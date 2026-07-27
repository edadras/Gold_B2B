<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Resources;

use App\Modules\Counterparty\Infrastructure\BalanceConfirmation;
use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;

/**
 * `POST /counterparties/{orgId}/confirm-balance` (docs §2.11).
 *
 * Both stored figure pairs are in the *requester's* sign convention, as
 * BalanceConfirmationService restates them (§10.4) — so `responder_gold_mg`
 * minus `requester_gold_mg` is the discrepancy directly, with no mental
 * negation for the reader to get wrong.
 *
 * @mixin BalanceConfirmation
 */
final class BalanceConfirmationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BalanceConfirmation $confirmation */
        $confirmation = $this->resource;

        return [
            'id' => (int) $confirmation->id,
            'organization_id' => (int) $confirmation->organization_id,
            'counterparty_org_id' => (int) $confirmation->counterparty_org_id,
            'status' => $confirmation->status->value,
            'period_start' => Display::iso($confirmation->period_start),
            'as_of' => Display::iso($confirmation->as_of),
            'requester_gold_mg' => (int) $confirmation->requester_gold_mg,
            'requester_rial' => (int) $confirmation->requester_rial,
            'responder_gold_mg' => $confirmation->responder_gold_mg === null
                ? null
                : (int) $confirmation->responder_gold_mg,
            'responder_rial' => $confirmation->responder_rial === null
                ? null
                : (int) $confirmation->responder_rial,
            'responded_at' => Display::iso($confirmation->responded_at),
            'created_at' => Display::iso($confirmation->created_at),
        ] + $this->display($request, [
            'requester_gold_display' => Display::grams((int) $confirmation->requester_gold_mg),
            'requester_rial_display' => Display::rial((int) $confirmation->requester_rial),
            'as_of_jalali' => Display::jalali($confirmation->as_of),
            'created_at_jalali' => Display::jalali($confirmation->created_at),
        ]);
    }
}
