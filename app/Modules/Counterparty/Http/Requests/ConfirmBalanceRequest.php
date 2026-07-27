<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * POST /counterparties/{orgId}/confirm-balance — docs §2.11.
 *
 * `as_of` is the date the question is about, not the date it is asked
 * (docs/03-domain/10-counterparty.md §10.4): a request raised on the 5th about
 * the 31st is normal, and the stated figures are computed from the movement log
 * as of that date. A future `as_of` would ask the counterparty to confirm a
 * balance that has not happened yet, so it is refused.
 */
final class ConfirmBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
            'period_start' => ['sometimes', 'nullable', 'date', 'before_or_equal:as_of'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function asOf(): Carbon
    {
        $value = $this->validated('as_of');

        return $value === null ? Carbon::now() : Carbon::parse((string) $value);
    }

    public function periodStart(): ?Carbon
    {
        $value = $this->validated('period_start');

        return $value === null ? null : Carbon::parse((string) $value);
    }

    public function note(): ?string
    {
        $value = $this->validated('note');

        return $value === null ? null : (string) $value;
    }
}
