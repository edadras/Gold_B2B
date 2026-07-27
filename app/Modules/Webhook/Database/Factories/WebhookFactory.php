<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Database\Factories;

use App\Modules\Webhook\Domain\WebhookEventType;
use App\Modules\Webhook\Domain\WebhookStatus;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Webhook>
 */
final class WebhookFactory extends Factory
{
    protected $model = Webhook::class;

    private static int $sequence = 0;

    /** The plaintext of the secret the factory issues, so tests can sign with it. */
    public const SECRET = 'whsec_factory0000000000000000000000000000000000000000000000000000';

    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'organization_id' => 1,
            'url' => sprintf('https://hook-%d.member-example.com/goldb2b', $n),
            'events' => [
                WebhookEventType::TRADE_EXECUTED->value,
                WebhookEventType::SETTLEMENT_COMPLETED->value,
            ],
            'secret_encrypted' => self::SECRET,
            'secret_hash' => hash('sha256', self::SECRET),
            'secret_last_four' => substr(self::SECRET, -4),
            'status' => WebhookStatus::ACTIVE,
            'description' => 'اتصال نرم‌افزار حسابداری',
        ];
    }

    public function forOrganization(int $organizationId): self
    {
        return $this->state(fn (): array => ['organization_id' => $organizationId]);
    }

    /** @param list<WebhookEventType|string> $events */
    public function subscribedTo(array $events): self
    {
        return $this->state(fn (): array => [
            'events' => array_map(
                static fn (WebhookEventType|string $e): string => $e instanceof WebhookEventType ? $e->value : $e,
                $events,
            ),
        ]);
    }

    public function withUrl(string $url): self
    {
        return $this->state(fn (): array => ['url' => $url]);
    }

    public function withSecret(string $secret): self
    {
        return $this->state(fn (): array => [
            'secret_encrypted' => $secret,
            'secret_hash' => hash('sha256', $secret),
            'secret_last_four' => substr($secret, -4),
        ]);
    }

    public function status(WebhookStatus $status): self
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'disabled_at' => $status === WebhookStatus::DISABLED ? now() : null,
        ]);
    }
}
