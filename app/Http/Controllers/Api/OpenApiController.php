<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Yaml\Yaml;

/**
 * `GET /openapi.json` — serves docs/05-api/openapi.yaml as JSON.
 *
 * The spec file is the checked-in artefact and this endpoint is a view of it,
 * rather than the other way round: a spec generated fresh on every request
 * cannot be diffed in review, and a spec that only exists at runtime cannot be
 * used to generate a client before the server is up.
 */
final class OpenApiController
{
    private const SPEC_PATH = 'docs/05-api/openapi.yaml';

    public function __invoke(): JsonResponse
    {
        $path = base_path(self::SPEC_PATH);

        if (! is_file($path)) {
            throw new NotFoundHttpException('RESOURCE_NOT_FOUND');
        }

        // Symfony's YAML component ships with Laravel; if it is ever absent the
        // endpoint degrades to serving the raw document rather than 500ing.
        if (! class_exists(Yaml::class)) {
            return response()->json(['error' => [
                'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'مشخصات OpenAPI در حال حاضر قابل ارائه نیست.',
            ]], 503);
        }

        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile($path);

        return response()->json($spec, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
