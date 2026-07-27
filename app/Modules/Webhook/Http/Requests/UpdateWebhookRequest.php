<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Http\Requests;

use App\Modules\Webhook\Domain\WebhookEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /webhooks/{id}` — §3.13 «ویرایش رویدادها».
 *
 * Every field is optional and an omitted field is left alone, so a client that
 * only wants to change the subscription list does not have to re-send the URL
 * and risk clobbering it. The secret is not among them: rotating is its own
 * endpoint, with its own audit trail and its own one-time response.
 */
final class UpdateWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'url' => ['sometimes', 'string', 'max:500'],
            'events' => ['sometimes', 'array', 'min:1', 'max:'.count(WebhookEventType::cases())],
            'events.*' => ['required', 'string', Rule::in(WebhookEventType::values())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return list<string>|null */
    public function events(): ?array
    {
        if (! $this->has('events')) {
            return null;
        }

        /** @var list<string> $events */
        $events = array_values(array_unique((array) $this->input('events', [])));

        return $events;
    }
}
