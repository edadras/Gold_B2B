<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Tests;

use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Reporting\Tests\Support\FakeReportingDataSource;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base test case for the Reporting module.
 *
 * The provider is registered here rather than relied upon from
 * bootstrap/providers.php, which the coordinator owns and several agents rewrite
 * while the platform is being assembled; and the schema is separated from the
 * shared `goldb2b_test`, which every agent's `migrate:fresh` drops.
 */
abstract class ReportingTestCase extends TestCase
{
    use RefreshDatabase;

    private const TEST_DATABASE = 'goldb2b_test_adr';

    protected FakeReportingDataSource $data;

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $app['config']->set(
            'database.connections.mysql.database',
            env('REPORTING_TEST_DB', self::TEST_DATABASE),
        );

        $app->register(ReportingServiceProvider::class);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->data = new FakeReportingDataSource;
        $this->app->instance(ReportingDataSource::class, $this->data);
    }
}
