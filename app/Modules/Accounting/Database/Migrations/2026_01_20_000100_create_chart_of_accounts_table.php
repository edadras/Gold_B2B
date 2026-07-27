<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\AccountCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03-domain/09-accounting.md §9.1 — the default chart of accounts.
 *
 * Row 0 of `organization_id` holds the platform template. When a member is
 * onboarded the template is copied into their own rows so they can rename a
 * caption ("بانک" → "بانک ملت شعبه بازار") without the posting rules losing
 * the account they mean — the rules key off `code`, never off `name`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 0 = the platform-wide template every organisation is seeded from.
            $table->unsignedBigInteger('organization_id')->default(0);

            $table->string('code', 10);
            $table->string('name', 200);
            $table->string('account_type', 20);          // AccountType
            $table->string('normal_balance', 10);        // BalanceSide

            // Dual-unit accounts of §9.2 carry a fine-gold quantity as well as
            // a rial book value; only these may appear on a GOLD line set.
            $table->boolean('carries_gold_quantity')->default(false);

            $table->string('group_code', 4);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'uq_org_code');
            $table->index(['organization_id', 'account_type'], 'idx_org_type');
        });

        $now = now();
        $rows = [];

        foreach (AccountCode::cases() as $code) {
            $rows[] = [
                'organization_id' => 0,
                'code' => $code->value,
                'name' => $code->label(),
                'account_type' => $code->type()->value,
                'normal_balance' => $code->type()->normalBalance()->value,
                'carries_gold_quantity' => $code->carriesGoldQuantity(),
                'group_code' => $code->groupCode(),
                'is_active' => true,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('chart_of_accounts')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
