<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Contracts\PostingContext;
use App\Modules\Accounting\Contracts\VoucherDraft;
use App\Modules\Accounting\Contracts\VoucherLine;
use App\Modules\Accounting\Domain\AccountCode;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Shared\Support\IntMath;
use InvalidArgumentException;

/**
 * The event → voucher mapping table of docs/03-domain/09-accounting.md §9.3.
 *
 * This class is the only place in the system that decides which accounts an
 * economic event touches. Callers name the event; they never name an account.
 *
 * Every rule that moves gold produces TWO sets of lines (see Domain\LineSet):
 * the money set, and the weight set posted against a MEMO account. §9.3 records
 * that decision explicitly, with the reasoning: line ۳ of the purchase voucher
 * (۱۱۰۳ موجودی ریالی) has no gold quantity, so it cannot balance the 248.750 g
 * debited to ۱۱۱۰. Two independent sets keep each column honest.
 */
final class PostingRules
{
    public function build(PostingRule $rule, PostingContext $context): VoucherDraft
    {
        $lines = match ($rule) {
            PostingRule::PURCHASE => $this->purchase($context),
            PostingRule::SALE => $this->sale($context),
            PostingRule::GOLD_DEPOSIT => $this->goldDeposit($context),
            PostingRule::GOLD_WITHDRAWAL => $this->goldWithdrawal($context),
            PostingRule::SEND_TO_REFINING => $this->sendToRefining($context),
            PostingRule::MELT_LOSS => $this->meltLoss($context),
            PostingRule::REFINING_COST => $this->refiningCost($context),
            PostingRule::ASSAY_ADJUSTMENT => $this->assayAdjustment($context),
            PostingRule::FEE => $this->fee($context),
            PostingRule::PENALTY => $this->penalty($context),
            PostingRule::COUNTERPARTY_SETTLEMENT => $this->counterpartySettlement($context),
        };

        return new VoucherDraft(
            organizationId: $context->organizationId,
            sourceType: $context->sourceType,
            sourceId: $context->sourceId,
            entryDate: $context->entryDate,
            description: $context->description !== '' ? $context->description : $rule->label(),
            lines: $lines,
            createdByUserId: $context->createdByUserId,
        );
    }

    /**
     * خرید طلا — §9.3, worked with the document's own numbers:
     *
     *   ۱۱۱۰ موجودی طلا در خزانه   19,521,900,000 (بدهکار)
     *   ۵۲۰۱ کارمزد سامانه             29,282,850 (بدهکار)
     *   ۱۱۰۳ موجودی ریالی                         19,551,182,850 (بستانکار)
     *   ── gold set ──
     *   ۱۱۱۰ موجودی طلا  248.750 g (بدهکار) / ۹۱۰۱ تعهدات (بستانکار)
     *
     * @return array<int, VoucherLine>
     */
    private function purchase(PostingContext $c): array
    {
        $this->requirePositive($c->grossRial, 'grossRial', PostingRule::PURCHASE);
        $this->requirePositive($c->fineMg, 'fineMg', PostingRule::PURCHASE);

        $totalPaid = IntMath::add($c->grossRial, $c->feeRial);

        return [
            VoucherLine::debitRial(AccountCode::GOLD_IN_VAULT, $c->grossRial, 'بهای خرید طلا'),
            VoucherLine::debitRial(AccountCode::PLATFORM_FEE, $c->feeRial, 'کارمزد خرید'),
            VoucherLine::creditRial(
                AccountCode::PLATFORM_RIAL_BALANCE,
                $totalPaid,
                'پرداخت بابت خرید',
                $c->counterpartyOrgId,
            ),

            VoucherLine::debitGold(AccountCode::GOLD_IN_VAULT, $c->fineMg, 'ورود طلای خریداری‌شده'),
            VoucherLine::creditGold(AccountCode::OPEN_TRADE_COMMITMENTS, $c->fineMg, 'طرف حساب انتظامی'),
        ];
    }

    /**
     * فروش طلا — §9.3. Two blocks in the rial set:
     * the revenue block (cash + fee vs sales) and the cost block (COGS vs the
     * book value of the gold that left). The gold set retires the weight.
     *
     * @return array<int, VoucherLine>
     */
    private function sale(PostingContext $c): array
    {
        $this->requirePositive($c->grossRial, 'grossRial', PostingRule::SALE);
        $this->requirePositive($c->fineMg, 'fineMg', PostingRule::SALE);

        $netProceeds = IntMath::sub($c->grossRial, $c->feeRial);

        if ($netProceeds < 0) {
            throw new InvalidArgumentException('Sale fee exceeds the sale proceeds');
        }

        return [
            VoucherLine::debitRial(AccountCode::PLATFORM_RIAL_BALANCE, $netProceeds, 'دریافت وجه فروش'),
            VoucherLine::debitRial(AccountCode::PLATFORM_FEE, $c->feeRial, 'کارمزد فروش'),
            VoucherLine::creditRial(
                AccountCode::GOLD_SALES,
                $c->grossRial,
                'فروش طلا',
                $c->counterpartyOrgId,
            ),

            VoucherLine::debitRial(AccountCode::COST_OF_GOODS_SOLD, $c->cogsRial, 'بهای تمام‌شده فروش'),
            VoucherLine::creditRial(AccountCode::GOLD_IN_VAULT, $c->cogsRial, 'کاهش ارزش دفتری موجودی'),

            VoucherLine::debitGold(AccountCode::OPEN_TRADE_COMMITMENTS, $c->fineMg, 'طرف حساب انتظامی'),
            VoucherLine::creditGold(AccountCode::GOLD_IN_VAULT, $c->fineMg, 'خروج طلای فروخته‌شده'),
        ];
    }

