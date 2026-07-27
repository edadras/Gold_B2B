<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->enum('value_type', ['string', 'int', 'bool', 'json', 'decimal']);
            $table->string('description', 500)->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('business_calendar', function (Blueprint $table) {
            $table->date('calendar_date')->primary();
            $table->string('jalali_date', 10);
            $table->boolean('is_working_day');
            $table->boolean('is_holiday')->default(false);
            $table->string('holiday_name', 191)->nullable();
            $table->time('market_opens_at')->nullable();
            $table->time('market_closes_at')->nullable();

            $table->index(['is_working_day', 'calendar_date'], 'idx_cal_working');
        });

        Schema::create('failed_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_class', 191);
            $table->string('listener_class', 191)->nullable();
            $table->json('payload');
            $table->text('error');
            $table->longText('trace')->nullable();
            $table->timestamp('failed_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();

            $table->index(['event_class', 'failed_at'], 'idx_failed_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_events');
        Schema::dropIfExists('business_calendar');
        Schema::dropIfExists('system_settings');
    }
};
