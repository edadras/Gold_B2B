<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Regenerates docs/05-api/openapi.yaml from the routes that actually exist.
 *
 * Hand-maintained API specs drift the moment someone adds an endpoint and
 * forgets the document. Deriving the path list from the router means the spec
 * cannot claim an endpoint that is not routed, or miss one that is; the CI
 * check runs this with --check and fails if the committed file is stale.
 *
 * Descriptions and schemas are enriched from a curated map below rather than
 * guessed from code, because a generated description that says nothing is
 * worse than a short one written by a person.
 */
final class GenerateOpenApiSpec extends Command
{
    protected $signature = 'openapi:generate {--check : Fail if the committed spec is out of date rather than rewriting it}';

    protected $description = 'Generate docs/05-api/openapi.yaml from the registered API routes';

    private const SPEC_PATH = 'docs/05-api/openapi.yaml';

    /** Endpoints that change money and therefore require an idempotency key. */
    private const IDEMPOTENT_PREFIXES = [
        'orders', 'otc-offers', 'rfqs', 'rfq-quotes', 'settlements',
        'vault', 'lots', 'netting-batches', 'disputes',
    ];

    public function handle(): int
    {
        $spec = $this->buildSpec();
        $yaml = Yaml::dump($spec, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $path = base_path(self::SPEC_PATH);

        if ($this->option('check')) {
            if (! is_file($path)) {
                $this->error('Spec file missing. Run: php artisan openapi:generate');

                return self::FAILURE;
            }

            if (trim(file_get_contents($path)) !== trim($yaml)) {
                $this->error('docs/05-api/openapi.yaml is out of date. Run: php artisan openapi:generate');

                return self::FAILURE;
            }

            $this->info('OpenAPI spec is current.');

            return self::SUCCESS;
        }

        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, $yaml);

        $this->info(sprintf(
            'Wrote %s — %d paths, %d operations.',
            self::SPEC_PATH,
            count($spec['paths']),
            array_sum(array_map(
                static fn (array $p): int => count(array_filter(
                    array_keys($p),
                    static fn (string $k): bool => $k !== 'parameters',
                )),
                $spec['paths'],
            )),
        ));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function buildSpec(): array
    {
        $paths = [];

        foreach ($this->apiRoutes() as $route) {
            $uri = '/'.ltrim(Str::after($route->uri(), 'api/v1'), '/');
            $uri = $uri === '/' ? '/' : rtrim($uri, '/');
            $uri = preg_replace('/\{(\w+)\??\}/', '{$1}', $uri);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$uri][strtolower($method)] = $this->operation($route, $method, $uri);
            }

            $params = $this->pathParameters($uri);
            if ($params !== []) {
                $paths[$uri]['parameters'] = $params;
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Gold B2B API',
                'version' => '1.0.0',
                'description' => trim('
Inter-dealer trading, settlement and custody for melted gold.

Conventions that apply to every endpoint are documented in
docs/05-api/01-conventions.md and summarised here:

* All weights are integer milligrams of FINE gold. All money is integer rial.
  There are no decimal or floating point quantities anywhere in this API.
* Purity is an integer in ten-thousandths: market purity 995 is 9950.
* Timestamps are ISO-8601 in UTC.
* Every state-changing financial request requires an `Idempotency-Key` header
  carrying a UUID. Retrying with the same key replays the stored response
  rather than repeating the action.
* High-value operations additionally require `X-Transaction-Signature`, a TOTP
  code, and that check runs BEFORE the idempotency key is recorded so a
  corrected retry is not answered from cache.
'),
                'contact' => ['name' => 'Gold B2B platform team'],
            ],
            'servers' => [
                ['url' => '/api/v1', 'description' => 'Versioned API root'],
            ],
            'security' => [['bearerAuth' => []]],
            'tags' => $this->tags(),
            'paths' => $paths,
            'components' => $this->components(),
        ];
    }

