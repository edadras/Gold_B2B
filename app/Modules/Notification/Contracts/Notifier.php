<?php

declare(strict_types=1);

namespace App\Modules\Notification\Contracts;

/**
 * The one entry point other modules use to notify anybody.
 *
 * Callers name an event and pass parameters; everything else — targeting,
 * channels, quiet hours, batching, deduplication — is this module's business
 * (§15.4). A caller that could choose channels would eventually choose SMS.
 */
interface Notifier
{
    public function dispatch(NotificationSpec $spec): DispatchResult;
}
