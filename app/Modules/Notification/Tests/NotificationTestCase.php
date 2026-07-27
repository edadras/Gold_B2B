<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Application\ChannelRegistry;
use App\Modules\Notification\Application\NotificationDispatcher;
use App\Modules\Notification\Application\PreferenceService;
use App\Modules\Notification\Contracts\Recipient;
use App\Modules\Notification\Contracts\RecipientDirectory;
use App\Modules\Notification\Domain\Category;
use App\Modules\Notification\Infrastructure\ArrayRecipientDirectory;
use App\Modules\Notification\NotificationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dispatch rules are tested against an in-memory directory: they are about
 * priorities, preferences and windows, not about Identity's schema. The
 * database-backed directory has its own test.
 */
abstract class NotificationTestCase extends TestCase
{
    use RefreshDatabase;

    protected ArrayRecipientDirectory $directory;

    protected NotificationDispatcher $dispatcher;

    protected PreferenceService $preferences;

    /**
     * Registered here rather than in setUp(): RefreshDatabase migrates from
     * setUpTraits(), which runs before any afterApplicationCreated callback, so
     * a provider registered later would contribute no migrations.
     */

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = new ArrayRecipientDirectory;
        $this->app->instance(RecipientDirectory::class, $this->directory);

        $this->preferences = $this->app->make(PreferenceService::class);
        $this->dispatcher = new NotificationDispatcher(
            $this->directory,
            $this->preferences,
            $this->app->make(ChannelRegistry::class),
        );
    }

    /**
     * @param  list<string>  $roles
     */
    protected function recipient(
        int $id,
        array $roles = ['OWNER'],
        int $organizationId = 1,
        ?string $mobile = '09120000000',
        ?string $email = 'user@example.test',
        array $pushTokens = ['device-token'],
    ): Recipient {
        $recipient = new Recipient(
            id: $id,
            organizationId: $organizationId,
            roles: $roles,
            mobile: $mobile,
            email: $email,
            pushTokens: $pushTokens,
        );

        $this->directory->add($recipient);

        return $recipient;
    }

    /** @param  array<string, bool|string|null>  $settings */
    protected function setPreference(int $userId, Category $category, array $settings): void
    {
        $this->preferences->set($userId, $category, $settings);
    }
}
