<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Ledger;

use App\Modules\Dispute\Contracts\DisputeHoldPort;
use App\Modules\Ledger\Contracts\GroupWriter;
use App\Modules\Ledger\Contracts\LedgerInterface;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;

/**
 * The real hold: AVAILABLE → IN_DISPUTE, exactly as §13.4 specifies
 * («LedgerEntry: DISPUTE_HOLD، AVAILABLE ► IN_DISPUTE»).
 *
 * The move is written through postGroup() rather than moveBucket() for one
 * reason: the schema in §13.5 keeps `hold_gold_entry_id`, and postGroup hands
 * back the id of each leg it writes, so the entry that landed in IN_DISPUTE can
 * be recorded. moveBucket returns only the group. Both legs go into one
 * transaction group, so the ledger's Σ = 0 invariant is checked for us.
 */
final readonly class LedgerHoldAdapter implements DisputeHoldPort
{
    public function __construct(private LedgerInterface $ledger) {}

    public function holdGold(int $organizationId, int $fineMg, int $disputeId): ?int
    {
        return $this->move(
            $organizationId,
            AssetType::GOLD,
            $fineMg,
            $disputeId,
            Bucket::AVAILABLE,
            Bucket::IN_DISPUTE,
            EntryType::DISPUTE_HOLD,
            'قفل مبلغ مورد اختلاف',
        );
    }

    public function holdRial(int $organizationId, int $rial, int $disputeId): ?int
    {
        return $this->move(
            $organizationId,
            AssetType::RIAL,
            $rial,
            $disputeId,
            Bucket::AVAILABLE,
            Bucket::IN_DISPUTE,
            EntryType::DISPUTE_HOLD,
            'قفل مبلغ مورد اختلاف',
        );
    }

    public function releaseGoldHold(int $organizationId, int $fineMg, int $disputeId, ?int $entryId): void
    {
        $this->move(
            $organizationId,
            AssetType::GOLD,
            $fineMg,
            $disputeId,
            Bucket::IN_DISPUTE,
            Bucket::AVAILABLE,
            EntryType::DISPUTE_RELEASE,
            $this->releaseNote($entryId),
        );
    }

    public function releaseRialHold(int $organizationId, int $rial, int $disputeId, ?int $entryId): void
    {
        $this->move(
            $organizationId,
            AssetType::RIAL,
            $rial,
            $disputeId,
            Bucket::IN_DISPUTE,
            Bucket::AVAILABLE,
            EntryType::DISPUTE_RELEASE,
            $this->releaseNote($entryId),
        );
    }

    public function transferGold(int $fromOrgId, int $toOrgId, int $fineMg, int $disputeId): void
    {
        if ($fineMg <= 0 || $fromOrgId === $toOrgId) {
            return;
        }

        $this->ledger->transfer(
            fromOrgId: $fromOrgId,
            toOrgId: $toOrgId,
            amount: FineWeight::fromMilligrams($fineMg),
            ref: LedgerReference::dispute($disputeId),
        );
    }

    public function transferRial(int $fromOrgId, int $toOrgId, int $rial, int $disputeId): void
    {
        if ($rial <= 0 || $fromOrgId === $toOrgId) {
            return;
        }

        $this->ledger->transfer(
            fromOrgId: $fromOrgId,
            toOrgId: $toOrgId,
            amount: Rial::fromRial($rial),
            ref: LedgerReference::dispute($disputeId),
        );
    }

    public function isOperational(): bool
    {
        return true;
    }

    /** @return ?int id of the leg landing in $to */
    private function move(
        int $organizationId,
        AssetType $asset,
        int $amount,
        int $disputeId,
        Bucket $from,
        Bucket $to,
        EntryType $type,
        string $description,
    ): ?int {
        if ($amount <= 0) {
            return null;
        }

        $reference = LedgerReference::dispute($disputeId);
        $landing = null;

        $this->ledger->postGroup(
            function (GroupWriter $writer) use (
                $organizationId, $asset, $amount, $from, $to, $type, $reference, $description, &$landing
            ): void {
                $writer->post(
                    orgId: $organizationId,
                    asset: $asset,
                    bucket: $from,
                    signedAmount: -$amount,
                    type: $type,
                    ref: $reference,
                    description: $description,
                );

                /** @var LedgerEntryId $credit */
                $credit = $writer->post(
                    orgId: $organizationId,
                    asset: $asset,
                    bucket: $to,
                    signedAmount: $amount,
                    type: $type,
                    ref: $reference,
                    description: $description,
                );

                $landing = $credit->value;
            }
        );

        return $landing;
    }

    private function releaseNote(?int $entryId): string
    {
        return $entryId === null
            ? 'آزادسازی قفل اختلاف'
            : "آزادسازی قفل اختلاف (ثبت {$entryId})";
    }
}
