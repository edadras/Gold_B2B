<?php

declare(strict_types=1);

namespace App\Modules\Web\Tests;

use App\Modules\Web\WebServiceProvider;
use Illuminate\Foundation\Application;
use Tests\TestCase;

/**
 * Base case for the Web module.
 *
 * Registers WebServiceProvider by hand. `bootstrap/providers.php` carries a
 * fixed module list that this module is not on, and the coordinator owns that
 * file — see resources/README.md, which spells out the one line that has to be
 * added there for `/app` to exist outside the test suite. Registering it here
 * means the module's routes are exercised exactly as they will be in the
 * application, without editing a file this agent may not touch.
 */
abstract class WebTestCase extends TestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app->register(WebServiceProvider::class);

        return $app;
    }
}
