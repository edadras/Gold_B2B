<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use Illuminate\Http\Request;

/**
 * A registered endpoint — the §3.7 shape, minus the secret.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  THERE IS NO `secret` KEY IN THIS RESOURCE, AND THERE MUST NEVER BE ONE.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * §3.7: «این تنها بار نمایش secret است. آن را ذخیره کنید.» The plaintext is
 * emitted by exactly two controller actions (register and rotate-secret), each
 * of which adds it to this array by hand from an IssuedSecret. Every other read
 * path in the module renders this class, so "never shown again" is a property of
 * the code, not of a reviewer remembering.
 *
 * `secret_last_four` is the compromise that makes the rule liveable: enough to
 * tell two registrations apart in a UI, useless to an attacker.
 *
 * @mixin Webhook
 */
final class WebhookResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Webhook $webhook */
        $webhook = $this->resource;

        return [
            'id' => (int) $webhook->getKey(),
            'url' => (string) $webhook->getAttribute('url'),
            'events' => $webhook->subscribedEvents(),
            'status' => $webhook->getAttribute('status')->value,
            'description' => $webhook->getAttribute('description'),
            'secret_last_four' => (string) $webhook->getAttribute('secret_last_four'),
            'secret_rotated_at' => Display::iso($webhook->getAttribute('secret_rotated_at')),

            // Health, so a member can diagnose their own integration without
            // opening a support ticket (§3.13 «مدیریت و اشکال‌زدایی»).
            'consecutive_failures' => (int) $webhook->getAttribute('consecutive_failures'),
            'total_deliveries' => (int) $webhook->getAttribute('total_deliveries'),
            'total_failures' => (int) $webhook->getAttribute('total_failures'),
            'last_error' => $webhook->getAttribute('last_error'),
            'last_success_at' => Display::iso($webhook->getAttribute('last_success_at')),
            'last_failure_at' => Display::iso($webhook->getAttribute('last_failure_at')),
            'disabled_at' => Display::iso($webhook->getAttribute('disabled_at')),
            'created_at' => Display::iso($webhook->getAttribute('created_at')),
        ];
    }
}
