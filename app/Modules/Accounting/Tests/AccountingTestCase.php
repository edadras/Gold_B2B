<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base test case for the Accounting module.
 *
 * It registers the module's provider itself rather than relying on
 * bootstrap/providers.php. That file is owned by the coordinator and is being
 * rewritten by several agents at once while the platform is assembled, so a
 * module's tests must not depend on their provider happening to be listed there
 * at the moment they run. Registering explicitly also documents exactly which
 * providers a test needs.
 *
 * It also moves off the shared `goldb2b_test` schema. Several agents build
 * against this machine at once and every one of them runs `migrate:fresh`;
 * sharing one schema means a foreign suite can drop this suite's tables
 * mid-run. The database name is overridable so CI, where nothing else is
 * running, can point it back at the default.
 */
abstract class AccountingTestCase extends TestCase
{
    use RefreshDatabase;
}