    /**
     * سپرده طلا در خزانه — «۱۱۱۰ موجودی طلا | ۹۱۰۱ / ۳۱۰۱».
     *
     * The rial set books the deposit against capital at its declared value;
     * when no value is given (a pure custody movement) only the gold set posts.
     *
     * @return array<int, VoucherLine>
     */
    private function goldDeposit(PostingContext $c): array
    {
        $this->requirePositive($c->fineMg, 'fineMg', PostingRule::GOLD_DEPOSIT);

        $lines = [
            VoucherLine::debitGold(AccountCode::GOLD_IN_VAULT, $c->fineMg, 'سپرده طلا در خزانه'),
            VoucherLine::creditGold(AccountCode::OPEN_TRADE_COMMITMENTS, $c->fineMg, 'طرف حساب انتظامی'),
        ];

        if ($c->grossRial > 0) {
            array_unshift(
                $lines,
                VoucherLine::debitRial(AccountCode::GOLD_IN_VAULT, $c->grossRial, 'ارزش‌گذاری سپرده'),
                VoucherLine::creditRial(AccountCode::CAPITAL, $c->grossRial, 'آورده طلا'),
            );
        }

        return $lines;
    }

    /**
     * برداشت طلا — «۱۱۱۱ نزد خود | ۱۱۱۰ در خزانه».
     *
     * Both accounts are gold-bearing, so the weight set balances between them
     * with no MEMO account involved.
     *
     * @return array<int, VoucherLine>
     */
    private function goldWithdrawal(PostingContext $c): array
    {
        $this->requirePositive($c->fineMg, 'fineMg', PostingRule::GOLD_WITHDRAWAL);

        $lines = [
            VoucherLine::debitGold(AccountCode::GOLD_ON_HAND, $c->fineMg, 'برداشت طلا از خزانه'),
            VoucherLine::creditGold(AccountCode::GOLD_IN_VAULT, $c->fineMg, 'خروج از خزانه'),
        ];

        if ($c->grossRial > 0) {
            array_unshift(
                $lines,
                VoucherLine::debitRial(AccountCode::GOLD_ON_HAND, $c->grossRial, 'انتقال ارزش دفتری'),
                VoucherLine::creditRial(AccountCode::GOLD_IN_VAULT, $c->grossRial, 'انتقال ارزش دفتری'),
            );
        }

        return $lines;
    }

    /**
     * ارسال به ری‌گیری — «۱۱۱۲ در ری‌گیری | ۱۱۱۰ در خزانه».
     *
     * @return array<int, VoucherLine>
     */
    private function sendToRefining(PostingContext $c): array
    {
        $this->requirePositive($c->fineMg, 'fineMg', PostingRule::SEND_TO_REFINING);

        $lines = [
            VoucherLine::debitGold(AccountCode::GOLD_IN_REFINING, $c->fineMg, 'ارسال به ری‌گیری'),
            VoucherLine::creditGold(AccountCode::GOLD_IN_VAULT, $c->fineMg, 'خروج از خزانه'),
        ];

        if ($c->grossRial > 0) {
            array_unshift(
                $lines,
                VoucherLine::debitRial(AccountCode::GOLD_IN_REFINING, $c->grossRial, 'انتقال ارزش دفتری'),
                VoucherLine::creditRial(AccountCode::GOLD_IN_VAULT, $c->grossRial, 'انتقال ارزش دفتری'),
            );
        }

        return $lines;
    }

    /**
     * افت ذوب — «۵۴۰۱ افت ذوب | ۱۱۱۲ در ری‌گیری».
     *
     * The weight genuinely disappears, so the gold set retires it against the
     * MEMO account rather than moving it somewhere else.
     *
     * @return array<int, VoucherLine>
     */
    private function meltLoss(PostingContext $c): array
    {
        $this->requirePositive($c->fineMg, 'fineMg', PostingRule::MELT_LOSS);

        $lines = [
            VoucherLine::debitGold(AccountCode::OPEN_TRADE_COMMITMENTS, $c->fineMg, 'طرف حساب انتظامی'),
            VoucherLine::creditGold(AccountCode::GOLD_IN_REFINING, $c->fineMg, 'افت ذوب'),
        ];

        if ($c->amountRial > 0) {
            array_unshift(
                $lines,
                VoucherLine::debitRial(AccountCode::MELT_LOSS, $c->amountRial, 'هزینه افت ذوب'),
                VoucherLine::creditRial(AccountCode::GOLD_IN_REFINING, $c->amountRial, 'کاهش ارزش دفتری'),
            );
        }

        return $lines;
    }

