<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the panel's single stylesheet from inside the module.
 *
 * The environment has no outbound access, so a CDN is not an option, and there
 * is no build step, so neither is Vite. Publishing into `public/` would put a
 * module's file outside the module. A route that streams one known file is the
 * remaining option and keeps the module self-contained.
 */
final class AssetController
{
    private const STYLESHEET = __DIR__.'/../../Resources/assets/admin.css';

    public function stylesheet(): Response
    {
        $path = realpath(self::STYLESHEET);

        if ($path === false || ! is_file($path)) {
            throw new NotFoundHttpException;
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
