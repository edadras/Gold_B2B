<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * The surrounding facts an analyst needs before judging a flag: who the member
 * is, what else has been raised against them, and the recent activity that
 * triggered the rule.
 */
final readonly class AmlInvestigationContext
{
    /**
     * @param  list<AmlFlagRow>  $otherFlags
     * @param  list<array{id: int, trade_code: ?string, counterparty_id: int, quantity_fine_mg: int, gross_amount_rial: int, executed_at: ?string}>  $recentTrades
     * @param  array<string, int>  $balances
     */
    public function __construct(
        public AmlFlagRow $flag,
        public ?string $organizationStatus,
        public ?string $organizationRiskLevel,
        public ?string $complianceState,
        public array $otherFlags,
        public array $recentTrades,
        public array $balances,
        public int $openDisputeCount,
        public int $overdueSettlementCount,
    ) {}
}
