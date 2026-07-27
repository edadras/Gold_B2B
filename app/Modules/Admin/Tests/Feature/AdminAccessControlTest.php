<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tests\Feature;

use App\Modules\Admin\Tests\AdminTestCase;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/08-frontend-web/01-web-panels.md §1.11 — the panel is staff-only, and
 * "staff-only" has to mean every route, not the ones someone remembered.
 *
 * The test enumerates the registered route table rather than a hand-written
 * list, so a route added tomorrow without middleware fails this test the moment
 * it appears.
 */
final class AdminAccessControlTest extends AdminTestCase
{
    use RefreshDatabase;

    /** Routes that are deliberately open: the login form and the stylesheet. */
    private const PUBLIC_ROUTES = ['admin.login', 'admin.login.submit', 'admin.assets.css'];

    #[Test]
    public function a_member_user_is_refused_every_admin_route(): void
    {
        $this->seedRoles();

        $member = $this->makeUser($this->memberOrganization(), [Role::OWNER]);

        $checked = 0;

        foreach ($this->adminRoutes() as [$name, $method, $uri]) {
            $response = $this->actingAs($member)->call($method, '/'.$uri);

            $this->assertSame(
                403,
                $response->getStatusCode(),
                "Route {$name} ({$method} /{$uri}) did not refuse a member user.",
            );

            $checked++;
        }

        // A guard that silently checked nothing would otherwise pass.
        $this->assertGreaterThan(15, $checked, 'The admin route table looks suspiciously small.');
    }

    #[Test]
    public function an_unauthenticated_visitor_is_sent_to_the_login_page(): void
    {
        $this->seedRoles();

        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    #[Test]
    public function platform_staff_reach_the_dashboard(): void
    {
        $this->seedRoles();

        $admin = $this->staffUser([Role::PLATFORM_ADMIN]);

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    #[Test]
    public function aml_sits_behind_a_permission_separate_from_general_admin_access(): void
    {
        $this->seedRoles();

        // A settlement officer is platform staff and passes PlatformStaffOnly,
        // but holds no `platform.aml.manage`, which is precisely the separation
        // §1.11 asks for.
        $settlementOfficer = $this->staffUser([Role::SETTLEMENT_OFFICER]);

        $this->actingAs($settlementOfficer)->get('/admin')->assertOk();
        $this->actingAs($settlementOfficer)->get('/admin/aml')->assertForbidden();

        $complianceOfficer = $this->staffUser([Role::COMPLIANCE_OFFICER]);

        $this->actingAs($complianceOfficer)->get('/admin/aml')->assertOk();
    }

    #[Test]
    public function no_admin_route_deletes_anything(): void
    {
        $verbs = [];

        foreach ($this->adminRoutes(includePublic: true) as [, $method]) {
            $verbs[$method] = true;
        }

        $this->assertSame(
            [],
            array_intersect(['DELETE', 'PUT', 'PATCH'], array_keys($verbs)),
            '§1.11 forbids deleting a financial record; the panel exposes no destructive verb at all.',
        );
    }

    /**
     * Every admin route as [name, method, uri], skipping the public ones and
     * routes whose URI needs a parameter we cannot invent — those are covered
     * with real ids by the screen-specific tests.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function adminRoutes(bool $includePublic = false): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $name = (string) $route->getName();

            if (! str_starts_with($uri, 'admin')) {
                continue;
            }

            if (! $includePublic && in_array($name, self::PUBLIC_ROUTES, true)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                // Parameterised routes still refuse a member — the middleware
                // runs before the controller — so a placeholder id is fine.
                $out[] = [$name, $method, str_replace(
                    ['{organization}', '{document}', '{settlement}', '{account}', '{adjustment}', '{flag}', '{dispute}', '{vault}'],
                    '1',
                    $uri,
                )];
            }
        }

        return $out;
    }
}
