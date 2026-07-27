<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Requests;

use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\RfqVisibility;
use App\Modules\Trading\Domain\Side;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /rfqs — 🔑 idempotent. Shape from §2.7. */
final class CreateRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'instrument' => ['required', 'string', 'max:50'],
            'side' => ['required', Rule::in(array_map(static fn (Side $s): string => $s->value, Side::cases()))],
            'quantity_mg' => ['required', 'integer', 'min:1'],
            'min_purity_x10' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'settlement_type' => ['sometimes', 'nullable', 'string', 'max:20'],
            'delivery_type' => ['sometimes', 'nullable', Rule::in(array_map(static fn (DeliveryType $d): string => $d->value, DeliveryType::cases()))],
            'visibility' => ['sometimes', Rule::in(array_map(static fn (RfqVisibility $v): string => $v->value, RfqVisibility::cases()))],
            'recipient_organization_ids' => ['required_if:visibility,SELECTED', 'array'],
            'recipient_organization_ids.*' => ['integer', 'min:1'],
            'allow_partial' => ['sometimes', 'boolean'],
            'expires_in_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }

    public function visibility(): RfqVisibility
    {
        return RfqVisibility::from((string) $this->input('visibility', RfqVisibility::ALL_QUALIFIED->value));
    }
}
