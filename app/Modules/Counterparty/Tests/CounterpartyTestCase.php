<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Tests;

use App\Modules\Counterparty\Application\RelationService;
use App\Modules\Counterparty\CounterpartyServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class CounterpartyTestCase extends TestCase
{
    use RefreshDatabase;

    protected RelationService $relations;

    /**
     * The module registers itself rather than relying on bootstrap/providers.php.
     *
     * The hook has to be here and not in setUp(): RefreshDatabase migrates from
     * setUpTraits(), which runs before any afterApplicationCreated callback, so
     * a provider registered later would contribute no migrations. Registering
     * twice is a no-op, so this stays correct once the provider list includes
     * the module too.
     */

    protected function setUp(): void
    {
        parent::setUp();

        $this->relations = $this->app->make(RelationService::class);
    }

    /** Both rows of a pair, keyed by direction. */
    protected function goldBalance(int $organizationId, int $counterpartyOrgId): int
    {
        return $this->relations->relation($organizationId, $counterpartyOrgId)?->gold_balance_mg ?? 0;
    }

    protected function rialBalance(int $organizationId, int $counterpartyOrgId): int
    {
        return $this->relations->relation($organizationId, $counterpartyOrgId)?->rial_balance ?? 0;
    }
}
