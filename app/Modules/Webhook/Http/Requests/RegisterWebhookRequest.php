<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Http\Requests;

use App\Modules\Webhook\Domain\WebhookEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /webhooks` — §3.7.
 *
 * Shape only. Whether the URL is a *safe destination* is decided by
 * OutboundUrlGuard inside the service, not here: that check performs DNS
 * resolution, it is re-run at delivery time, and it must apply to every caller
 * of the registrar, not only to callers who arrived through this FormRequest.
 * Laravel's `url` rule is deliberately not used either — it happily accepts
 * `http://localhost`, which is exactly the input the guard exists to refuse.
 */
final class RegisterWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // 500 is the column width and OutboundUrlGuard::MAX_URL_LENGTH.
            'url' => ['required', 'string', 'max:500'],

            'events' => ['required', 'array', 'min:1', 'max:'.count(WebhookEventType::cases())],
            // An unknown event name is rejected rather than ignored: a member
            // who typos `trade.execued` must be told, not left waiting for
            // events that will never arrive.
            'events.*' => ['required', 'string', Rule::in(WebhookEventType::values())],

            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'events.*.in' => 'رویداد انتخاب‌شده در فهرست رویدادهای مجاز نیست.',
        ];
    }

    /** @return list<string> */
    public function events(): array
    {
        /** @var list<string> $events */
        $events = array_values(array_unique((array) $this->input('events', [])));

        return $events;
    }
}
