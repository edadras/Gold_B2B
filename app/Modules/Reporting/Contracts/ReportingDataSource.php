<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

use App\Modules\Reporting\Domain\DateRange;

/**
 * Everything Reporting reads, in one place.
 *
 * Reporting is allowed to depend on Shared and Identity only — it deliberately
 * sits at the bottom of nothing and the top of nothing, so that adding a report
 * can never drag a dependency through the module graph. Every figure it prints
 * therefore arrives through this interface, and the modules that own those
 * figures (Ledger, Trading, Accounting, Custody) bind an implementation from
 * their side.
 *
 * The default binding returns zeros and empty lists. That is not a placeholder
 * to be forgotten: a report built on it renders correctly with nothing in it,
 * and — crucially — its reconciliation still passes, because zero movements
 * really do reconcile to a zero balance.
 */
interface ReportingDataSource
{
    /** Fine-gold movements over the range, in milligrams (§15.7 shape). */
    public function goldFlow(int $organizationId, DateRange $range): FlowFacts;

    /** Rial movements over the range. */
    public function rialFlow(int $organizationId, DateRange $range): FlowFacts;

    /**
     * Closing fine-gold balance read from the LEDGER, not from the movements.
     *
     * This is the independent half of the §15.7 reconciliation, so an
     * implementation must not compute it by re-adding the same flow rows — that
     * would make the check compare a number with itself and always pass.
     *
     * Null means the read could not be made, which is reported as an
     * unreconciled report rather than silently accepted.
     */
    public function closingGoldBalance(int $organizationId, string $asOfDate): ?int;

    /** Closing rial balance read from the ledger. */
    public function closingRialBalance(int $organizationId, string $asOfDate): ?int;

    /**
     * Executed trades in the range.
     *
     * @return array<int, TradeRow>
     */
    public function trades(int $organizationId, DateRange $range): array;

    /** Realised and unrealised profit for the range (§9.4, §9.5). */
    public function pnl(int $organizationId, DateRange $range): PnlFacts;

    /**
     * Gold lots currently held.
     *
     * @return array<int, InventoryRow>
     */
    public function inventory(int $organizationId): array;

    /**
     * Fees charged in the range.
     *
     * @return array<int, FeeRow>
     */
    public function fees(int $organizationId, DateRange $range): array;

    /** Organisations with any activity on a date — the daily build's work list. */
    public function activeOrganizations(string $date): array;

    /** Display name for a report header, or null when unknown. */
    public function organizationName(int $organizationId): ?string;
}
