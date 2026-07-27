<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Listeners;

use App\Modules\Webhook\Application\WebhookDispatcher;
use App\Modules\Webhook\Domain\WebhookEventType;
use Illuminate\Support\Facades\Log;
use ReflectionObject;
use ReflectionProperty;
use Throwable;

/**
 * The single bridge between the rest of the platform and the webhook catalogue.
 *
 * ───────────────────────── WHY EVERYTHING IS A STRING ─────────────────────────
 *
 * Webhook may depend on Shared and Identity only, so it cannot `use` a single
 * one of the producing event classes. They are named as strings in
 * `goldb2b.webhook.event_map`; the provider subscribes to them by class NAME and
 * this listener receives `object`. Nothing is imported, nothing is type-hinted,
 * and a module that is not deployed simply never fires — the map entry sits
 * there inert. That is the same arrangement the Notification module already
 * uses, and it is what lets four agents build modules concurrently without a
 * compile-time edge between them.
 *
 * The consequence is that every payload must be read DEFENSIVELY. An event class
 * may gain, lose or rename a property at any time without this module noticing,
 * so:
 *
 *   · properties are discovered by reflection, not assumed;
 *   · only public, initialised, non-static, scalar (or scalar-list) properties
 *     are copied — an object property could be an Eloquent model, and putting a
 *     model into a webhook payload would leak whatever columns it happens to
 *     have, forever, to a third party;
 *   · an event with no discoverable organisation is logged and dropped rather
 *     than throwing, because throwing inside a listener would roll back or fail
 *     the business operation that fired it. A missed webhook must never break a
 *     trade.
 *
 * ─────────────────────── WHO RECEIVES WHICH EVENT ───────────────────────
 *
 * The recipients are every organisation named by a property whose name ends in
 * `OrganizationId`/`OrgId` (or the matching plural array). That covers
 * `organizationId`, `buyerOrganizationId`, `goldDelivererOrgId`,
 * `claimantOrgId`, `participantOrganizationIds` … without this module needing to
 * know the shape of any particular event. Each recipient gets its own envelope
 * with its own `organization_id`, and therefore its own event id.
 *
 * A member only ever receives events their own organisation is a party to; the
 * dispatcher then filters again by subscription.
 */
final class DispatchWebhooksForDomainEvent
{
    /** Property names that identify an organisation, matched case-insensitively. */
    private const ORG_SUFFIXES = ['organizationid', 'orgid'];

    private const ORG_LIST_SUFFIXES = ['organizationids', 'orgids'];

    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    /**
     * Domain event class name => §3.12 catalogue type.
     *
     * The map lives in `goldb2b.webhook.event_map` so that this listener and
     * WebhookServiceProvider::listeners() read the same list — the set of events
     * subscribed to and the set that can be translated must not be able to
     * drift apart.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        /** @var array<string, string> $map */
        $map = (array) config('goldb2b.webhook.event_map', []);

        return $map;
    }

    public static function typeFor(string $eventClass): ?WebhookEventType
    {
        return WebhookEventType::tryFrom(self::map()[$eventClass] ?? '');
    }

    public function handle(object $event): void
    {
        $type = self::typeFor($event::class);

        if ($type === null) {
            return;
        }

        try {
            $properties = $this->readableProperties($event);
            $recipients = $this->recipients($properties);

            if ($recipients === []) {
                Log::debug('Webhook ignored an event with no organisation', ['event' => $event::class]);

                return;
            }

            $this->dispatcher->dispatchToAll($type, $recipients, $this->data($properties));
        } catch (Throwable $e) {
            // A webhook is an integration convenience. It does not get to fail
            // the trade, the settlement or the ledger write that produced it.
            Log::error('Webhook dispatch failed for a domain event', [
                'event' => $event::class,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Public, initialised, non-static properties whose value is a scalar or a
     * list of scalars.
     *
     * @return array<string, mixed>
     */
    private function readableProperties(object $event): array
    {
        $values = [];

        foreach ((new ReflectionObject($event))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || ! $property->isInitialized($event)) {
                continue;
            }

            /** @var mixed $value */
            $value = $property->getValue($event);

            if (is_scalar($value) || $value === null) {
                $values[$property->getName()] = $value;

                continue;
            }

            if (is_array($value) && $this->isScalarList($value)) {
                $values[$property->getName()] = array_values($value);
            }
            // Anything else (a model, a value object, a closure) is skipped.
        }

        return $values;
    }

    /** @param array<array-key, mixed> $value */
    private function isScalarList(array $value): bool
    {
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<int>
     */
    private function recipients(array $properties): array
    {
        $ids = [];

        foreach ($properties as $name => $value) {
            $lower = strtolower($name);

            foreach (self::ORG_SUFFIXES as $suffix) {
                if (str_ends_with($lower, $suffix) && is_int($value) && $value > 0) {
                    $ids[] = $value;
                }
            }

            foreach (self::ORG_LIST_SUFFIXES as $suffix) {
                if (str_ends_with($lower, $suffix) && is_array($value)) {
                    foreach ($value as $item) {
                        if (is_int($item) && $item > 0) {
                            $ids[] = $item;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The payload's `data` object: every readable property, snake_cased, in a
     * stable order.
     *
     * Sorted because the event id is a digest of this map (Domain\EventIdentity)
     * — a reordered constructor must not re-issue ids.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function data(array $properties): array
    {
        $data = [];

        foreach ($properties as $name => $value) {
            $data[$this->snake($name)] = $value;
        }

        ksort($data);

        return $data;
    }

    private function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
