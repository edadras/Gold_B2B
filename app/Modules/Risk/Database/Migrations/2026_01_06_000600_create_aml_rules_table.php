<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rule catalogue. docs/03-domain/12-aml-compliance.md §12.2.
 *
 * Thresholds live in `parameters` so compliance can retune a rule without a
 * deploy — §12.9 requires exactly that feedback loop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aml_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 191);
            $table->text('description');
            $table->enum('category', [
                'VOLUME', 'VELOCITY', 'PATTERN', 'STRUCTURING',
                'COUNTERPARTY', 'BEHAVIORAL', 'SANCTIONS',
            ]);
            $table->enum('severity', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']);
            $table->enum('action', ['LOG', 'FLAG', 'WARN', 'BLOCK']);
            $table->json('parameters');
            $table->json('applies_to')->comment('event types this rule reacts to');
            $table->unsignedSmallInteger('evaluation_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'evaluation_order'], 'idx_active_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aml_rules');
    }
};
