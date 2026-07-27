<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tests\Feature;

use App\Modules\Admin\Application\DashboardService;
use App\Modules\Admin\Tests\AdminTestCase;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The dashboard of §1.9, and specifically its ordering: a ledger discrepancy is
 * the first thing on the page and outranks every other alert.
 */
final class DashboardTest extends AdminTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_dashboard_surfaces_a_seeded_ledger_discrepancy(): void
    {
        $this->seedRoles();
        $admin = $this->staffUser([Role::PLATFORM_ADMIN]);

        $this->seedDiscrepancy();

        $dashboard = app(DashboardService::class)->build();

        $this->assertSame(1, $dashboard['discrepancyCount']);

        // First alert on the page, and at critical severity.
        $first = $dashboard['alerts'][0];
        $this->assertSame('ledger_discrepancy', $first['key']);
        $this->assertSame('critical', $first['severity']);
        $this->assertSame(1, $first['count']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('مغایرت دفتری');
    }

    #[Test]
    public function a_clean_ledger_reports_the_absence_of_discrepancies_rather_than_saying_nothing(): void
    {
        $this->seedRoles();
        $admin = $this->staffUser([Role::PLATFORM_ADMIN]);

        $dashboard = app(DashboardService::class)->build();

        $this->assertSame(0, $dashboard['discrepancyCount']);
        $this->assertSame('ok', $dashboard['alerts'][0]['severity']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('هیچ مغایرت دفتری وجود ندارد');
    }

    #[Test]
    public function overdue_and_defaulted_settlements_reach_the_alert_row(): void
    {
        $this->seedRoles();
        $this->staffUser([Role::PLATFORM_ADMIN]);

        $this->seedSettlement('OVERDUE');
        $this->seedSettlement('DEFAULTED');

        $dashboard = app(DashboardService::class)->build();

        $keys = array_column($dashboard['alerts'], 'key');

        $this->assertContains('settlements_defaulted', $keys);
        $this->assertContains('settlements_overdue', $keys);

        // A default outranks a late payment; both sit under the ledger alert.
        $this->assertSame('ledger_discrepancy', $keys[0]);
        $this->assertLessThan(
            array_search('settlements_overdue', $keys, true),
            array_search('settlements_defaulted', $keys, true),
        );
    }

    #[Test]
    public function the_work_queue_counts_reflect_seeded_rows(): void
    {
        $this->seedRoles();
        $member = $this->memberOrganization();

        DB::table('kyc_profiles')->insert([
            'organization_id' => $member->id,
            'status' => 'SUBMITTED',
            'submitted_at' => now(),
            'submission_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queues = app(DashboardService::class)->build()['queues'];

        $this->assertSame(1, $queues->kycPending);
    }

    /**
     * One account whose cached balance disagrees with the sum of its entries —
     * invariant I2 broken, which is what the dashboard must shout about.
     */
    private function seedDiscrepancy(): void
    {
        $member = $this->memberOrganization('طلافروشی مغایر');

        $accountId = DB::table('ledger_accounts')->insertGetId([
            'organization_id' => $member->id,
            'asset_type' => 'GOLD',
            'metal_type' => 'GOLD',
            'bucket' => 'AVAILABLE',
            'currency' => 'IRR',
            'allows_negative' => false,
            'status' => 'ACTIVE',
            'created_at' => now(),
        ]);

        // Cached balance says 1000; there are no entries at all to back it.
        DB::table('ledger_balances')->insert([
            'account_id' => $accountId,
            'balance' => 1_000,
            'last_entry_id' => null,
            'entry_count' => 0,
            'version' => 1,
            'updated_at' => now(),
        ]);
    }

    private function seedSettlement(string $status): void
    {
        $deliverer = $this->memberOrganization('تحویل‌دهنده '.$status);
        $receiver = $this->memberOrganization('دریافت‌کننده '.$status);

        DB::table('settlements')->insert([
            'settlement_code' => 'S-'.$status.'-'.random_int(1000, 9999),
            'trade_id' => random_int(1000, 999999),
            'settlement_type' => 'T1',
            'gold_deliverer_org_id' => $deliverer->id,
            'gold_receiver_org_id' => $receiver->id,
            'cash_payer_org_id' => $receiver->id,
            'cash_receiver_org_id' => $deliverer->id,
            'fine_weight_mg' => 1_000_000,
            'cash_amount_rial' => 5_000_000_000,
            'delivery_method' => 'VAULT_TRANSFER',
            'payment_method' => 'BANK_TRANSFER',
            'deadline_at' => now()->subHours(2),
            'overdue_since' => now()->subHour(),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
