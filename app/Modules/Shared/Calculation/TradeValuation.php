<?php

declare(strict_types=1);

namespace App\Modules\Shared\Calculation;

use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Shared\ValueObjects\Rial;
use LogicException;

/**
 * The fully-priced result of a trade. Immutable and self-checking: constructing
 * one that does not balance throws immediately.
 *
 * Invariant (F9):
 *   grossAmount == sellerNet + sellerFee + sellerTax
 *   buyerNet    == grossAmount + buyerFee + buyerTax
 */
final readonly class TradeValuation
{
    public function __construct(
        public FineWeight $fineWeight,
        public PricePerFineGram $pricePerFineGram,
        public Rial $grossAmount,
        public Rial $buyerFee,
        public Rial $sellerFee,
        public Rial $buyerTax,
        public Rial $sellerTax,
        public Rial $buyerNet,
        public Rial $sellerNet,
    ) {
        $this->assertBalanced();
    }

    /** Total platform income from this trade. */
    public function platformIncome(): Rial
    {
        return $this->buyerFee->plus($this->sellerFee);
    }

    /** Total tax withheld from this trade. */
    public function totalTax(): Rial
    {
        return $this->buyerTax->plus($this->sellerTax);
    }

    private function assertBalanced(): void
    {
        $sellerSide = $this->sellerNet->plus($this->sellerFee)->plus($this->sellerTax);

        if (! $sellerSide->equals($this->grossAmount)) {
            throw new LogicException(sprintf(
                'Seller side does not balance: gross=%d, net+fee+tax=%d',
                $this->grossAmount->amount,
                $sellerSide->amount,
            ));
        }

        $buyerSide = $this->grossAmount->plus($this->buyerFee)->plus($this->buyerTax);

        if (! $buyerSide->equals($this->buyerNet)) {
            throw new LogicException(sprintf(
                'Buyer side does not balance: buyerNet=%d, gross+fee+tax=%d',
                $this->buyerNet->amount,
                $buyerSide->amount,
            ));
        }

        // Cash-flow closure: what the buyer pays minus what the seller receives
        // must equal exactly the fees and taxes the platform retains.
        $spread = $this->buyerNet->minus($this->sellerNet);
        $retained = $this->platformIncome()->plus($this->totalTax());

        if (! $spread->equals($retained)) {
            throw new LogicException(sprintf(
                'Cash flow does not close: spread=%d, retained=%d',
                $spread->amount,
                $retained->amount,
            ));
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'fine_weight_mg' => $this->fineWeight->milligrams,
            'price_per_gram_rial' => $this->pricePerFineGram->rial,
            'gross_amount_rial' => $this->grossAmount->amount,
            'buyer_fee_rial' => $this->buyerFee->amount,
            'seller_fee_rial' => $this->sellerFee->amount,
            'buyer_tax_rial' => $this->buyerTax->amount,
            'seller_tax_rial' => $this->sellerTax->amount,
            'buyer_net_rial' => $this->buyerNet->amount,
            'seller_net_rial' => $this->sellerNet->amount,
        ];
    }
}
