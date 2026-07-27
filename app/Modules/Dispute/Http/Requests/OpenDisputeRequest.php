<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Requests;

use App\Modules\Dispute\Domain\DisputeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /disputes — 🔑 §2.12 «ثبت اختلاف».
 *
 * The claim figures are only partly the claimant's to state. For a purity or
 * weight dispute the disputed amount is DERIVED by the domain from the trade
 * and the alleged real figure, so `claim_rial` is ignored there — a
 * claimant-supplied amount would be a second opinion the platform would have to
 * reconcile, and it would let anyone inflate what gets frozen on the other
 * side. For the categories with nothing derivable (a payment that never
 * arrived) `claim_rial` is how the amount is stated.
 *
 * `respondent_org_id` is accepted only for a case with no trade. When a trade
 * is named the respondent is whoever was on the other side of it, and the
 * claimant does not get to nominate someone else.
 */
final class OpenDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dispute_type' => ['required', Rule::in(array_map(
                static fn (DisputeType $t): string => $t->value,
                DisputeType::cases(),
            ))],
            'claim_description' => ['required', 'string', 'min:10', 'max:5000'],

            'trade_id' => ['nullable', 'integer', 'min:1'],
            'settlement_id' => ['nullable', 'integer', 'min:1'],
            'gold_lot_id' => ['nullable', 'integer', 'min:1'],

            // Required only when there is no trade to derive it from.
            'respondent_org_id' => ['nullable', 'integer', 'min:1', 'required_without:trade_id'],

            // Purity is in ten-thousandths: 995 per mille is 9950.
            'actual_purity_x10k' => [
                'nullable', 'integer', 'min:1', 'max:10000',
                'required_if:dispute_type,'.DisputeType::PURITY_MISMATCH->value,
            ],
            'actual_fine_mg' => [
                'nullable', 'integer', 'min:0',
                'required_if:dispute_type,'.DisputeType::WEIGHT_MISMATCH->value,
            ],

            'claim_rial' => ['nullable', 'integer', 'min:0'],
            'price_per_fine_gram' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function disputeType(): DisputeType
    {
        return DisputeType::from((string) $this->validated('dispute_type'));
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null ? null : (int) $value;
    }

    public function claimRial(): int
    {
        return (int) ($this->validated('claim_rial') ?? 0);
    }
}
