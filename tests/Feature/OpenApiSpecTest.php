<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The spec is a checked-in artefact, not a runtime rendering: a spec generated
 * on every request cannot be diffed in review, and a client cannot be generated
 * from it before the server is running. These tests keep it honest — the
 * committed file must match what the router actually exposes.
 */
final class OpenApiSpecTest extends TestCase
{
    private const SPEC = 'docs/05-api/openapi.yaml';

    #[Test]
    public function the_committed_spec_matches_the_registered_routes(): void
    {
        $exit = Artisan::call('openapi:generate', ['--check' => true]);

        self::assertSame(
            0,
            $exit,
            "docs/05-api/openapi.yaml is stale. Run: php artisan openapi:generate\n".Artisan::output(),
        );
    }

    #[Test]
    public function the_spec_is_structurally_valid(): void
    {
        $spec = Yaml::parseFile(base_path(self::SPEC));

        self::assertSame('3.1.0', $spec['openapi']);
        self::assertNotEmpty($spec['paths']);
        self::assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);

        // Every $ref must resolve, or a generated client breaks on import.
        $refs = [];
        array_walk_recursive($spec, static function ($value, $key) use (&$refs): void {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;
            }
        });

        foreach (array_unique($refs) as $ref) {
            $segments = explode('/', ltrim($ref, '#/'));
            $node = $spec;

            foreach ($segments as $segment) {
                self::assertArrayHasKey($segment, $node, "Dangling reference: {$ref}");
                $node = $node[$segment];
            }
        }
    }

    #[Test]
    public function money_moving_endpoints_document_their_idempotency_key(): void
    {
        $spec = Yaml::parseFile(base_path(self::SPEC));

        // Placing an order, accepting a quote and confirming a payment all move
        // value. A client that does not know to send a key will double-submit
        // on any dropped response.
        $mustDeclareKey = [
            '/orders' => 'post',
            '/otc-offers' => 'post',
            '/rfqs' => 'post',
        ];

        foreach ($mustDeclareKey as $path => $method) {
            self::assertArrayHasKey($path, $spec['paths'], "{$path} is missing from the spec");

            $params = $spec['paths'][$path][$method]['parameters'] ?? [];
            $refs = array_column($params, '$ref');

            self::assertContains(
                '#/components/parameters/IdempotencyKey',
                $refs,
                "{$method} {$path} does not document Idempotency-Key",
            );
        }
    }

    #[Test]
    public function integer_money_and_weight_are_documented_as_integers(): void
    {
        $spec = Yaml::parseFile(base_path(self::SPEC));
        $schemas = $spec['components']['schemas'];

        // The single most important thing a client integrator must not get
        // wrong: these are never decimals.
        foreach (['FineWeight', 'Rial', 'Purity'] as $name) {
            self::assertSame('integer', $schemas[$name]['type'], "{$name} must be documented as an integer");
        }
    }

    #[Test]
    public function the_endpoint_serves_the_committed_spec(): void
    {
        $response = $this->getJson('/api/v1/openapi.json');

        $response->assertOk();
        $response->assertJsonPath('openapi', '3.1.0');
        self::assertNotEmpty($response->json('paths'));
    }
}
