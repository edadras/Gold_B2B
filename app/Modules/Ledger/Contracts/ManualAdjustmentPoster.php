<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Contracts;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * The one way to correct a balance by hand
 * (docs/03-domain/03-ledger.md and docs/08-frontend-web/01-web-panels.md §1.10).
 *
 * Published because the admin panel needs it and the alternative is worse. A
 * caller outside this module cannot be trusted to reproduce a ledger write —
 * the lock ordering, the running-balance carry, the hash chain, the Σ=0
 * assertion — and a second implementation that gets the hash chain subtly wrong
 * does not fail at write time. It fails months later, in the nightly chain
 * verification, over a row nobody can now explain.
 *
 * So: one leg on the member's AVAILABLE account, one equal and opposite leg on
 * a system offset account, in one transaction group, through the same writer
 * every trade uses.
 *
 * WHAT THIS CONTRACT DOES NOT DO is decide whether the adjustment is allowed.
 * Dual control — maker ≠ checker, the reason, the supporting document, the two
 * distinct roles — is the caller's, because it is a matter of who is asking and
 * this module does not know. By the time post() is called the decision has been
 * made and audited; all that is left is to write it down correctly.
 */
interface ManualAdjustmentPoster
{
    /**
     * @param  int  $signedAmount  milligrams or rial; negative reduces the member's balance
     * @param  int  $adjustmentRequestId  the approved request, recorded as the entries' reference
     *
     * @throws DomainException when the amount is zero, the offset account does not
     *                         exist for that asset, or the member's balance would go negative
     */
    public function post(
        int $organizationId,
        AssetType $asset,
        int $signedAmount,
        SystemAccountCode $offset,
        int $adjustmentRequestId,
        string $description,
        int $postedByUserId,
    ): PostedAdjustment;
}
