<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Requests;

use App\Modules\Notification\Domain\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /notifications/preferences.
 *
 * Two shapes, one endpoint:
 *   · with `category` — change that category only;
 *   · without it      — the "mute everything" switch, applied to all five.
 *
 * The channel flags are all optional so a client can toggle one without having
 * to echo the other three back; quiet hours are the exception and are validated
 * as a pair, because half a window silently disables quiet hours.
 */
final class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['nullable', Rule::in(Category::values())],
            'in_app' => ['nullable', 'boolean'],
            'push' => ['nullable', 'boolean'],
            'sms' => ['nullable', 'boolean'],
            'email' => ['nullable', 'boolean'],
            'quiet_hours_from' => ['nullable', 'date_format:H:i,H:i:s', 'required_with:quiet_hours_to'],
            'quiet_hours_to' => ['nullable', 'date_format:H:i,H:i:s', 'required_with:quiet_hours_from'],
        ];
    }

    public function category(): ?Category
    {
        $category = $this->safe()->input('category');

        return is_string($category) ? Category::tryFrom($category) : null;
    }

    /**
     * Only the keys the client actually sent, so an absent flag keeps its
     * stored value instead of being reset to the default.
     *
     * @return array<string, bool|string|null>
     */
    public function settings(): array
    {
        /** @var array<string, bool|string|null> $settings */
        $settings = $this->safe()->only([
            'in_app', 'push', 'sms', 'email', 'quiet_hours_from', 'quiet_hours_to',
        ]);

        foreach (['in_app', 'push', 'sms', 'email'] as $flag) {
            if (array_key_exists($flag, $settings)) {
                $settings[$flag] = (bool) $settings[$flag];
            }
        }

        return $settings;
    }
}
