<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Tests;

use App\Modules\Webhook\Application\DeliverWebhookJob;
use App\Modules\Webhook\Application\OutboundUrlGuard;
use App\Modules\Webhook\Application\SignatureGenerator;
use App\Modules\Webhook\Tests\Support\RegistersWebhookModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base for the module's service-level tests. HTTP tests use
 * WebhookApiTestCase, which adds Identity's fixtures.
 */
abstract class WebhookTestCase extends TestCase
{
    use RefreshDatabase;
    use RegistersWebhookModule;

    /**
     * Run one delivery attempt synchronously.
     *
     * The job is invoked directly rather than through the queue so a test can
     * step the retry ladder one rung at a time and inspect the row in between —
     * which is exactly what §3.10 needs asserting.
     */
    protected function attemptDelivery(int $deliveryId): void
    {
        (new DeliverWebhookJob($deliveryId))->handle(
            $this->app->make(OutboundUrlGuard::class),
            $this->app->make(SignatureGenerator::class),
        );
    }
}
