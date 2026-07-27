<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Requests;

use App\Modules\Pricing\Domain\CandleInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /market/candles/{code}?interval=5m&limit=200 */
final class CandleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'interval' => ['sometimes', Rule::in(array_map(static fn (CandleInterval $i): string => $i->value, CandleInterval::cases()))],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }

    public function interval(): CandleInterval
    {
        return CandleInterval::from((string) $this->query('interval', CandleInterval::M5->value));
    }

    public function limit(): int
    {
        return (int) $this->query('limit', '100');
    }
}
