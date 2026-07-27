<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Application\AccountProvisioner;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Events\LedgerAccountCreated;
use App\Modules\Ledger\LedgerServiceProvider;
use App\Modules\Ledger\Listeners\CreateLedgerAccountsForOrganization;
use App\Modules\Ledger\Tests\LedgerTestCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Account provisioning: the system accounts of §2.7 and the nine accounts every
 * member needs (GOLD × 4 buckets, RIAL × 5).
 */
final class ProvisioningTest extends LedgerTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_seeder_creates_every_system_account_from_the_docs(): void
    {
        $this->seedSystemAccounts();

        $rows = DB::table('ledger_accounts')->where('organization_id', 0)->get();

        // docs/04-data/02-schema-mysql.md §2.7 lists 13 rows.
        $this->assertCount(13, $rows);

        foreach (SystemAccountCode::cases() as $code) {
            $this->assertTrue(
                $rows->contains(fn (object $row): bool => $row->system_account_code === $code->value),
                "System account {$code->value} was not seeded",
            );
        }

        foreach ($rows as $row) {
            $this->assertSame(Bucket::AVAILABLE->value, $row->bucket);
            $this->assertSame(1, (int) $row->allows_negative, 'System accounts must be allowed to go negative');
            $this->assertNotNull(
                DB::table('ledger_balances')->where('account_id', $row->id)->first(),
                'Every account needs a balance row to lock',
            );
        }
    }

    #[Test]
    public function seeding_twice_creates_nothing_new(): void
    {
        $this->seedSystemAccounts();
        $this->seedSystemAccounts();

        $this->assertSame(13, DB::table('ledger_accounts')->where('organization_id', 0)->count());
    }

    #[Test]
    public function a_member_gets_four_gold_and_five_rial_accounts(): void
    {
        $this->provision(184);

        $accounts = DB::table('ledger_accounts')->where('organization_id', 184)->get();

        $this->assertCount(9, $accounts);
        $this->assertCount(4, $accounts->where('asset_type', 'GOLD'));
        $this->assertCount(5, $accounts->where('asset_type', 'RIAL'));

        $goldBuckets = $accounts->where('asset_type', 'GOLD')->pluck('bucket')->sort()->values()->all();
        $this->assertSame(['AVAILABLE', 'IN_DISPUTE', 'IN_SETTLEMENT', 'RESERVED'], $goldBuckets);

        $this->assertCount(
            1,
            $accounts->where('asset_type', 'RIAL')->where('bucket', 'PAYABLE'),
            'Only rial has a PAYABLE bucket',
        );
    }

    #[Test]
    public function only_the_payable_bucket_allows_a_negative_balance(): void
    {
        $this->provision(184);

        foreach (DB::table('ledger_accounts')->where('organization_id', 184)->get() as $account) {
            $this->assertSame(
                $account->bucket === Bucket::PAYABLE->value,
                (bool) $account->allows_negative,
                "allows_negative is wrong for {$account->asset_type}/{$account->bucket}",
            );
        }
    }

    #[Test]
    public function gold_accounts_carry_a_metal_type_and_rial_accounts_do_not(): void
    {
        $this->provision(184);

        foreach (DB::table('ledger_accounts')->where('organization_id', 184)->get() as $account) {
            $account->asset_type === 'GOLD'
                ? $this->assertSame('GOLD', $account->metal_type)
                : $this->assertNull($account->metal_type);
        }
    }

    #[Test]
    public function provisioning_the_same_organisation_twice_is_a_no_op(): void
    {
        $this->provision(184);
        $this->provision(184);

        $this->assertSame(9, DB::table('ledger_accounts')->where('organization_id', 184)->count());
    }

    /**
     * The generated uniqueness key is what actually prevents a duplicate
     * account, since MySQL would treat the NULL metal_type of a rial account as
     * distinct on the literal (org, asset, metal, bucket) key.
     */
    #[Test]
    public function the_database_refuses_a_duplicate_rial_account(): void
    {
        $this->provision(184);

        $this->expectException(QueryException::class);

        DB::table('ledger_accounts')->insert([
            'organization_id' => 184,
            'asset_type' => 'RIAL',
            'metal_type' => null,
            'bucket' => 'AVAILABLE',
            'system_account_code' => null,
            'allows_negative' => false,
            'status' => 'ACTIVE',
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function the_database_refuses_a_duplicate_gold_account(): void
    {
        $this->provision(184);

        $this->expectException(QueryException::class);

        DB::table('ledger_accounts')->insert([
            'organization_id' => 184,
            'asset_type' => 'GOLD',
            'metal_type' => 'GOLD',
            'bucket' => 'RESERVED',
            'system_account_code' => null,
            'allows_negative' => false,
            'status' => 'ACTIVE',
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function it_dispatches_ledger_account_created_for_each_new_account(): void
    {
        Event::fake([LedgerAccountCreated::class]);

        $this->provision(184);

        Event::assertDispatchedTimes(LedgerAccountCreated::class, 9);
        Event::assertDispatched(LedgerAccountCreated::class, function (LedgerAccountCreated $event): bool {
            return $event->organizationId === 184
                && $event->bucket === Bucket::PAYABLE->value
                && $event->allowsNegative === true;
        });
    }

    /**
     * Identity does not exist yet, so the listener is exercised with a stand-in
     * that has the shape the real OrganizationActivated event will have.
     */
    #[Test]
    public function the_activation_listener_provisions_a_members_accounts(): void
    {
        $listener = new CreateLedgerAccountsForOrganization($this->app->make(AccountProvisioner::class));

        $listener->handle(new class
        {
            public int $organizationId = 512;
        });

        $this->assertSame(9, DB::table('ledger_accounts')->where('organization_id', 512)->count());
        $this->assertSame(9, DB::table('ledger_balances')
            ->whereIn('account_id', DB::table('ledger_accounts')->where('organization_id', 512)->pluck('id'))
            ->count());
    }

    #[Test]
    public function the_activation_listener_ignores_an_event_without_an_organisation_id(): void
    {
        $listener = new CreateLedgerAccountsForOrganization($this->app->make(AccountProvisioner::class));

        $listener->handle(new class
        {
            public string $somethingElse = 'x';
        });

        $this->assertSame(0, DB::table('ledger_accounts')->count());
    }

    /**
     * The provider must boot whether or not Identity has shipped
     * OrganizationActivated yet: the listener map is keyed by class name and
     * guarded with class_exists, so a missing Identity module is not fatal and
     * a present one wires the listener up automatically.
     */
    #[Test]
    public function the_provider_boots_with_or_without_the_identity_module(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(LedgerServiceProvider::class));

        $event = 'App\Modules\Identity\Events\OrganizationActivated';

        $this->assertSame(
            class_exists($event),
            Event::hasListeners($event),
            'The activation listener should be registered exactly when the event class exists',
        );
    }
}
