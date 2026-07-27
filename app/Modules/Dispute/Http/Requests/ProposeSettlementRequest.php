<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /disputes/{id}/propose-settlement — 🔑 §2.12 «پیشنهاد تسویه».
 *
 * The amounts are UNSIGNED and are read FROM THE SENDER'S PERSPECTIVE: they are
 * what the sender offers to hand over. NegotiationService converts that into
 * the case's own frame when the offer is accepted, so neither side has to
 * reason about signs — and a client cannot express "you pay me" by sending a
 * negative number, which is why the minimum is zero rather than open.
 *
 * At least one of the two must be non-zero; an offer of nothing is not an
 * offer. That rule lives in NegotiationService, which owns it; the `min:0`
 * rules here only stop a malformed request from getting that far.
 */
final class ProposeSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'offered_gold_mg' => ['nullable', 'integer', 'min:0'],
            'offered_rial' => ['nullable', 'integer', 'min:0'],
            'body' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function offeredGoldMg(): int
    {
        return (int) ($this->validated('offered_gold_mg') ?? 0);
    }

    public function offeredRial(): int
    {
        return (int) ($this->validated('offered_rial') ?? 0);
    }

    public function body(): string
    {
        $body = $this->validated('body');

        return is_string($body) ? $body : '';
    }
}
