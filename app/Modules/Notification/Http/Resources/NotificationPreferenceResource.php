<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Resources;

use App\Modules\Notification\Application\ChannelPreferences;
use App\Modules\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One category's channel settings — `GET/PUT /notifications/preferences`.
 *
 * Wraps the resolved ChannelPreferences value object, not the row, because a
 * user who never opened the settings screen has no row and still has
 * preferences (the §15.3 defaults). Rendering the row would show them nothing.
 *
 * @mixin ChannelPreferences
 */
final class NotificationPreferenceResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ChannelPreferences $preferences */
        $preferences = $this->resource;

        return [
            'category' => $preferences->category->value,
            'in_app' => $preferences->inApp,
            'push' => $preferences->push,
            'sms' => $preferences->sms,
            'email' => $preferences->email,
            'quiet_hours_from' => $preferences->quietHoursFrom,
            'quiet_hours_to' => $preferences->quietHoursTo,
            'has_quiet_hours' => $preferences->hasQuietHours(),
        ] + $this->display($request, [
            'category_display' => $preferences->category->label(),
        ]);
    }
}
