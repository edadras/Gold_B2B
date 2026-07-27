<?php

declare(strict_types=1);

namespace App\Modules\Admin\Tests\Feature;

use App\Modules\Admin\Application\ManualAdjustmentService;
use App\Modules\Admin\Domain\AdjustmentAsset;
use App\Modules\Admin\Domain\AdjustmentStatus;
use App\Modules\Admin\Domain\OffsetAccount;
use App\Modules\Admin\Infrastructure\Models\LedgerAdjustmentRequest;
use App\Modules\Admin\Tests\AdminTestCase;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Infrastructure\Models\Organization;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The dual-controlled manual ledger adjustment (§1.10).
 *
 * The screen creates a request; only a second person with a different role can
 * turn it into ledger entries. Each refusal below corresponds to one line of
 * the control, and the happy path asserts the shape of what lands in the
 * ledger: exactly one balanced pair, in one transaction group, audited.
 */
final class ManualLedgerAdjustmentTest extends AdminTestCase
{
    use RefreshDatabase;

    private const GOOD_REASON = 'اختلاف ری‌گیری شمش شماره ۸۸۲۳۱ پس از آزمایش مجدد آزمایشگاه مرجع، طبق گزارش پیوست و تأیید کارشناس فنی.';

    #[Test]
    public function the_form_creates_a_request_and_posts_nothing_to_the_ledger(): void
    {
        $this->seedRoles();
        $member = $this->memberWithAccounts();
        $maker = $this->staffUser([Role::SETTLEMENT_OFFICER]);

        $entriesBefore = DB::table('ledger_entries')->count();

        $request = $this->service()->request(
            makerUserId: (int) $maker->id,
            organizationId: (int) $member->id,
            asset: AdjustmentAsset::GOLD,
            amount: -5_000,
            offset: OffsetAccount::ASSAY_VARIANCE,
            reason: self::GOOD_REASON,
            documentPath: 'admin/ledger-adjustments/report.pdf',
        );

        $this->assertSame(AdjustmentStatus::PENDING_APPROVAL, $request->status);
        $this->assertSame(
            $entriesBefore,
            DB::table('ledger_entries')->count(),
            'Raising a request must not touch the ledger.',
        );
    }

    #[Test]
    public function a_reason_shorter_than_fifty_characters_is_refused(): void
    {
        $this->seedRoles();
        $member = $this->memberWithAccounts();
        $maker = $this->staffUser([Role::SETTLEMENT_OFFICER]);

        $this->expectException(DomainException::class);

        $this->service()->request(
            makerUserId: (int) $maker->id,
            organizationId: (int) $member->id,
            asset: AdjustmentAsset::GOLD,
            amount: -5_000,
            offset: OffsetAccount::ASSAY_VARIANCE,
            reason: 'اشتباه بود',
            documentPath: 'admin/ledger-adjustments/report.pdf',
        );
    }

    #[Test]
    public function a_request_without_a_supporting_document_is_refused(): void
    {
        $this->seedRoles();
        $member = $this->memberWithAccounts();
        $maker = $this->staffUser([Role::SETTLEMENT_OFFICER]);

        $this->expectException(DomainException::class);

        $this->service()->request(
            makerUserId: (int) $maker->id,
            organizationId: (int) $member->id,
            asset: AdjustmentAsset::GOLD,
            amount: -5_000,
            offset: OffsetAccount::ASSAY_VARIANCE,
            reason: self::GOOD_REASON,
            documentPath: '   ',
        );
    }

    #[Test]
    public function a_user_who_is_not_a_settlement_officer_cannot_raise_a_request(): void
    {
        $this->seedRoles();
        $member = $this->memberWithAccounts();
        $supportAgent = $this->staffUser([Role::SUPPORT_AGENT]);

        $this->expectException(DomainException::class);

        $this->service()->request(
            makerUserId: (int) $supportAgent->id,
            organizationId: (int) $member->id,
            asset: AdjustmentAsset::GOLD,
            amount: -5_000,
            offset: OffsetAccount::ASSAY_VARIANCE,
            reason: self::GOOD_REASON,
            documentPath: 'admin/ledger-adjustments/report.pdf',
        );
    }

