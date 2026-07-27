<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Application\EventPostingService;
use App\Modules\Accounting\Contracts\PostingContext;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Accounting\Domain\SourceType;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Books the cash leg of a completed settlement — «تسویه بدهی طرف‌حساب» in the
 * §9.3 mapping table: ۲۱۰۱ حساب‌های پرداختنی against ۱۱۰۳ موجودی ریالی.
 *
 * Bound by string event name; see PostTradeVoucher for why, and for the
 * treatment of payloads that do not carry what a voucher needs.
 */
final readonly class PostSettlementVoucher
{
    public function __construct(private EventPostingService $posting) {}

    public function handle(object $event): void
    {
        $settlementId = EventFacts::int($event, 'settlementId', 'id');
        $amount = EventFacts::int($event, 'rialAmount', 'amountRial', 'amount', 'totalRial');

        if ($settlementId === null || $amount === null || $amount <= 0) {
            $this->skip($event, 'settlement event lacks id or rial amount');

            return;
        }

        // The paying side is the one whose payable is discharged. Where the
        // event does not say, there is nothing to post against.
        $payerOrgId = EventFacts::int($event, 'payerOrgId', 'buyerOrgId', 'fromOrgId', 'debtorOrgId');
        $payeeOrgId = EventFacts::int($event, 'payeeOrgId', 'sellerOrgId', 'toOrgId', 'creditorOrgId');

        if ($payerOrgId === null) {
            $this->skip($event, 'settlement event names no payer');

            return;
        }

        $entryDate = EventFacts::date($event, 'completedAt', 'settledAt', 'occurredAt', 'createdAt')
            ?? (new DateTimeImmutable('today'))->format('Y-m-d');

        try {
            $this->posting->postSimple(
                PostingRule::COUNTERPARTY_SETTLEMENT,
                new PostingContext(
                    organizationId: $payerOrgId,
                    sourceId: $settlementId,
                    entryDate: $entryDate,
                    sourceType: SourceType::SETTLEMENT,
                    description: EventFacts::string($event, 'description') ?? "تسویه STL-{$settlementId}",
                    amountRial: $amount,
                    counterpartyOrgId: $payeeOrgId,
                ),
            );
        } catch (Throwable $e) {
            Log::channel(config('logging.default'))->error('accounting: settlement voucher failed', [
                'settlement_id' => $settlementId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function skip(object $event, string $reason): void
    {
        Log::channel(config('logging.default'))->debug('accounting: settlement event ignored', [
            'event' => $event::class,
            'reason' => $reason,
        ]);
    }
}
