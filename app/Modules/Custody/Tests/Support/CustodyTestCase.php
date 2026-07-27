<?php

declare(strict_types=1);

namespace App\Modules\Custody\Tests\Support;

use App\Modules\Custody\CustodyServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base for Custody tests that touch the database.
 *
 * Registers the module provider explicitly instead of relying on
 * bootstrap/providers.php, so the module's migrations and bindings are
 * available whether or not the coordinator has wired it up yet.
 */
abstract class CustodyTestCase extends TestCase
{
    use CreatesCustodyFixtures;
    use RefreshDatabase;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        // Application::register() is idempotent, so this is safe even once the
        // provider is listed in bootstrap/providers.php.
        $this->app->register(CustodyServiceProvider::class);
    }
}
