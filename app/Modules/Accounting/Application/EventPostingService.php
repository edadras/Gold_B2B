<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Contracts\JournalPosterInterface;
use App\Modules\Accounting\Contracts\PostingContext;
use App\Modules\Accounting\Contracts\PostingResult;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Accounting\Domain\SourceType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The bridge between "something happened" and "a voucher exists".
 *
 * It exists to solve one problem that JournalPoster cannot solve alone.
 * JournalPoster is idempotent because of `uq_source`, but a trade also moves
 * the weighted-average cost basis, and *that* write is not idempotent — a
 * redelivered TradeExecuted would fold the same purchase into the average
 * twice, permanently corrupting every future COGS figure.
 *
 * So the cost-basis mutation and the voucher are wrapped in one transaction,
 * and the voucher's idempotency key arbitrates: if the voucher turns out to
 * exist already, the whole transaction is rolled back and the inventory is left
 * exactly as it was.
 */
final readonly class EventPostingService
{
    public function __construct(
        private JournalPosterInterface $poster,
        private PostingRules $rules,
        private CostBasisService $costBasis,
    ) {}

    /**
     * A purchase: inventory in at cost, voucher per §9.3.
     */
    public function postPurchase(
        int $organizationId,
        int $tradeId,
        int $fineMg,
        int $grossRial,
        int $feeRial,
        string $entryDate,
        ?int $counterpartyOrgId = null,
        string $description = '',
    ): PostingResult {
        return $this->atomically(
            $organizationId,
            SourceType::TRADE,
            $tradeId,
            function () use (
                $organizationId, $tradeId, $fineMg, $grossRial, $feeRial,
                $entryDate, $counterpartyOrgId, $description
            ): PostingResult {
                $this->costBasis->recordPurchase($organizationId, $fineMg, $grossRial);

                return $this->poster->post($this->rules->build(PostingRule::PURCHASE, new PostingContext(
                    organizationId: $organizationId,
                    sourceId: $tradeId,
                    entryDate: $entryDate,
                    sourceType: SourceType::TRADE,
                    description: $description,
                    fineMg: $fineMg,
                    grossRial: $grossRial,
                    feeRial: $feeRial,
                    counterpartyOrgId: $counterpartyOrgId,
                )));
            },
        );
    }

    /**
     * A sale: COGS comes from the cost basis, never from the caller, so the
     * two can never disagree.
     */
    public function postSale(
        int $organizationId,
        int $tradeId,
        int $fineMg,
        int $grossRial,
        int $feeRial,
        string $entryDate,
        ?int $counterpartyOrgId = null,
        string $description = '',
    ): PostingResult {
        return $this->atomically(
            $organizationId,
            SourceType::TRADE,
            $tradeId,
            function () use (
                $organizationId, $tradeId, $fineMg, $grossRial, $feeRial,
                $entryDate, $counterpartyOrgId, $description
            ): PostingResult {
                $sale = $this->costBasis->recordSale($organizationId, $fineMg, $grossRial, $feeRial);

                return $this->poster->post($this->rules->build(PostingRule::SALE, new PostingContext(
                    organizationId: $organizationId,
                    sourceId: $tradeId,
                    entryDate: $entryDate,
                    sourceType: SourceType::TRADE,
                    description: $description,
                    fineMg: $fineMg,
                    grossRial: $grossRial,
                    feeRial: $feeRial,
                    cogsRial: $sale->costOfGoodsSold,
                    counterpartyOrgId: $counterpartyOrgId,
                )));
            },
        );
    }

    /** Any rule that touches no inventory: fees, penalties, settlements. */
    public function postSimple(PostingRule $rule, PostingContext $context): PostingResult
    {
        return $this->poster->post($this->rules->build($rule, $context));
    }

    /**
     * A vault deposit adds weight the member already paid for elsewhere; when a
     * value is supplied it also enters the cost basis.
     */
    public function postGoldDeposit(
        int $organizationId,
        int $custodyOperationId,
        int $fineMg,
        int $valueRial,
        string $entryDate,
        string $description = '',
    ): PostingResult {
        return $this->atomically(
            $organizationId,
            SourceType::CUSTODY,
            $custodyOperationId,
            function () use ($organizationId, $custodyOperationId, $fineMg, $valueRial, $entryDate, $description): PostingResult {
                if ($valueRial > 0) {
                    $this->costBasis->recordPurchase($organizationId, $fineMg, $valueRial);
                }

                return $this->poster->post($this->rules->build(PostingRule::GOLD_DEPOSIT, new PostingContext(
                    organizationId: $organizationId,
                    sourceId: $custodyOperationId,
                    entryDate: $entryDate,
                    sourceType: SourceType::CUSTODY,
                    description: $description,
                    fineMg: $fineMg,
                    grossRial: $valueRial,
                )));
            },
        );
    }

    /**
     * A re-assay that found less fine gold: quantity drops, spent money does not.
     */
    public function postAssayAdjustment(
        int $organizationId,
        int $assayId,
        int $shortfallMg,
        int $writeDownRial,
        string $entryDate,
        string $description = '',
    ): PostingResult {
        return $this->atomically(
            $organizationId,
            SourceType::ASSAY,
            $assayId,
            function () use ($organizationId, $assayId, $shortfallMg, $writeDownRial, $entryDate, $description): PostingResult {
                if ($shortfallMg > 0) {
                    $this->costBasis->recordQuantityAdjustment($organizationId, -$shortfallMg);
                }

                return $this->poster->post($this->rules->build(PostingRule::ASSAY_ADJUSTMENT, new PostingContext(
                    organizationId: $organizationId,
                    sourceId: $assayId,
                    entryDate: $entryDate,
                    sourceType: SourceType::ASSAY,
                    description: $description,
                    fineMg: $shortfallMg,
                    amountRial: $writeDownRial,
                )));
            },
        );
    }

    /**
     * Run $work in a transaction, rolling it back entirely if the voucher it
     * produces turns out to have existed all along.
     *
     * @param  callable(): PostingResult  $work
     */
    private function atomically(
        int $organizationId,
        SourceType $sourceType,
        int $sourceId,
        callable $work,
    ): PostingResult {
        // Fast path: nothing to undo, so no transaction is needed.
        if ($this->poster->existsForSource($organizationId, $sourceType, $sourceId)) {
            return $this->existingResult($organizationId, $sourceType, $sourceId);
        }

        try {
            return DB::transaction(function () use ($work): PostingResult {
                $result = $work();

                if ($result->alreadyExisted) {
                    // Another worker won the race between our check and our
                    // insert. Undo whatever this attempt did to the inventory.
                    throw new AlreadyPostedSignal($result);
                }

                return $result;
            });
        } catch (AlreadyPostedSignal $signal) {
            return $signal->result;
        }
    }

    private function existingResult(int $organizationId, SourceType $sourceType, int $sourceId): PostingResult
    {
        $entry = DB::table('journal_entries')
            ->where('organization_id', $organizationId)
            ->where('source_type', $sourceType->value)
            ->where('source_id', $sourceId)
            ->first();

        if ($entry === null) {
            throw new RuntimeException('Voucher vanished between existence check and read');
        }

        return new PostingResult(
            journalEntryId: (int) $entry->id,
            voucherNo: (string) $entry->voucher_no,
            alreadyExisted: true,
            lineCount: (int) DB::table('journal_lines')->where('journal_entry_id', $entry->id)->count(),
        );
    }
}