    #[Test]
    public function the_same_user_cannot_be_both_maker_and_checker(): void
    {
        $this->seedRoles();
        $member = $this->memberWithAccounts();

        // Deliberately a user holding BOTH roles: the refusal must come from
        // "same person", not from "lacks the checker role".
        $both = $this->staffUser([Role::SETTLEMENT_OFFICER, Role::PLATFORM_ADMIN]);

        $request = $this->service()->request(
            makerUserId: (int) $both->id,
            organizationId: (int) $member->id,
            asset: AdjustmentAsset::GOLD,
            amount: -5_000,
            offset: OffsetAccount::ASSAY_VARIANCE,
            reason: self::GOOD_REASON,
            documentPath: 'admin/ledger-adjustments/report.pdf',
        );

        try {
            $this->service()->approveAndPost((int) $request->id, (int) $both->id, 'تأیید می‌کنم.');
            $this->fail('A user approved their own adjustment request.');
        } catch (DomainException) {
            // expected
        }

        $this->assertSame(
            AdjustmentStatus::PENDING_APPROVAL,
            $request->refresh()->status,
            'A refused approval must leave the request pending.',
        );
        $this->assertSame(0, DB::table('ledger_entries')->where('entry_type', 'MANUAL_ADJUSTMENT')->count());
    }

    #[Test]
    public function a_checker_without_the_platform_admin_role_is_refused(): void
    {
        $this->seedRoles();
        $member = $this->memberWithAccounts();
        $maker = $this->staffUser([Role::SETTLEMENT_OFFICER]);
        $otherOfficer = $this->staffUser([Role::SETTLEMENT_OFFICER]);

        $request = $this->pendingRequest($maker->id, (int) $member->id);

        $this->expectException(DomainException::class);

        $this->service()->approveAndPost((int) $request->id, (int) $otherOfficer->id, 'تأیید می‌کنم.');
    }

    #[Test]
    public function approval_without_a_note_is_refused(): void
    {
        $this->seedRoles();
        $member = $this->memberWithAccounts();
        $maker = $this->staffUser([Role::SETTLEMENT_OFFICER]);
        $checker = $this->staffUser([Role::PLATFORM_ADMIN]);

        $request = $this->pendingRequest($maker->id, (int) $member->id);

        $this->expectException(DomainException::class);

        $this->service()->approveAndPost((int) $request->id, (int) $checker->id, '   ');
    }

    #[Test]
    public function an_approved_request_posts_exactly_one_balanced_pair_and_is_audited(): void
    {
        $this->seedRoles();
        $member = $this->memberWithAccounts();
        $maker = $this->staffUser([Role::SETTLEMENT_OFFICER]);
        $checker = $this->staffUser([Role::PLATFORM_ADMIN]);

        $request = $this->pendingRequest($maker->id, (int) $member->id, amount: -5_000);

        $posted = $this->service()->approveAndPost(
            (int) $request->id,
            (int) $checker->id,
            'گزارش آزمایشگاه بررسی و تأیید شد.',
        );

        $this->assertSame(AdjustmentStatus::POSTED, $posted->status);
        $this->assertNotNull($posted->transaction_group);

        $entries = DB::table('ledger_entries')
            ->where('transaction_group', $posted->transaction_group)
            ->orderBy('id')
            ->get();

        // Exactly two legs, and only two.
        $this->assertCount(2, $entries);
        $this->assertSame(2, DB::table('ledger_entries')->where('entry_type', 'MANUAL_ADJUSTMENT')->count());

        // Conservation of mass: the pair nets to zero.
        $this->assertSame(0, (int) $entries->sum('amount'));
        $this->assertSame(-5_000, (int) $entries[0]->amount);
        $this->assertSame(5_000, (int) $entries[1]->amount);
        $this->assertSame('DEBIT', $entries[0]->direction);
        $this->assertSame('CREDIT', $entries[1]->direction);

        // Both legs reference the request, so the entry can be traced back to
        // the two people who authorised it.
        foreach ($entries as $entry) {
            $this->assertSame('adjustment', $entry->reference_type);
            $this->assertSame((int) $request->id, (int) $entry->reference_id);
            $this->assertSame((int) $checker->id, (int) $entry->created_by_user_id);
            $this->assertNotNull($entry->row_hash);
        }

        // The member's cached balance moved with the entry.
        $memberAccountId = (int) DB::table('ledger_accounts')
            ->where('organization_id', $member->id)
            ->where('asset_type', 'GOLD')
            ->where('bucket', 'AVAILABLE')
            ->value('id');

        $this->assertSame(
            5_000,
            (int) DB::table('ledger_balances')->where('account_id', $memberAccountId)->value('balance'),
        );

        // §1.11: every action is audited, with the note.
        $audit = DB::table('audit_logs')
            ->where('action', 'admin.ledger_adjustment.posted')
            ->where('subject_id', $request->id)
            ->first();

        $this->assertNotNull($audit, 'Posting an adjustment must write an audit row.');
        $this->assertSame('platform_staff', $audit->actor_type);
        $this->assertSame((int) $checker->id, (int) $audit->actor_id);
        $this->assertStringContainsString('گزارش آزمایشگاه', (string) $audit->metadata);
    }

