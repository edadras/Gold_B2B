<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

use App\Modules\Ledger\Contracts\GoldLedgerInterface;
use App\Modules\Ledger\Contracts\RialLedgerInterface;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Rial;
use App\Modules\Trading\Domain\Side;

/**
 * Locks whichever asset a party owes under a set of agreed terms.
 *
 * The order book sizes its reservations itself, because a buyer's limit price
 * and its execution price differ. OTC and RFQ do not have that problem — the
 * terms are exact by the time anything is locked — so both channels share this
 * one place instead of repeating the branch on side.
 *
 * @phpstan-type Reservation array{0: LedgerEntryId, 1: int}
 */
final readonly class ObligationReserver
{
    public function __construct(
        private GoldLedgerInterface $goldLedger,
        private RialLedgerInterface $rialLedger,
        private ReservationCalculator $reservations,
    ) {}

    /**
     * @return array{0: LedgerEntryId, 1: int} the reservation entry and the amount
     *                                         locked (rial for a buyer, milligrams for a seller)
     */
    public function reserve(
        int $organizationId,
        Side $side,
        FineWeight $quantity,
        PricePerFineGram $price,
        LedgerReference $reference,
    ): array {
        if ($side->isBuy()) {
            $amount = $this->reservations->buyerRequirement($quantity, $price);

            return [$this->rialLedger->reserve($organizationId, $amount, $reference), $amount->amount];
        }

        $amount = $this->reservations->sellerRequirement($quantity);

        return [$this->goldLedger->reserve($organizationId, $amount, $reference), $amount->milligrams];
    }

    /** Hand a reservation back, whole or in part. */
    public function release(Side $side, LedgerEntryId $reservationId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $side->isBuy()
            ? $this->rialLedger->release($reservationId, Rial::fromRial($amount))
            : $this->goldLedger->release($reservationId, FineWeight::fromMilligrams($amount));
    }

    /** What a party of this side would have to lock — without locking it. */
    public function requirementFor(Side $side, FineWeight $quantity, PricePerFineGram $price): int
    {
        return $this->reservations->amountFor($side, $quantity, $price);
    }
}
