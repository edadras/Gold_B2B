<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Requests;

use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\Side;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /otc-offers — 🔑 idempotent. Shape from §2.6. */
final class CreateOtcOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'counterparty_organization_id' => ['required', 'integer', 'min:1'],
            'instrument' => ['required', 'string', 'max:50'],
            'side' => ['required', Rule::in(array_map(static fn (Side $s): string => $s->value, Side::cases()))],
            'quantity_mg' => ['required', 'integer', 'min:1'],
            'price_rial' => ['required', 'integer', 'min:1'],
            'min_purity_x10' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'settlement_type' => ['sometimes', 'nullable', 'string', 'max:20'],
            'delivery_type' => ['sometimes', 'nullable', Rule::in(array_map(static fn (DeliveryType $d): string => $d->value, DeliveryType::cases()))],
            'expires_in_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
