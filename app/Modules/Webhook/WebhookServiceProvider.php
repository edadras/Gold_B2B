<?php

declare(strict_types=1);

namespace App\Modules\Webhook;

use App\Modules\Shared\Concerns\ModuleServiceProvider;
use App\Modules\Webhook\Application\OutboundUrlGuard;
use App\Modules\Webhook\Application\SignatureGenerator;
use App\Modules\Webhook\Application\WebhookDispatcher;
use App\Modules\Webhook\Application\WebhookRegistrar;
use App\Modules\Webhook\Console\DispatchDueDeliveriesCommand;
use App\Modules\Webhook\Console\PruneWebhookDeliveriesCommand;
use App\Modules\Webhook\Contracts\HostResolver;
use App\Modules\Webhook\Infrastructure\DnsHostResolver;
use App\Modules\Webhook\Listeners\DispatchWebhooksForDomainEvent;

final class WebhookServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/webhook.php', 'goldb2b.webhook');

        parent::register();

        $this->app->singleton(SignatureGenerator::class);
        $this->app->singleton(OutboundUrlGuard::class);
        $this->app->singleton(WebhookRegistrar::class);
        $this->app->singleton(WebhookDispatcher::class);
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [
            // The SSRF guard's view of DNS. Tests bind an in-memory resolver;
            // nothing else in the module changes.
            HostResolver::class => DnsHostResolver::class,
        ];
    }

    /** @return array<class-string> */
    protected function consoleCommands(): array
    {
        return [
            DispatchDueDeliveriesCommand::class,
            PruneWebhookDeliveriesCommand::class,
        ];
    }

    /**
     * The §3.12 catalogue, wired to its producers BY STRING.
     *
     * The list is `goldb2b.webhook.event_map` — the same map the listener uses
     * to translate, so the two cannot drift. Webhook may depend on Shared and
     * Identity only, so not one of those classes may be imported; Laravel's
     * event dispatcher takes a string key, which means a module that is not
     * deployed simply never fires its entry and nothing here breaks — the same
     * arrangement Notification uses.
     *
     * The one listener reads every payload defensively; see
     * Listeners\DispatchWebhooksForDomainEvent for what "defensively" buys and
     * costs.
     *
     * @return array<class-string|string, array<class-string>>
     */
    protected function listeners(): array
    {
        $map = [];

        foreach (array_keys(DispatchWebhooksForDomainEvent::map()) as $event) {
            $map[(string) $event] = [DispatchWebhooksForDomainEvent::class];
        }

        return $map;
    }
}
