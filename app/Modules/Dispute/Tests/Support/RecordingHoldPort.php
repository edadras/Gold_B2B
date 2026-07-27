<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Tests\Support;

use App\Modules\Dispute\Contracts\DisputeHoldPort;

/**
 * A hold port that records instead of moving anything.
 *
 * The single most important assertion in this module is "only the disputed
 * amount was locked". That is a statement about the *arguments* the ledger was
 * called with, so a recorder is a better instrument than a real ledger: it
 * fails loudly if the whole trade value is ever passed through, whereas a real
 * ledger would happily lock it.
 */
final class RecordingHoldPort implements DisputeHoldPort
{
    /** @var array<int, array{op: string, org: int, amount: int, dispute: int}> */
    public array $calls = [];

    private int $nextEntryId = 5_000;

    public function holdGold(int $organizationId, int $fineMg, int $disputeId): ?int
    {
        if ($fineMg <= 0) {
            return null;
        }

        $this->record('holdGold', $organizationId, $fineMg, $disputeId);

        return $this->nextEntryId++;
    }

    public function holdRial(int $organizationId, int $rial, int $disputeId): ?int
    {
        if ($rial <= 0) {
            return null;
        }

        $this->record('holdRial', $organizationId, $rial, $disputeId);

        return $this->nextEntryId++;
    }

    public function releaseGoldHold(int $organizationId, int $fineMg, int $disputeId, ?int $entryId): void
    {
        $this->record('releaseGoldHold', $organizationId, $fineMg, $disputeId);
    }

    public function releaseRialHold(int $organizationId, int $rial, int $disputeId, ?int $entryId): void
    {
        $this->record('releaseRialHold', $organizationId, $rial, $disputeId);
    }

    public function transferGold(int $fromOrgId, int $toOrgId, int $fineMg, int $disputeId): void
    {
        $this->calls[] = [
            'op' => 'transferGold',
            'org' => $fromOrgId,
            'to' => $toOrgId,
            'amount' => $fineMg,
            'dispute' => $disputeId,
        ];
    }

    public function transferRial(int $fromOrgId, int $toOrgId, int $rial, int $disputeId): void
    {
        $this->calls[] = [
            'op' => 'transferRial',
            'org' => $fromOrgId,
            'to' => $toOrgId,
            'amount' => $rial,
            'dispute' => $disputeId,
        ];
    }

    public function isOperational(): bool
    {
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public function callsTo(string $operation): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['op'] === $operation,
        ));
    }

    public function amountOf(string $operation): ?int
    {
        $calls = $this->callsTo($operation);

        return $calls === [] ? null : (int) $calls[0]['amount'];
    }

    public function countOf(string $operation): int
    {
        return count($this->callsTo($operation));
    }

    private function record(string $op, int $org, int $amount, int $dispute): void
    {
        $this->calls[] = ['op' => $op, 'org' => $org, 'amount' => $amount, 'dispute' => $dispute];
    }
}
