<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Requests;

use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The `?from=&to=` pair the journal, the export and the trial balance take.
 *
 * Accounting states its own range rule rather than importing Reporting's
 * DateRange: the module graph forbids the dependency (Accounting may reach
 * Shared, Identity, Ledger, Trading and Settlement — not Reporting), and the
 * rule is different anyway. A trial balance is a period report and a member's
 * accountant legitimately asks for a whole financial year, so the cap is two
 * years rather than the 366 days a flow report is limited to.
 */
final class AccountingRangeRequest extends FormRequest
{
    /** An accounting period plus its comparative; wider than that is a mistake. */
    public const MAX_DAYS = 732;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:from',
                'before_or_equal:'.$this->latestPermittedTo(),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.before_or_equal' => 'حداکثر بازه گزارش حسابداری دو سال است.',
        ];
    }

    public function from(): string
    {
        return (string) $this->validated('from');
    }

    public function to(): string
    {
        return (string) $this->validated('to');
    }

    /**
     * The last `to` this `from` may legally reach.
     *
     * Computed with a date interval rather than by dividing a timestamp: this
     * module is a FINANCIAL_PATH where floating-point arithmetic is banned
     * outright, and day counting by subtraction invites exactly that.
     */
    private function latestPermittedTo(): string
    {
        $from = $this->input('from');

        if (! is_string($from) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            // Shape is enforced by the `from` rule; anything else fails there
            // first, so an unbounded ceiling here is never reached.
            return '9999-12-31';
        }

        $ceiling = (new DateTimeImmutable($from))->modify('+'.(self::MAX_DAYS - 1).' days');

        return $ceiling->format('Y-m-d');
    }
}
