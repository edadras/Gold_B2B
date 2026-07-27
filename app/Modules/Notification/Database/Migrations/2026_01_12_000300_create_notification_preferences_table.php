<?php

declare(strict_types=1);

use App\Modules\Notification\Domain\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user, per-category delivery preferences (docs §15.3).
 *
 * A missing row means the defaults in PreferenceService, not "everything off" —
 * a member who never opened the settings screen must still be told that a
 * settlement deadline is two hours away.
 *
 * Quiet hours are stored as wall-clock times in the market timezone
 * (`goldb2b.market.timezone`); a member who says "not before 8am" means 8am in
 * Tehran, not 8am UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->enum('category', Category::values());

            $table->boolean('in_app')->default(true);
            $table->boolean('push')->default(true);
            $table->boolean('sms')->default(false);
            $table->boolean('email')->default(false);

            $table->time('quiet_hours_from')->nullable();
            $table->time('quiet_hours_to')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'category'], 'uq_user_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
