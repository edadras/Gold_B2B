<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Requests;

use App\Modules\Trading\Application\OrderBookReader;
use Illuminate\Foundation\Http\FormRequest;

/** GET /market/depth/{code}?levels=10 — capped at OrderBookReader::MAX_LEVELS. */
final class MarketDepthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'levels' => ['sometimes', 'integer', 'min:1', 'max:'.OrderBookReader::MAX_LEVELS],
        ];
    }

    public function levels(): int
    {
        return (int) $this->query('levels', (string) OrderBookReader::MAX_LEVELS);
    }
}
