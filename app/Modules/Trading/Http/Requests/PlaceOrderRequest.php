<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Requests;

use App\Modules\Trading\Domain\OrderType;
use App\Modules\Trading\Domain\Side;
use App\Modules\Trading\Domain\TimeInForce;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /orders — 🔑 idempotent.
 *
 * Shape follows the sample in docs/05-api/02-endpoints.md §2.5.
 *
 * `quantity_mg` and `price_rial` are validated as INTEGERS, not `numeric`: a
 * client that sends 250000.5 must be told it is wrong rather than have a
 * half-milligram silently truncated (AGENT_BRIEF rule 1). Every business rule
 * beyond shape — tick size, lot multiple, min/max, price band, risk limits —
 * belongs to PlaceOrderService and is deliberately NOT duplicated here; two
 * copies of a trading rule is one copy too many.
 */
final class PlaceOrderRequest extends FormRequest
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
            'type' => ['required', Rule::in(array_map(static fn (OrderType $t): string => $t->value, OrderType::cases()))],
            'time_in_force' => ['required', Rule::in(array_map(static fn (TimeInForce $t): string => $t->value, TimeInForce::cases()))],
            'quantity_mg' => ['required', 'integer', 'min:1'],
            'price_rial' => ['required_if:type,LIMIT', 'prohibited_if:type,MARKET', 'integer', 'min:1'],
            'max_slippage_bps' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'expires_at' => ['required_if:time_in_force,GTD', 'nullable', 'date', 'after:now'],
            'representative_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'quantity_mg.integer' => 'حجم سفارش باید عدد صحیح بر حسب میلی‌گرم باشد.',
            'price_rial.integer' => 'قیمت باید عدد صحیح بر حسب ریال باشد.',
            'price_rial.required_if' => 'سفارش LIMIT بدون قیمت پذیرفته نمی‌شود.',
            'price_rial.prohibited_if' => 'سفارش MARKET قیمت نمی‌پذیرد.',
        ];
    }

    public function side(): Side
    {
        return Side::from((string) $this->validated('side'));
    }

    public function type(): OrderType
    {
        return OrderType::from((string) $this->validated('type'));
    }

    public function timeInForce(): TimeInForce
    {
        return TimeInForce::from((string) $this->validated('time_in_force'));
    }
}
