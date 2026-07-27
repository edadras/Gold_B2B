<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Shared base for the panel's controllers.
 *
 * Controllers here are deliberately thin: they resolve the actor, hand the
 * request to an Application service and render a view. Everything worth testing
 * lives in the service, which is also what makes a later move to Filament
 * mechanical — a Filament page would call the same service.
 */
abstract class AdminController
{
    /** The signed-in staff member. Never null behind the panel's middleware. */
    protected function actorId(): int
    {
        $id = Auth::id();

        if ($id === null) {
            throw new RuntimeException('An admin controller ran without an authenticated actor.');
        }

        return (int) $id;
    }
}