    #[Test]
    public function a_rejected_request_never_reaches_the_ledger(): void
    {
        $this->seedRoles();
        $member = $this->memberWithAccounts();
        $maker = $this->staffUser([Role::SETTLEMENT_OFFICER]);
        $checker = $this->staffUser([Role::PLATFORM_ADMIN]);

        $request = $this->pendingRequest($maker->id, (int) $member->id);

        $rejected = $this->service()->reject(
            (int) $request->id,
            (int) $checker->id,
            'مستند پشتیبان ناکافی است.',
        );

        $this->assertSame(AdjustmentStatus::REJECTED, $rejected->status);
        $this->assertSame(0, DB::table('ledger_entries')->where('entry_type', 'MANUAL_ADJUSTMENT')->count());
    }

    #[Test]
    public function the_database_itself_refuses_a_row_where_maker_equals_checker(): void
    {
        $this->seedRoles();
        $member = $this->memberWithAccounts();
        $maker = $this->staffUser([Role::SETTLEMENT_OFFICER]);

        $request = $this->pendingRequest($maker->id, (int) $member->id);

        // Bypassing the service entirely — the CHECK constraint is the second
        // line of defence §1.6 asks for.
        $this->expectException(QueryException::class);

        DB::table('ledger_adjustment_requests')
            ->where('id', $request->id)
            ->update(['approved_by_user_id' => $maker->id]);
    }

    #[Test]
    public function the_form_refuses_a_short_reason_and_a_missing_document_before_anything_is_stored(): void
    {
        $this->seedRoles();
        $member = $this->memberWithAccounts();
        $maker = $this->staffUser([Role::SETTLEMENT_OFFICER]);

        $this->actingAs($maker)
            ->from(route('admin.ledger.adjustments.create'))
            ->post('/admin/ledger/adjustments', [
                'organization_id' => $member->id,
                'asset_type' => 'GOLD',
                'amount' => -5_000,
                'offset_account' => 'ASSAY_VARIANCE',
                'reason' => 'اشتباه بود',
            ])
            ->assertSessionHasErrors(['reason', 'supporting_document']);

        $this->assertDatabaseCount('ledger_adjustment_requests', 0);
    }

