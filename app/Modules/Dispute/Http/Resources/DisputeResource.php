<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Resources;

use App\Modules\Dispute\Domain\DisputeDecision;
use App\Modules\Dispute\Domain\DisputePriority;
use App\Modules\Dispute\Infrastructure\Models\DisputeModel;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * A dispute case — docs/05-api/02-endpoints.md §2.12.
 *
 * NOTE ON THE ENUM COLUMNS. `DisputeModel` casts its ids, its booleans and its
 * timestamps, but NOT `status`, `dispute_type`, `decision` or `priority` —
 * those four are plain strings on the model. This resource therefore goes
 * through `statusEnum()` / `typeEnum()` and `tryFrom()` rather than assuming a
 * cast that is not there; reading `$dispute->status->value` would be a fatal
 * error on a string, and comparing `$dispute->status === DisputeStatus::OPENED`
 * would silently be false for every row.
 *
 * `claim_gold_mg` and `claim_rial` are the DISPUTED amounts, never the trade
 * total: §13.4 locks 394 million rial of a 39-billion-rial trade, and a client
 * that showed the trade value here would be describing a different case.
 * `awarded_*` are signed — positive means the claimant receives.
 *
 * @mixin DisputeModel
 */
final class DisputeResource extends ApiResource
{
    /**
     * @param  DisputeModel  $resource
     * @param  int|null  $viewerOrganizationId  which side the caller is on, when known
     */
    public function __construct(mixed $resource, private readonly ?int $viewerOrganizationId = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DisputeModel $dispute */
        $dispute = $this->resource;

        $status = $dispute->statusEnum();
        $type = $dispute->typeEnum();
        $priority = DisputePriority::tryFrom((string) $dispute->priority);
        $decision = $dispute->decision === null
            ? null
            : DisputeDecision::tryFrom((string) $dispute->decision);

        return [
            'id' => (int) $dispute->id,
            'case_number' => (string) $dispute->case_number,
            'dispute_type' => $type->value,
            'status' => $status->value,
            'priority' => (string) $dispute->priority,

            'trade_id' => $dispute->trade_id === null ? null : (int) $dispute->trade_id,
            'settlement_id' => $dispute->settlement_id === null ? null : (int) $dispute->settlement_id,
            'gold_lot_id' => $dispute->gold_lot_id === null ? null : (int) $dispute->gold_lot_id,

            'claimant_org_id' => (int) $dispute->claimant_org_id,
            'respondent_org_id' => (int) $dispute->respondent_org_id,
            // Saves every client re-deriving "is this against me or by me?".
            'my_role' => $this->roleOfViewer($dispute),

            'claim_description' => (string) $dispute->claim_description,
            'claim_gold_mg' => (int) $dispute->claim_gold_mg,
            'claim_rial' => (int) $dispute->claim_rial,
            'claim_basis' => $dispute->claim_basis,

            'funds_held' => $dispute->hold_gold_entry_id !== null || $dispute->hold_rial_entry_id !== null,
            'hold_released' => (bool) $dispute->hold_released,

            'decision' => $dispute->decision,
            'decision_rationale' => $dispute->decision_rationale,
            'is_frivolous' => (bool) $dispute->is_frivolous,
            'awarded_gold_mg' => (int) $dispute->awarded_gold_mg,
            'awarded_rial' => (int) $dispute->awarded_rial,

            'is_open' => $status->isOpen(),
            'reply_deadline_at' => Display::iso($dispute->reply_deadline_at),
            'negotiation_deadline_at' => Display::iso($dispute->negotiation_deadline_at),
            'opened_at' => Display::iso($dispute->opened_at),
            'resolved_at' => Display::iso($dispute->resolved_at),
            'executed_at' => Display::iso($dispute->executed_at),
        ] + $this->display($request, [
            'status_display' => $status->label(),
            'dispute_type_display' => $type->label(),
            'priority_display' => $priority?->label(),
            'decision_display' => $decision?->label(),
            'claim_gold_display' => Display::grams((int) $dispute->claim_gold_mg),
            'claim_rial_display' => Display::rial((int) $dispute->claim_rial),
            'awarded_gold_display' => Display::grams((int) $dispute->awarded_gold_mg),
            'awarded_rial_display' => Display::rial((int) $dispute->awarded_rial),
            'opened_at_jalali' => Display::jalali($dispute->opened_at),
            'reply_deadline_at_jalali' => Display::jalali($dispute->reply_deadline_at),
            'resolved_at_jalali' => Display::jalali($dispute->resolved_at),
        ]);
    }

    private function roleOfViewer(DisputeModel $dispute): ?string
    {
        if ($this->viewerOrganizationId === null) {
            return null;
        }

        return match ($this->viewerOrganizationId) {
            (int) $dispute->claimant_org_id => 'CLAIMANT',
            (int) $dispute->respondent_org_id => 'RESPONDENT',
            default => null,
        };
    }
}
