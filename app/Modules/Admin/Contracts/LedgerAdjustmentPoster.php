<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

use App\Modules\Admin\Domain\AdjustmentAsset;
use App\Modules\Admin\Domain\OffsetAccount;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * The one write into `ledger_entries` the admin panel may perform, and only
 * after a manual adjustment request has passed dual control.
 *
 * Posts a *pair*: `amount` on the member's AVAILABLE account and `-amount` on
 * the chosen system offset account, in one transaction group, so conservation
 * of mass (invariant I1/I4) survives the correction.
 *
 * The shipped adapter, LedgerModuleAdjustmentPoster, is pure translation: it
 * maps this module's two enums onto Ledger's and hands the work to
 * Ledger\Contracts\ManualAdjustmentPoster. The panel writes nothing itself. The
 * port survives the arrival of that contract because it is what keeps the
 * screens speaking Admin's vocabulary — and what a deployment without the
 * Ledger module would rebind.
 */
interface LedgerAdjustmentPoster
{
    /**
     * @param  int  $amount  signed; negative reduces the member's balance
     *
     * @throws DomainException when the accounts
     *                         do not exist, the account is not ACTIVE, or the resulting
     *                         balance would be negative on an account that forbids it
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
