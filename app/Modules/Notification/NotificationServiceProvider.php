<?php

declare(strict_types=1);

namespace App\Modules\Notification;

use App\Modules\Notification\Application\ChannelRegistry;
use App\Modules\Notification\Application\NotificationDispatcher;
use App\Modules\Notification\Application\PreferenceService;
use App\Modules\Notification\Console\PruneNotificationsCommand;
use App\Modules\Notification\Contracts\Notifier;
use App\Modules\Notification\Contracts\RecipientDirectory;
use App\Modules\Notification\Infrastructure\DatabaseRecipientDirectory;
use App\Modules\Notification\Listeners\NotifyOnDomainEvent;
use App\Modules\Notification\Listeners\NotifyOnTierChange;
use App\Modules\Shared\Concerns\ModuleServiceProvider;

final class NotificationServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/notification.php', 'goldb2b.notification');

        parent::register();

        $this->app->singleton(PreferenceService::class);
        $this->app->singleton(ChannelRegistry::class);
        $this->app->singleton(NotificationDispatcher::class);
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [
            Notifier::class => NotificationDispatcher::class,
            // Swap for ArrayRecipientDirectory (or any other implementation) in a
            // deployment slice without Identity; nothing else moves.
            RecipientDirectory::class => DatabaseRecipientDirectory::class,
        ];
    }

    /** @return array<class-string> */
    protected function consoleCommands(): array
    {
        return [
            PruneNotificationsCommand::class,
        ];
    }

    /**
     * Every producing module is named as a string: Notification sits at the
     * bottom of the dependency graph with Shared and Identity above it, so it
     * cannot import Trading, Settlement, Custody, Kyc, Dispute or Reputation.
     * The listeners validate each payload's shape before acting on it.
     *
     * @return array<class-string|string, array<class-string>>
     */
    protected function listeners(): array
    {
        return [
            'App\Modules\Trading\Events\OrderFilled' => [NotifyOnDomainEvent::class],
            'App\Modules\Trading\Events\OrderPartiallyFilled' => [NotifyOnDomainEvent::class],
            'App\Modules\Trading\Events\OrderRejected' => [NotifyOnDomainEvent::class],
            'App\Modules\Trading\Events\OtcOfferReceived' => [NotifyOnDomainEvent::class],
            'App\Modules\Trading\Events\RfqQuoteAccepted' => [NotifyOnDomainEvent::class],
            'App\Modules\Settlement\Events\SettlementOpened' => [NotifyOnDomainEvent::class],
            'App\Modules\Settlement\Events\PaymentRequired' => [NotifyOnDomainEvent::class],
            'App\Modules\Settlement\Events\PaymentDeclared' => [NotifyOnDomainEvent::class],
            'App\Modules\Settlement\Events\SettlementCompleted' => [NotifyOnDomainEvent::class],
            'App\Modules\Settlement\Events\SettlementOverdue' => [NotifyOnDomainEvent::class],
            'App\Modules\Settlement\Events\SettlementDefaulted' => [NotifyOnDomainEvent::class],
            'App\Modules\Custody\Events\AssayVarianceDetected' => [NotifyOnDomainEvent::class],
            'App\Modules\Kyc\Events\KycApproved' => [NotifyOnDomainEvent::class],
            'App\Modules\Dispute\Events\DisputeOpened' => [NotifyOnDomainEvent::class],
            'App\Modules\Dispute\Events\DisputeResolved' => [NotifyOnDomainEvent::class],
            'App\Modules\Reputation\Events\TierPromoted' => [NotifyOnTierChange::class],
        ];
    }
}
