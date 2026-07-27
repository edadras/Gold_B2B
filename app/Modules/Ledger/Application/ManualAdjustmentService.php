<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Contracts\GroupWriter;
use App\Modules\Ledger\Contracts\ManualAdjustmentPoster;
use App\Modules\Ledger\Contracts\PostedAdjustment;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\Exceptions\OffsetAccountMismatchException;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntryModel;
use InvalidArgumentException;

/**
 * Manual balance corrections, §1.10.
 *
 * Everything here is delegation, and that is the point. The adjustment is two
 * legs — the member and a system offset account — posted through
 * LedgerInterface::postGroup(), which means it gets the same lock ordering, the
 * same running-balance carry, the same hash chain and the same Σ=0 assertion as
 * a trade. There is no second write path to keep in step.
 *
 * The one rule enforced here rather than delegated is that the offset account
 * must be one that exists for the asset. SUSPENSE and ROUNDING_DIFFERENCE carry
 * both; ASSAY_VARIANCE and PROCESSING_LOSS are gold-only concepts with only
 * gold rows seeded, so booking a rial correction against one of them would fail
 * deep inside the group writer with an account-lookup error rather than at the
 * boundary, where the operator can still be told what to pick instead.
 *
 * Authorisation is NOT here. Whether this correction may be made — maker ≠
 * checker, the written reason, the supporting document, the two distinct roles
 * — belongs to whoever is asking, and by the time this runs that decision has
 * been made and audited.
 */
final class ManualAdjustmentService implements ManualAdjustmentPoster
{
    /** @var array<string, list<AssetType>> */
    private const OFFSET_ASSETS = [
        SystemAccountCode::SUSPENSE->value => [AssetType::GOLD, AssetType::RIAL],
        SystemAccountCode::ROUNDING_DIFFERENCE->value => [AssetType::GOLD, AssetType::RIAL],
        SystemAccountCode::ASSAY_VARIANCE->value => [AssetType::GOLD],
        SystemAccountCode::PROCESSING_LOSS->value => [AssetType::GOLD],
    ];

    public function __construct(private readonly LedgerService $ledger) {}

    public function post(
        int $organizationId,
        AssetType $asset,
        int $signedAmount,
        SystemAccountCode $offset,
        int $adjustmentRequestId,
        string $description,
        int $postedByUserId,
    ): PostedAdjustment {
        if ($signedAmount === 0) {
            throw new InvalidArgumentException('A manual adjustment of zero is not a correction.');
        }

        $this->assertOffsetSupports($offset, $asset);

        $reference = LedgerReference::adjustment($adjustmentRequestId);
        $metadata = [
            'adjustment_request_id' => $adjustmentRequestId,
            'posted_by_user_id' => $postedByUserId,
        ];

        $entryIds = [];

        // actingAs, not Auth::id(): the row must name the checker who approved
        // the request, which is the only thing that makes dual control legible
        // afterwards. The session user may be nobody at all — this can be
        // posted from a console command.
        $post = function () use (
            $organizationId,
            $asset,
            $signedAmount,
            $offset,
            $reference,
            $description,
            $metadata,
            &$entryIds,
        ) {
            return $this->ledger->postGroup(function (GroupWriter $writer) use (
                $organizationId,
                $asset,
                $signedAmount,
                $offset,
                $reference,
                $description,
                $metadata,
                &$entryIds,
            ): void {
                $member = $writer->post(
                    orgId: $organizationId,
                    asset: $asset,
                    bucket: Bucket::AVAILABLE,
                    signedAmount: $signedAmount,
                    type: EntryType::MANUAL_ADJUSTMENT,
                    ref: $reference,
                    description: $description,
                    metadata: $metadata,
                );

                // Equal and opposite, so the group nets to zero and the correction
                // is visible in the platform's own accounts rather than conjured.
                $system = $writer->postSystem(
                    code: $offset,
                    asset: $asset,
                    signedAmount: -$signedAmount,
                    type: EntryType::MANUAL_ADJUSTMENT,
                    ref: $reference,
                    description: $description,
                    metadata: $metadata,
                );

                $entryIds = [$member->value, $system->value];
            });
        };

        $group = $this->ledger->actingAs($postedByUserId, $post);

        // Read back rather than widen GroupWriter's return type: the account
        // ids are of interest to the audit screen, not to the write path, and
        // every other caller of postGroup() manages without them.
        [$memberAccountId, $offsetAccountId] = $this->accountIdsOf($entryIds);

        return new PostedAdjustment(
            transactionGroup: $group->value,
            entryIds: $entryIds,
            memberAccountId: $memberAccountId,
            offsetAccountId: $offsetAccountId,
        );
    }

    /**
     * @param  list<int>  $entryIds  [member leg, offset leg], in that order
     * @return array{0: int, 1: int}
     */
    private function accountIdsOf(array $entryIds): array
    {
        $accounts = LedgerEntryModel::query()
            ->whereIn('id', $entryIds)
            ->pluck('account_id', 'id');

        return [
            (int) ($accounts[$entryIds[0]] ?? 0),
            (int) ($accounts[$entryIds[1]] ?? 0),
        ];
    }

    private function assertOffsetSupports(SystemAccountCode $offset, AssetType $asset): void
    {
        $supported = self::OFFSET_ASSETS[$offset->value] ?? [];

        if (! in_array($asset, $supported, true)) {
            throw new OffsetAccountMismatchException($offset, $asset);
        }
    }
}
