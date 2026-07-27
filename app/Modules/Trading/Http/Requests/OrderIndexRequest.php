<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Requests;

use App\Modules\Trading\Domain\OrderStatus;
use App\Modules\Trading\Domain\Side;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /orders — filters per docs/05-api/01-conventions.md §1.9. */
final class OrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', Rule::in(array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases()))],
            'filter.side' => ['sometimes', Rule::in(array_map(static fn (Side $s): string => $s->value, Side::cases()))],
            'filter.instrument' => ['sometimes', 'string', 'max:50'],
            'filter.open' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<OrderStatus>|null */
    public function statuses(): ?array
    {
        if ($this->boolean('filter.open')) {
            return [OrderStatus::PENDING, OrderStatus::OPEN, OrderStatus::PARTIALLY_FILLED];
        }

        $status = $this->input('filter.status');

        if (! is_string($status)) {
            return null;
        }

        $enum = OrderStatus::tryFrom($status);

        return $enum === null ? null : [$enum];
    }

    public function side(): ?Side
    {
        $side = $this->input('filter.side');

        return is_string($side) ? Side::tryFrom($side) : null;
    }

    public function instrumentCode(): ?string
    {
        $code = $this->input('filter.instrument');

        return is_string($code) && $code !== '' ? $code : null;
    }
}
