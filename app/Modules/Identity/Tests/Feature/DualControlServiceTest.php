<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Application\DualControlService;
use App\Modules\Identity\Domain\DualControlStatus;
use App\Modules\Identity\Domain\Exceptions\DualControlViolationException;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Tests\IdentityTestCase;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class DualControlServiceTest extends IdentityTestCase
{
    use RefreshDatabase;

    private DualControlService $service;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(DualControlService::class);
        $this->organization = Organization::factory()->active()->create();
    }

    public function test_maker_and_checker_must_be_different_users(): void
    {
        $maker = $this->userInOrganization();

        $request = $this->service->request(
            action: 'vault.withdraw',
            payload: ['lot_id' => 17, 'weight_mg' => 250_000],
            makerUserId: (int) $maker->id,
        );

        try {
            $this->service->approve((int) $request->id, (int) $maker->id);
            $this->fail('a user must not be able to approve their own request');
        } catch (DualControlViolationException $e) {
            $this->assertSame('maker_cannot_be_checker', $e->violation);
        }

        $this->assertSame(DualControlStatus::PENDING, $request->fresh()->status);
    }

    public function test_self_rejection_is_refused_too(): void
    {
        $maker = $this->userInOrganization();

        $request = $this->service->request('bank_account.add', ['iban' => 'IR...'], (int) $maker->id);

        $this->expectException(DualControlViolationException::class);

        $this->service->reject((int) $request->id, (int) $maker->id, 'changed my mind');
    }

    public function test_the_database_itself_refuses_a_self_approval(): void
    {
        $maker = $this->userInOrganization();

        $request = $this->service->request('vault.withdraw', ['lot_id' => 1], (int) $maker->id);

        // The application check is not the only line of defence: a raw update
        // that bypasses the service must still fail.
        $this->expectException(QueryException::class);

        DB::table('dual_control_requests')
            ->where('id', $request->id)
            ->update(['checker_user_id' => $maker->id]);
    }

    public function test_a_different_user_can_approve(): void
    {
        $maker = $this->userInOrganization();
        $checker = $this->userInOrganization();

        $request = $this->service->request(
            action: 'vault.withdraw',
            payload: ['lot_id' => 17],
            makerUserId: (int) $maker->id,
            makerNote: 'خروج برای تحویل مشتری',
        );

        $approved = $this->service->approve((int) $request->id, (int) $checker->id, 'تأیید شد');

        $this->assertSame(DualControlStatus::APPROVED, $approved->status);
        $this->assertSame((int) $checker->id, (int) $approved->checker_user_id);
        $this->assertNotNull($approved->decided_at);
        $this->assertSame(['lot_id' => 17], $approved->payload);
    }

    public function test_a_request_cannot_be_decided_twice(): void
    {
        $maker = $this->userInOrganization();
        $checker = $this->userInOrganization();
        $other = $this->userInOrganization();

        $request = $this->service->request('vault.withdraw', ['lot_id' => 3], (int) $maker->id);
        $this->service->approve((int) $request->id, (int) $checker->id);

        try {
            $this->service->approve((int) $request->id, (int) $other->id);
            $this->fail('a decided request must not be re-approved');
        } catch (DualControlViolationException $e) {
            $this->assertSame('already_decided:APPROVED', $e->violation);
        }
    }

    public function test_rejection_requires_a_note(): void
    {
        $maker = $this->userInOrganization();
        $checker = $this->userInOrganization();

        $request = $this->service->request('vault.withdraw', ['lot_id' => 3], (int) $maker->id);

        $this->expectException(OperationNotPermittedException::class);

        $this->service->reject((int) $request->id, (int) $checker->id, '   ');
    }

    public function test_a_checker_from_another_organisation_is_refused(): void
    {
        $maker = $this->userInOrganization();
        $outsider = $this->userInOrganization(Organization::factory()->active()->create());

        $request = $this->service->request('bank_account.add', ['iban' => 'IR...'], (int) $maker->id);

        $this->expectException(OperationNotPermittedException::class);

        $this->service->approve((int) $request->id, (int) $outsider->id);
    }

    public function test_expired_requests_cannot_be_approved(): void
    {
        $maker = $this->userInOrganization();
        $checker = $this->userInOrganization();

        $request = $this->service->request(
            action: 'vault.withdraw',
            payload: ['lot_id' => 9],
            makerUserId: (int) $maker->id,
            ttlHours: 1,
        );

        $request->forceFill(['expires_at' => now()->subHour()])->save();

        try {
            $this->service->approve((int) $request->id, (int) $checker->id);
            $this->fail('an expired request must not be approvable');
        } catch (DualControlViolationException $e) {
            $this->assertSame('request_expired', $e->violation);
        }

        $this->assertSame(DualControlStatus::EXPIRED, $request->fresh()->status);
    }

    public function test_only_the_maker_may_cancel(): void
    {
        $maker = $this->userInOrganization();
        $other = $this->userInOrganization();

        $request = $this->service->request('vault.withdraw', ['lot_id' => 4], (int) $maker->id);

        try {
            $this->service->cancel((int) $request->id, (int) $other->id);
            $this->fail('only the maker may cancel');
        } catch (OperationNotPermittedException $e) {
            $this->assertSame('only_the_maker_may_cancel', $e->reason);
        }

        $cancelled = $this->service->cancel((int) $request->id, (int) $maker->id);
        $this->assertSame(DualControlStatus::CANCELLED, $cancelled->status);
    }

    public function test_stale_requests_are_swept(): void
    {
        $maker = $this->userInOrganization();

        $stale = $this->service->request('vault.withdraw', ['lot_id' => 5], (int) $maker->id);
        $stale->forceFill(['expires_at' => now()->subDay()])->save();

        $fresh = $this->service->request('vault.withdraw', ['lot_id' => 6], (int) $maker->id);

        $this->assertSame(1, $this->service->expireStale());
        $this->assertSame(DualControlStatus::EXPIRED, $stale->fresh()->status);
        $this->assertSame(DualControlStatus::PENDING, $fresh->fresh()->status);
    }

    public function test_payload_hash_is_order_independent(): void
    {
        $this->assertSame(
            $this->service->payloadHash('vault.withdraw', ['a' => 1, 'b' => 2]),
            $this->service->payloadHash('vault.withdraw', ['b' => 2, 'a' => 1]),
        );

        $this->assertNotSame(
            $this->service->payloadHash('vault.withdraw', ['a' => 1]),
            $this->service->payloadHash('vault.deposit', ['a' => 1]),
        );
    }

    private function userInOrganization(?Organization $organization = null): User
    {
        /** @var User $user */
        $user = User::factory()->forOrganization($organization ?? $this->organization)->create();

        return $user;
    }
}
