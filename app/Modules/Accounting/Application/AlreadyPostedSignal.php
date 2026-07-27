<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Contracts\PostingResult;
use RuntimeException;

/**
 * Internal control-flow signal, not an error.
 *
 * EventPostingService needs to roll back a transaction whose outcome is already
 * decided — the voucher existed, so the cost-basis mutation made alongside it
 * must be undone. Throwing is the only way to make Laravel roll back a closure
 * that has a perfectly good value to return.
 *
 * @internal
 */
final class AlreadyPostedSignal extends RuntimeException
{
    public function __construct(public readonly PostingResult $result)
    {
        parent::__construct('Voucher already posted for this source');
    }
}
