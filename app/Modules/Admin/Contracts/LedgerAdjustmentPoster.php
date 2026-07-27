<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

use App\Modules\Admin\Domain\AdjustmentAsset;
use App\Modules\Admin\Domain\OffsetAccount;

/**
 * The one write into `ledger_entries` the admin panel may perform, and only
 * after a manual adjustment request has passed dual control.
 *
 * Posts a *pair*: `amount` on the member's AVAILABLE account and `-amount` on
 * the chosen system offset account, in one transaction group, so conservation
 * of mass (invariant I1/I4) survives the correction.
 *
 * ⚠️ Ledger publishes no contract for this today, so the shipped adapter writes
 * the rows itself. Replacing it with a Ledger-owned implementation is a
 * one-line rebinding and is the intended end state.
 */
interface LedgerAdjustmentPoster
{
    /**
     * @param  int  $amount  signed; negative reduces the member's balance
     *
     * @throws \App\Modules\Shared\Exceptions\DomainException when the accounts
     *                                                       do not exist, the account is not ACTIVE, or the resulting
     *                                                       balance would be negative on an account that forbids it
     */
    public function post(
        int $organizationId,
        AdjustmentAsset $asset,
        int $amount,
        OffsetAccount $offset,
        int $adjustmentRequestId,
        string $description,
        int $postedByUserId,
    ): PostedAdjustment;
}
