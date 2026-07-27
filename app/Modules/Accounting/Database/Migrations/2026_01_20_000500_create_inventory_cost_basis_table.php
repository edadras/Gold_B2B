<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weighted-average cost basis per organisation — F14/F15/F16 of
 * docs/11-appendix/01-formulas.md.
 *
 * One row per member, taken FOR UPDATE by CostBasisService before every
 * purchase or sale. Two concurrent purchases that both read the old average
 * and both write a new one would silently lose a batch of cost, which is why
 * the row lock is not optional.
 *
 * `average_cost_per_gram` is rial per fine GRAM (the formula's unit) while
 * `quantity_mg` is milligrams, so the ×1000 in F14/F15 is a unit conversion,
 * not a scaling factor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_basis', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->primary();

            $table->unsignedBigInteger('quantity_mg')->default(0);
            $table->unsignedBigInteger('total_cost_rial')->default(0);
            $table->unsignedBigInteger('average_cost_per_gram')->default(0);

            // Running totals, informational: they make a drift check cheap.
            $table->unsignedBigInteger('lifetime_bought_mg')->default(0);
            $table->unsignedBigInteger('lifetime_sold_mg')->default(0);
            $table->bigInteger('lifetime_realized_profit')->default(0);

            $table->timestamp('last_purchase_at')->nullable();
            $table->timestamp('last_sale_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_basis');
    }
};
