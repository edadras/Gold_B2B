<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Tables;

use App\Modules\Admin\Contracts\LedgerAdjustmentPoster;
use App\Modules\Admin\Contracts\PostedAdjustment;
use App\Modules\Admin\Domain\AdjustmentAsset;
use App\Modules\Admin\Domain\OffsetAccount;
use App\Modules\Ledger\Contracts\ManualAdjustmentPoster;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\SystemAccountCode;

/**
 * Translation, and nothing else.
 *
 * This replaces TableLedgerAdjustmentPoster, which reproduced
 * LedgerService::writeEntry() — the lock order, the running balance, the hash
 * chain, the Σ=0 assertion — because Ledger published no contract for a manual
 * adjustment. Ledger publishes one now, so the panel posts through the same
 * writer as every trade and there is no second implementation of the hash chain
 * to drift out of step with the nightly verification.
 *
 * What is left is mapping Admin's two enums onto Ledger's. They were declared
 * separately so the panel could describe a correction without importing the
 * ledger; the values were always the same strings, and `from()` is where a
 * divergence would now fail loudly instead of writing a row against the wrong
 * account.
 */
final class LedgerModuleAdjustmentPoster implements LedgerAdjustmentPoster
{
    public function __construct(private readonly ManualAdjustmentPoster $poster) {}

    public function post(
        int $organizationId,
        AdjustmentAsset $asset,
        int $amount,
        OffsetAccount $offset,
        int $adjustmentRequestId,
        string $description,
        int $postedByUserId,
    ): PostedAdjustment {
        $posted = $this->poster->post(
            organizationId: $organizationId,
            asset: AssetType::from($asset->value),
            signedAmount: $amount,
            offset: SystemAccountCode::from($offset->value),
            adjustmentRequestId: $adjustmentRequestId,
            description: $description,
            postedByUserId: $postedByUserId,
        );

        return new PostedAdjustment(
            transactionGroup: $posted->transactionGroup,
            entryIds: $posted->entryIds,
            memberAccountId: $posted->memberAccountId,
            offsetAccountId: $posted->offsetAccountId,
        );
    }
}