    /** @return list<RoutingRoute> */
    private function apiRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1')) {
                $routes[] = $route;
            }
        }

        usort($routes, static fn (RoutingRoute $a, RoutingRoute $b) => $a->uri() <=> $b->uri());

        return $routes;
    }

    /** @return array<string, mixed> */
    private function operation(RoutingRoute $route, string $method, string $uri): array
    {
        $middleware = $route->gatherMiddleware();
        $group = $this->group($uri);
        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        $operation = [
            'tags' => [$group],
            'summary' => $this->summary($route, $method, $uri),
            'operationId' => $this->operationId($route, $method, $uri),
            'responses' => $this->responses($method, $middleware),
        ];

        $parameters = [];

        if ($isWrite && $this->needsIdempotency($uri, $middleware)) {
            $parameters[] = ['$ref' => '#/components/parameters/IdempotencyKey'];
        }

        if (in_array('transaction.sign', $middleware, true)) {
            $parameters[] = ['$ref' => '#/components/parameters/TransactionSignature'];
            $operation['description'] = 'Requires a re-signed transaction: send a current TOTP code in `X-Transaction-Signature`.';
        }

        if ($method === 'GET' && $this->isCollection($uri)) {
            $parameters[] = ['$ref' => '#/components/parameters/Limit'];
            $parameters[] = ['$ref' => '#/components/parameters/Cursor'];
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($isWrite) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ];
        }

        if ($this->isPublic($middleware)) {
            $operation['security'] = [];
        }

        return $operation;
    }

    private function needsIdempotency(string $uri, array $middleware): bool
    {
        if (in_array('idempotency', $middleware, true)) {
            return true;
        }

        $segment = trim(explode('/', trim($uri, '/'))[0] ?? '', '/');

        return in_array($segment, self::IDEMPOTENT_PREFIXES, true);
    }

    private function isPublic(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (str_starts_with($m, 'auth:')) {
                return false;
            }
        }

        return true;
    }

    private function isCollection(string $uri): bool
    {
        return ! str_ends_with($uri, '}');
    }

    /** @return array<string, mixed> */
    private function responses(string $method, array $middleware): array
    {
        $ok = match ($method) {
            'POST' => '201',
            'DELETE' => '204',
            default => '200',
        };

        $responses = [
            $ok => [
                'description' => 'Success',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
            ],
            '422' => ['$ref' => '#/components/responses/DomainError'],
            '429' => ['$ref' => '#/components/responses/RateLimited'],
        ];

        if (! $this->isPublic($middleware)) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
        }

        if (in_array('idempotency', $middleware, true)) {
            $responses['409'] = ['$ref' => '#/components/responses/IdempotencyConflict'];
        }

        ksort($responses);

        return $responses;
    }

    private function group(string $uri): string
    {
        return explode('/', trim($uri, '/'))[0] ?: 'root';
    }

    private function summary(RoutingRoute $route, string $method, string $uri): string
    {
        $action = $route->getActionMethod();
        $group = $this->group($uri);

        if ($action !== null && $action !== 'Closure' && ! str_contains($action, '\\')) {
            return Str::headline($action).' — '.$group;
        }

        return match ($method) {
            'GET' => 'Read '.$group,
            'POST' => 'Create '.$group,
            'PUT', 'PATCH' => 'Update '.$group,
            'DELETE' => 'Delete '.$group,
            default => $group,
        };
    }

    private function operationId(RoutingRoute $route, string $method, string $uri): string
    {
        if ($route->getName() !== null) {
            return Str::camel(str_replace(['.', '-'], ' ', $route->getName()));
        }

        return Str::camel(strtolower($method).' '.str_replace(['/', '{', '}', '-'], ' ', $uri));
    }

    /** @return list<array<string, mixed>> */
    private function pathParameters(string $uri): array
    {
        preg_match_all('/\{(\w+)\}/', $uri, $matches);

        return array_map(static fn (string $name): array => [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
        ], $matches[1]);
    }

    /** @return list<array<string, string>> */
    private function tags(): array
    {
        $groups = [];

        foreach ($this->apiRoutes() as $route) {
            $uri = '/'.ltrim(Str::after($route->uri(), 'api/v1'), '/');
            $groups[$this->group($uri)] = true;
        }

        $named = array_keys($groups);
        sort($named);

        return array_map(static fn (string $g): array => ['name' => $g], $named);
    }

    /** @return array<string, mixed> */
    private function components(): array
    {
        return [
            'securitySchemes' => [
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
            ],
            'parameters' => [
                'IdempotencyKey' => [
                    'name' => 'Idempotency-Key',
                    'in' => 'header',
                    'required' => true,
                    'schema' => ['type' => 'string', 'format' => 'uuid'],
                    'description' => 'A UUID minted by the client when the intent is formed, not when the request is sent. Reuse the same key on every retry of the same intent.',
                ],
                'TransactionSignature' => [
                    'name' => 'X-Transaction-Signature',
                    'in' => 'header',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                    'description' => 'Current TOTP code. Verified before the idempotency key is recorded.',
                ],
                'Limit' => [
                    'name' => 'limit',
                    'in' => 'query',
                    'schema' => ['type' => 'integer', 'default' => 50, 'maximum' => 200],
                ],
                'Cursor' => [
                    'name' => 'cursor',
                    'in' => 'query',
                    'schema' => ['type' => 'string'],
                    'description' => 'Opaque cursor from the previous response. Cursor paging is stable against concurrent inserts; offset paging is not.',
                ],
            ],
            'schemas' => [
                'Envelope' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => ['description' => 'Resource or collection'],
                        'meta' => ['$ref' => '#/components/schemas/Meta'],
                        'links' => ['type' => 'object', 'nullable' => true],
                    ],
                    'required' => ['data', 'meta'],
                ],
                'Meta' => [
                    'type' => 'object',
                    'properties' => [
                        'request_id' => ['type' => 'string', 'format' => 'uuid'],
                        'server_time' => ['type' => 'string', 'format' => 'date-time'],
                        'count' => ['type' => 'integer'],
                    ],
                ],
                'Error' => [
                    'type' => 'object',
                    'properties' => [
                        'error' => [
                            'type' => 'object',
                            'properties' => [
                                'code' => ['type' => 'string', 'example' => 'INSUFFICIENT_GOLD'],
                                'message' => ['type' => 'string', 'description' => 'Persian, safe to show a user'],
                                'details' => ['type' => 'object', 'nullable' => true],
                                'field_errors' => ['type' => 'object', 'nullable' => true],
                            ],
                            'required' => ['code', 'message'],
                        ],
                        'meta' => ['$ref' => '#/components/schemas/Meta'],
                    ],
                ],
                'FineWeight' => [
                    'type' => 'integer',
                    'format' => 'int64',
                    'description' => 'Milligrams of pure gold. 248750 is 248.750 g fine.',
                    'example' => 248750,
                ],
                'Rial' => [
                    'type' => 'integer',
                    'format' => 'int64',
                    'description' => 'Whole rial. Never a decimal.',
                    'example' => 19521900000,
                ],
                'Purity' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 10000,
                    'description' => 'Ten-thousandths. Market purity 995 is 9950.',
                    'example' => 9950,
                ],
            ],
            'responses' => [
                'DomainError' => [
                    'description' => 'A business rule refused the request',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
                ],
                'Unauthenticated' => [
                    'description' => 'Missing or expired token',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
                ],
                'Forbidden' => [
                    'description' => 'Authenticated but not permitted, or the resource belongs to another organization',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
                ],
                'RateLimited' => [
                    'description' => 'Too many requests',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
                ],
                'IdempotencyConflict' => [
                    'description' => 'An identical request is still in flight, or the key was reused with a different body',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
                ],
            ],
        ];
    }
}