    #[Test]
    public function the_whole_flow_works_over_http_and_a_self_approval_comes_back_as_a_form_error(): void
    {
        $this->seedRoles();
        Storage::fake('local');

        $member = $this->memberWithAccounts();

        // A maker who also holds PLATFORM_ADMIN: the only thing standing
        // between them and their own adjustment is `maker !== checker`.
        $dualRoleMaker = $this->staffUser([Role::SETTLEMENT_OFFICER, Role::PLATFORM_ADMIN]);

        $this->actingAs($dualRoleMaker)->post('/admin/ledger/adjustments', [
            'organization_id' => $member->id,
            'asset_type' => 'GOLD',
            'amount' => -5_000,
            'offset_account' => 'ASSAY_VARIANCE',
            'reason' => self::GOOD_REASON,
            'supporting_document' => UploadedFile::fake()->create('assay-report.pdf', 12),
        ])->assertRedirect();

        $own = LedgerAdjustmentRequest::query()->latest('id')->firstOrFail();

        $this->actingAs($dualRoleMaker)
            ->from(route('admin.ledger.adjustments.show', $own->id))
            ->post('/admin/ledger/adjustments/'.$own->id.'/approve', [
                'decision_note' => 'خودم تأیید می‌کنم.',
            ])
            ->assertRedirect(route('admin.ledger.adjustments.show', $own->id))
            ->assertSessionHasErrors('domain');

        $this->assertSame(AdjustmentStatus::PENDING_APPROVAL, $own->refresh()->status);
        $this->assertSame(0, DB::table('ledger_entries')->count());

        // The supporting document was stored on the private disk, not exposed.
        Storage::disk('local')->assertExists($own->supporting_document_path);

        // A request raised by a plain settlement officer, approved by a
        // platform admin — two people, two roles — does go through.
        $maker = $this->staffUser([Role::SETTLEMENT_OFFICER]);
        $checker = $this->staffUser([Role::PLATFORM_ADMIN]);

        $this->actingAs($maker)->post('/admin/ledger/adjustments', [
            'organization_id' => $member->id,
            'asset_type' => 'GOLD',
            'amount' => -5_000,
            'offset_account' => 'ASSAY_VARIANCE',
            'reason' => self::GOOD_REASON,
            'supporting_document' => UploadedFile::fake()->create('assay-report.pdf', 12),
        ])->assertRedirect();

        $request = LedgerAdjustmentRequest::query()->latest('id')->firstOrFail();

        $this->actingAs($checker)
            ->post('/admin/ledger/adjustments/'.$request->id.'/approve', [
                'decision_note' => 'گزارش آزمایشگاه بررسی و تأیید شد.',
            ])
            ->assertRedirect(route('admin.ledger.adjustments.show', $request->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(AdjustmentStatus::POSTED, $request->refresh()->status);
        $this->assertSame(2, DB::table('ledger_entries')->count());

        // The first request is untouched by the second one being posted.
        $this->assertSame(AdjustmentStatus::PENDING_APPROVAL, $own->refresh()->status);
    }

    private function service(): ManualAdjustmentService
    {
        return app(ManualAdjustmentService::class);
    }

    private function pendingRequest(int $makerId, int $organizationId, int $amount = -5_000): LedgerAdjustmentRequest
    {
        return $this->service()->request(
            makerUserId: $makerId,
            organizationId: $organizationId,
            asset: AdjustmentAsset::GOLD,
            amount: $amount,
            offset: OffsetAccount::ASSAY_VARIANCE,
            reason: self::GOOD_REASON,
            documentPath: 'admin/ledger-adjustments/report.pdf',
        );
    }

    /**
     * A member with ledger accounts, plus the system accounts the offset leg
     * lands on. Written with the query builder rather than Ledger's provisioner
     * because Admin may not reach into Ledger's application layer — the same
     * constraint the production adapter works under.
     */
    private function memberWithAccounts(): Organization
    {
        $member = $this->memberOrganization();

        $this->createAccount((int) $member->id, 'GOLD', 'AVAILABLE', null, false);
        $this->createAccount((int) $member->id, 'RIAL', 'AVAILABLE', null, false);
        $this->createAccount(0, 'GOLD', 'AVAILABLE', 'ASSAY_VARIANCE', true);
        $this->createAccount(0, 'GOLD', 'AVAILABLE', 'SUSPENSE', true);
        $this->createAccount(0, 'RIAL', 'AVAILABLE', 'SUSPENSE', true);

        // Seed the member with gold so the debit does not go negative on an
        // account that forbids it.
        $accountId = (int) DB::table('ledger_accounts')
            ->where('organization_id', $member->id)
            ->where('asset_type', 'GOLD')
            ->value('id');

        DB::table('ledger_balances')->where('account_id', $accountId)->update(['balance' => 10_000]);

        return $member;
    }

    private function createAccount(
        int $organizationId,
        string $asset,
        string $bucket,
        ?string $code,
        bool $allowsNegative,
    ): void {
        $id = DB::table('ledger_accounts')->insertGetId([
            'organization_id' => $organizationId,
            'asset_type' => $asset,
            'metal_type' => $asset === 'GOLD' ? 'GOLD' : null,
            'bucket' => $bucket,
            'system_account_code' => $code,
            'currency' => 'IRR',
            'allows_negative' => $allowsNegative,
            'status' => 'ACTIVE',
            'created_at' => now(),
        ]);

        DB::table('ledger_balances')->insert([
            'account_id' => $id,
            'balance' => 0,
            'last_entry_id' => null,
            'entry_count' => 0,
            'version' => 0,
            'updated_at' => now(),
        ]);
    }
}
