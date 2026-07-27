<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tests\Feature;

use App\Modules\Admin\Http\Middleware\IdleSessionTimeout;
use App\Modules\Admin\Tests\AdminTestCase;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every screen renders with real rows in it.
 *
 * A panel whose queries are right and whose templates are broken is still a
 * broken panel, and Blade errors only show up when a view is actually
 * rendered — so each screen gets seeded data and a request.
 */
final class AdminScreensTest extends AdminTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_settlement_monitor_renders_each_tab_and_the_event_timeline(): void
    {
        $this->seedRoles();
        $admin = $this->staffUser([Role::PLATFORM_ADMIN]);

        $settlementId = $this->seedSettlement('OVERDUE');

        DB::table('settlement_events')->insert([
            'settlement_id' => $settlementId,
            'from_status' => 'PAYMENT_PENDING',
            'to_status' => 'OVERDUE',
            'actor_type' => 'SYSTEM',
            'reason' => 'گذشت مهلت تسویه',
            'occurred_at' => now(),
        ]);

        foreach (['open', 'overdue', 'defaulted', 'disputed'] as $tab) {
            $this->actingAs($admin)
                ->get('/admin/settlements?tab='.$tab)
                ->assertOk();
        }

        $this->actingAs($admin)
            ->get('/admin/settlements/'.$settlementId)
            ->assertOk()
            ->assertSee('گذشت مهلت تسویه')
            ->assertSee('خط زمانی رویدادها');
    }

    #[Test]
    public function the_reconciliation_screen_shows_a_discrepancy_and_its_account_rebuild(): void
    {
        $this->seedRoles();
        $admin = $this->staffUser([Role::PLATFORM_ADMIN]);
        $member = $this->memberOrganization();

        $accountId = DB::table('ledger_accounts')->insertGetId([
            'organization_id' => $member->id,
            'asset_type' => 'RIAL',
            'bucket' => 'AVAILABLE',
            'currency' => 'IRR',
            'allows_negative' => false,
            'status' => 'ACTIVE',
            'created_at' => now(),
        ]);

        DB::table('ledger_balances')->insert([
            'account_id' => $accountId,
            'balance' => 250_000,
            'last_entry_id' => null,
            'entry_count' => 0,
            'version' => 1,
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/ledger/reconciliation')
            ->assertOk()
            ->assertSee('دفتر تراز نیست')
            ->assertSee('مغایرت مانده');

        $this->actingAs($admin)
            ->get('/admin/ledger/accounts/'.$accountId)
            ->assertOk()
            ->assertSee('مغایر');

        $this->actingAs($admin)
            ->get('/admin/ledger/reconciliation/organization?organization_id='.$member->id)
            ->assertOk();
    }

    #[Test]
    public function the_aml_queue_and_case_file_render_with_investigation_context(): void
    {
        $this->seedRoles();
        $officer = $this->staffUser([Role::COMPLIANCE_OFFICER]);
        $member = $this->memberOrganization('طلافروشی تحت بررسی');

        $flagId = DB::table('aml_flags')->insertGetId([
            'rule_code' => 'STRUCTURING_01',
            'organization_id' => $member->id,
            'severity' => 'CRITICAL',
            'status' => 'OPEN',
            'summary' => 'الگوی خردکردن معاملات در سه روز متوالی',
            'context' => json_encode(['window_days' => 3], JSON_UNESCAPED_UNICODE),
            'raised_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($officer)
            ->get('/admin/aml')
            ->assertOk()
            ->assertSee('الگوی خردکردن');

        $this->actingAs($officer)
            ->get('/admin/aml/'.$flagId)
            ->assertOk()
            ->assertSee('زمینه بررسی');

        // Opening a case reveals a member's trading pattern — audited.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.view_sensitive',
            'subject_type' => 'AmlFlag',
            'subject_id' => $flagId,
        ]);

        $this->actingAs($officer)
            ->post('/admin/aml/'.$flagId.'/decision', [
                'status' => 'FALSE_POSITIVE',
                'notes' => 'الگو ناشی از تسویه دوره‌ای با یک طرف ثابت است و توجیه تجاری دارد.',
            ])
            ->assertRedirect(route('admin.aml.index'));

        $this->assertSame('FALSE_POSITIVE', DB::table('aml_flags')->where('id', $flagId)->value('status'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.aml.decision', 'subject_id' => $flagId]);
    }

    #[Test]
    public function an_aml_decision_without_a_note_is_refused(): void
    {
        $this->seedRoles();
        $officer = $this->staffUser([Role::COMPLIANCE_OFFICER]);
        $member = $this->memberOrganization();

        $flagId = DB::table('aml_flags')->insertGetId([
            'rule_code' => 'VELOCITY_02',
            'organization_id' => $member->id,
            'severity' => 'HIGH',
            'status' => 'OPEN',
            'summary' => 'سرعت غیرعادی معاملات',
            'context' => json_encode(['window_hours' => 6], JSON_UNESCAPED_UNICODE),
            'raised_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($officer)
            ->post('/admin/aml/'.$flagId.'/decision', ['status' => 'CLEARED', 'notes' => ''])
            ->assertSessionHasErrors('notes');

        $this->assertSame('OPEN', DB::table('aml_flags')->where('id', $flagId)->value('status'));
    }

    #[Test]
    public function the_dispute_queue_and_case_file_render(): void
    {
        $this->seedRoles();
        $admin = $this->staffUser([Role::PLATFORM_ADMIN]);
        $mediator = $this->staffUser([Role::SUPPORT_AGENT]);

        $claimant = $this->memberOrganization('خواهان');
        $respondent = $this->memberOrganization('خوانده');

        $disputeId = DB::table('disputes')->insertGetId([
            'case_number' => 'D-1404-0001',
            'dispute_type' => 'ASSAY_MISMATCH',
            'claimant_org_id' => $claimant->id,
            'respondent_org_id' => $respondent->id,
            'opened_by_user_id' => $admin->id,
            'claim_description' => 'عیار تحویلی با گواهی ری‌گیری هم‌خوان نیست.',
            'claim_gold_mg' => 250_000,
            'claim_rial' => 0,
            'status' => 'UNDER_MEDIATION',
            'priority' => 'HIGH',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get('/admin/disputes')->assertOk()->assertSee('D-1404-0001');
        $this->actingAs($admin)->get('/admin/disputes/'.$disputeId)->assertOk()->assertSee('خط زمانی');

        $this->actingAs($admin)
            ->post('/admin/disputes/'.$disputeId.'/mediator', [
                'mediator_user_id' => $mediator->id,
                'note' => 'پرونده به کارشناس میانجی ارجاع شد.',
            ])
            ->assertRedirect(route('admin.disputes.show', $disputeId));

        $this->assertSame(
            (int) $mediator->id,
            (int) DB::table('disputes')->where('id', $disputeId)->value('mediator_user_id'),
        );
        $this->assertDatabaseHas('dispute_timeline', ['dispute_id' => $disputeId, 'action' => 'mediator.assigned']);
    }

    #[Test]
    public function the_vault_inventory_renders(): void
    {
        $this->seedRoles();
        $admin = $this->staffUser([Role::PLATFORM_ADMIN]);

        $vaultId = DB::table('vaults')->insertGetId([
            'vault_code' => 'V-01',
            'name' => 'خزانه مرکزی',
            'address' => 'تهران',
            'capacity_fine_mg' => 500_000_000,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get('/admin/vaults')->assertOk()->assertSee('خزانه مرکزی');
        $this->actingAs($admin)->get('/admin/vaults/'.$vaultId)->assertOk()->assertSee('تطبیق فیزیکی');
    }

    #[Test]
    public function organization_management_lists_members_and_suspends_one_with_a_reason(): void
    {
        $this->seedRoles();
        $admin = $this->staffUser([Role::PLATFORM_ADMIN]);
        $member = $this->memberOrganization('طلافروشی متخلف');

        $this->actingAs($admin)->get('/admin/organizations')->assertOk()->assertSee('طلافروشی متخلف');
        $this->actingAs($admin)->get('/admin/organizations/'.$member->id)->assertOk();

        // A status change without a reason is refused before anything moves.
        $this->actingAs($admin)
            ->post('/admin/organizations/'.$member->id.'/status', ['status' => 'SUSPENDED', 'reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame('ACTIVE', DB::table('organizations')->where('id', $member->id)->value('status'));

        $this->actingAs($admin)
            ->post('/admin/organizations/'.$member->id.'/status', [
                'status' => 'SUSPENDED',
                'reason' => 'گزارش پلیس فتا درباره حساب‌های اجاره‌ای این عضو.',
            ])
            ->assertRedirect(route('admin.organizations.show', $member->id));

        $this->assertSame('SUSPENDED', DB::table('organizations')->where('id', $member->id)->value('status'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.organization.suspended',
            'subject_id' => $member->id,
        ]);
    }

    #[Test]
    public function the_audit_viewer_searches_by_actor_subject_and_action(): void
    {
        $this->seedRoles();
        $auditor = $this->staffUser([Role::AUDITOR]);
        $member = $this->memberOrganization();

        // Produce a real audit row through a real action.
        $admin = $this->staffUser([Role::PLATFORM_ADMIN]);
        $this->actingAs($admin)->post('/admin/organizations/'.$member->id.'/status', [
            'status' => 'RESTRICTED',
            'reason' => 'مدارک KYC منقضی شده و تمدید نشده است.',
        ]);

        $this->actingAs($auditor)->get('/admin/audit')->assertOk();

        $this->actingAs($auditor)
            ->get('/admin/audit?action=admin.organization')
            ->assertOk()
            ->assertSee('admin.organization.restricted');

        $this->actingAs($auditor)
            ->get('/admin/audit?actor_id='.$admin->id)
            ->assertOk()
            ->assertSee('admin.organization.restricted');

        $this->actingAs($auditor)
            ->get('/admin/audit?subject_type=Organization&subject_id='.$member->id)
            ->assertOk()
            ->assertSee('admin.organization.restricted');

        // A filter that matches nothing says so rather than showing everything.
        $this->actingAs($auditor)
            ->get('/admin/audit?actor_id=999999')
            ->assertOk()
            ->assertSee('رکوردی برای نمایش نیست.');
    }

    #[Test]
    public function settings_are_editable_and_every_change_is_audited(): void
    {
        $this->seedRoles();
        $admin = $this->staffUser([Role::PLATFORM_ADMIN]);

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('fee.default_taker_x100k');

        $this->actingAs($admin)
            ->post('/admin/settings', [
                'key' => 'fee.default_taker_x100k',
                'value' => '175',
                'note' => 'مصوبه هیئت‌مدیره درباره افزایش کارمزد taker.',
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertSame(
            '175',
            (string) DB::table('system_settings')->where('key', 'fee.default_taker_x100k')->value('value'),
        );

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.settings.update']);

        // A key outside the allow-list is refused by validation, not by the
        // service happening to not find it.
        $this->actingAs($admin)
            ->post('/admin/settings', ['key' => 'app.debug', 'value' => 'true', 'note' => 'تلاش برای تغییر'])
            ->assertSessionHasErrors('key');

        // A non-integer value for an integer setting is refused, and the
        // operator is told why rather than shown a 500.
        $this->actingAs($admin)
            ->from(route('admin.settings.index'))
            ->post('/admin/settings', [
                'key' => 'fee.default_taker_x100k',
                'value' => '1.75',
                'note' => 'مقدار اعشاری که نباید پذیرفته شود.',
            ])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasErrors('domain');

        $this->assertSame(
            '175',
            (string) DB::table('system_settings')->where('key', 'fee.default_taker_x100k')->value('value'),
        );
    }

    #[Test]
    public function an_idle_session_is_logged_out_after_thirty_minutes(): void
    {
        $this->seedRoles();
        $admin = $this->staffUser([Role::PLATFORM_ADMIN]);

        $this->actingAs($admin)->get('/admin')->assertOk();

        // Pretend the operator walked away.
        $this->withSession([
            'admin.last_activity_at' => time() - (IdleSessionTimeout::IDLE_SECONDS + 60),
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }

    #[Test]
    public function the_stylesheet_is_served_from_inside_the_module(): void
    {
        $response = $this->get('/admin/assets/app.css');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->assertStringContainsString('direction: rtl', $response->getContent());
    }

    private function seedSettlement(string $status): int
    {
        $deliverer = $this->memberOrganization('تحویل‌دهنده');
        $receiver = $this->memberOrganization('دریافت‌کننده');

        return (int) DB::table('settlements')->insertGetId([
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