    /**
     * هزینه ری‌گیری — «۵۳۰۱ هزینه ری‌گیری | ۲۱۰۱ پرداختنی». Rial only.
     *
     * @return array<int, VoucherLine>
     */
    private function refiningCost(PostingContext $c): array
    {
        $this->requirePositive($c->amountRial, 'amountRial', PostingRule::REFINING_COST);

        return [
            VoucherLine::debitRial(AccountCode::REFINING_COST, $c->amountRial, 'هزینه ری‌گیری'),
            VoucherLine::creditRial(
                AccountCode::PAYABLE_RIAL,
                $c->amountRial,
                'بدهی به آزمایشگاه/ری‌گیر',
                $c->counterpartyOrgId,
            ),
        ];
    }

    /**
     * تعدیل ری‌گیری مجدد (کاهش) — «۵۹۰۱ سایر هزینه | ۱۱۱۰ موجودی طلا».
     *
     * A re-assay that finds less fine gold than declared removes both the value
     * and the weight; the weight side retires against the MEMO account.
     *
     * @return array<int, VoucherLine>
     */
    private function assayAdjustment(PostingContext $c): array
    {
        if ($c->fineMg === 0 && $c->amountRial === 0) {
            throw new InvalidArgumentException('An assay adjustment must adjust something');
        }

        $lines = [];

        if ($c->amountRial > 0) {
            $lines[] = VoucherLine::debitRial(AccountCode::OTHER_EXPENSE, $c->amountRial, 'تعدیل ری‌گیری مجدد');
            $lines[] = VoucherLine::creditRial(AccountCode::GOLD_IN_VAULT, $c->amountRial, 'کاهش ارزش دفتری');
        }

        if ($c->fineMg > 0) {
            $lines[] = VoucherLine::debitGold(AccountCode::OPEN_TRADE_COMMITMENTS, $c->fineMg, 'طرف حساب انتظامی');
            $lines[] = VoucherLine::creditGold(AccountCode::GOLD_IN_VAULT, $c->fineMg, 'کاهش وزن پس از ری‌گیری');
        }

        return $lines;
    }

    /**
     * کارمزد سامانه — «۵۲۰۱ کارمزد | ۱۱۰۳ موجودی ریالی».
     *
     * The platform deducts its fee from the member's rial balance at the moment
     * of the trade, so the credit side is the balance, not ۲۱۲۰ کارمزد پرداختنی.
     * ۲۱۲۰ exists for fees that are billed and settled later; nothing in the
     * current flows accrues a fee, so no rule posts to it yet.
     *
     * @return array<int, VoucherLine>
     */
    private function fee(PostingContext $c): array
    {
        $amount = $c->feeRial > 0 ? $c->feeRial : $c->amountRial;
        $this->requirePositive($amount, 'feeRial', PostingRule::FEE);

        return [
            VoucherLine::debitRial(AccountCode::PLATFORM_FEE, $amount, 'کارمزد سامانه'),
            VoucherLine::creditRial(
                AccountCode::PLATFORM_RIAL_BALANCE,
                $amount,
                'کسر کارمزد از موجودی',
                $c->counterpartyOrgId,
            ),
        ];
    }

    /**
     * جریمه تأخیر — «۵۵۰۱ جریمه | ۱۱۰۳ موجودی ریالی».
     *
     * @return array<int, VoucherLine>
     */
    private function penalty(PostingContext $c): array
    {
        $amount = $c->amountRial > 0 ? $c->amountRial : $c->grossRial;
        $this->requirePositive($amount, 'amountRial', PostingRule::PENALTY);

        return [
            VoucherLine::debitRial(AccountCode::LATE_PENALTY, $amount, 'جریمه تأخیر'),
            VoucherLine::creditRial(
                AccountCode::PLATFORM_RIAL_BALANCE,
                $amount,
                'کسر جریمه از موجودی',
                $c->counterpartyOrgId,
            ),
        ];
    }

    /**
     * تسویه بدهی طرف‌حساب — «۲۱۰۱ پرداختنی | ۱۱۰۳ موجودی ریالی».
     *
     * @return array<int, VoucherLine>
     */
    private function counterpartySettlement(PostingContext $c): array
    {
        $amount = $c->amountRial > 0 ? $c->amountRial : $c->grossRial;
        $this->requirePositive($amount, 'amountRial', PostingRule::COUNTERPARTY_SETTLEMENT);

        return [
            VoucherLine::debitRial(
                AccountCode::PAYABLE_RIAL,
                $amount,
                'تسویه بدهی طرف‌حساب',
                $c->counterpartyOrgId,
            ),
            VoucherLine::creditRial(
                AccountCode::PLATFORM_RIAL_BALANCE,
                $amount,
                'پرداخت از موجودی ریالی',
                $c->counterpartyOrgId,
            ),
        ];
    }

    private function requirePositive(int $value, string $field, PostingRule $rule): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(
                sprintf('%s requires a positive %s', $rule->value, $field)
            );
        }
    }
}
