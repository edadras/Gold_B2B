<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Tests\Support;

use App\Modules\Webhook\Contracts\HostResolver;
use App\Modules\Webhook\WebhookServiceProvider;
use Illuminate\Foundation\Application;

/**
 * Registers the module's provider for its own tests.
 *
 * bootstrap/providers.php is owned by the coordinator and does not list Webhook,
 * so the module has to bring itself online. The registration happens inside
 * createApplication(), i.e. BEFORE RefreshDatabase runs from setUpTraits():
 * loadMigrationsFrom() is what makes `webhooks` and `webhook_deliveries` exist,
 * and a provider registered any later would contribute no schema and the whole
 * suite would fail with "table doesn't exist".
 */
trait RegistersWebhookModule
{
    protected FakeHostResolver $hosts;

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = parent::createApplication();

        $app->register(WebhookServiceProvider::class);

        // The resolver is swapped here rather than in setUp() so that no code
        // path can resolve OutboundUrlGuard (a singleton) against the real DNS
        // resolver first and then keep it.
        $this->hosts = new FakeHostResolver;
        $app->instance(HostResolver::class, $this->hosts);

        return $app;
    }
}
