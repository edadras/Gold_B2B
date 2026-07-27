<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /disputes/{id}/reply — §2.12 «پاسخ».
 *
 * DESIGN DECISION, DOCUMENTED HERE BECAUSE THE SPEC LEFT IT OPEN.
 *
 * §13.6 مرحله ۱ gives the respondent three options: accept in full, accept in
 * part, or reject. The last two both lead to the negotiation room, so this
 * endpoint is exactly `DisputeService::disputeClaim()` — it always means "I do
 * not accept this as it stands". Accepting in full has its own endpoint,
 * `POST /disputes/{id}/accept`.
 *
 * There is no `action` field, and that is the point rather than an omission.
 * Accepting a claim is a money-moving admission: §2.12 marks it 🔑 ✍️, so it
 * carries an idempotency key AND a transaction signature. If `/reply` could
 * also accept, a caller could reach that outcome through a route with neither
 * gate, and the ✍️ requirement would become advisory. Splitting the two makes
 * the middleware stack match the consequence.
 *
 * A message is mandatory: a rejection with no reason gives the mediator nothing
 * to weigh and the claimant nothing to answer.
 */
final class ReplyToDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:5', 'max:5000'],
        ];
    }

    public function message(): string
    {
        return (string) $this->validated('message');
    }
}
