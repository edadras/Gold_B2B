<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Domain\Category;
use App\Modules\Notification\Domain\Channel;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/** §15.3 — per-user, per-category preferences, with defaults that fail safe. */
final class PreferenceServiceTest extends NotificationTestCase
{
    #[Test]
    public function a_user_with_no_row_gets_the_documented_defaults(): void
    {
        $preferences = $this->preferences->for(1, Category::SETTLEMENT);

        self::assertTrue($preferences->inApp);
        self::assertTrue($preferences->push);
        self::assertFalse($preferences->sms, 'SMS costs money — opt-in, not opt-out');
        self::assertFalse($preferences->email);
        self::assertFalse($preferences->hasQuietHours());
        self::assertSame(0, DB::table('notification_preferences')->count());
    }

    #[Test]
    public function preferences_are_stored_per_category(): void
    {
        $this->preferences->set(1, Category::TRADING, ['push' => false]);

        self::assertFalse($this->preferences->for(1, Category::TRADING)->push);
        self::assertTrue($this->preferences->for(1, Category::SETTLEMENT)->push, 'other categories untouched');
    }

    #[Test]
    public function a_channel_can_be_asked_about_directly(): void
    {
        $this->preferences->set(1, Category::ASSET, ['push' => false, 'email' => true]);

        $preferences = $this->preferences->for(1, Category::ASSET);

        self::assertFalse($preferences->allows(Channel::PUSH));
        self::assertTrue($preferences->allows(Channel::EMAIL));
        // A webhook is the organisation's integration, not a personal channel.
        self::assertTrue($preferences->allows(Channel::WEBHOOK));
    }

    #[Test]
    public function setting_everything_at_once_covers_every_category(): void
    {
        $this->preferences->setAll(1, ['push' => false]);

        foreach (Category::cases() as $category) {
            self::assertFalse($this->preferences->for(1, $category)->push, $category->value);
        }

        self::assertSame(count(Category::cases()), DB::table('notification_preferences')->count());
    }

    #[Test]
    public function half_a_quiet_window_is_refused(): void
    {
        $this->expectException(OperationNotPermittedException::class);

        // Storing only a start time would silently disable quiet hours, which
        // is the opposite of what the user just asked for.
        $this->preferences->set(1, Category::TRADING, ['quiet_hours_from' => '22:00']);
    }

    #[Test]
    public function a_repeated_update_does_not_create_a_second_row(): void
    {
        $this->preferences->set(1, Category::TRADING, ['push' => false]);
        $this->preferences->set(1, Category::TRADING, ['push' => true, 'email' => true]);

        self::assertSame(1, DB::table('notification_preferences')->count());

        $preferences = $this->preferences->for(1, Category::TRADING);

        self::assertTrue($preferences->push);
        self::assertTrue($preferences->email);
    }

    #[Test]
    public function reads_are_cached_but_a_write_invalidates_the_cache(): void
    {
        $this->preferences->for(1, Category::TRADING);

        DB::enableQueryLog();
        $this->preferences->for(1, Category::TRADING);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertSame(0, $queries, 'the settlement path must not re-read preferences per notification');

        $this->preferences->set(1, Category::TRADING, ['push' => false]);

        self::assertFalse($this->preferences->for(1, Category::TRADING)->push);
    }

    #[Test]
    public function all_categories_can_be_listed_for_the_settings_screen(): void
    {
        $all = $this->preferences->all(1);

        self::assertCount(count(Category::cases()), $all);
        self::assertArrayHasKey(Category::DISPUTE->value, $all);
        self::assertSame(1, $all[Category::DISPUTE->value]->userId);
    }
}
