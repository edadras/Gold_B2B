<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Tests;

use App\Modules\Webhook\Tests\Support\RegistersWebhookModule;
use Tests\Api\ApiTestCase;

/**
 * Base for the §3.13 endpoint tests: Identity's organisations, users, roles and
 * Sanctum tokens from ApiTestCase, plus this module's provider.
 */
abstract class WebhookApiTestCase extends ApiTestCase
{
    use RegistersWebhookModule;
}
