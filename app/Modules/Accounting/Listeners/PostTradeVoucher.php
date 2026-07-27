<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Application\EventPostingService;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns an executed trade into a pair of vouchers: a purchase for the buyer and
 * a sale for the seller.
 *
 * Registered by STRING event name in AccountingServiceProvider::listeners(),
 * because Trading is being built concurrently and its classes may not exist.
 * Everything about the payload is therefore treated as untrusted: if the event
 * does not carry the facts a voucher needs, the listener logs and returns
 * rather than posting a voucher built from guesses. A missing voucher is a
 * fixable gap; a wrong voucher is a corrupted ledger.
 */
final readonly class PostTradeVoucher
{
    public function __construct(private EventPostingService $posting) {}

    public function handle(object $event): void
    {
        $tradeId = EventFacts::int($event, 'tradeId', 'id');
        $fineMg = EventFacts::int($event, 'fineWeightMg', 'fineMg', 'quantityMg', 'fineWeight');
        $grossRial = EventFacts::int($event, 'grossAmount', 'grossRial', 'totalRial', 'amount');

        if ($tradeId === null || $fineMg === null || $grossRial === null || $fineMg <= 0 || $grossRial <= 0) {
            $this->skip($event, 'trade event lacks id, fine weight or gross amount');

            return;
        }

        $buyerOrgId = EventFacts::int($event, 'buyerOrgId', 'buyerOrganizationId');
        $sellerOrgId = EventFacts::int($event, 'sellerOrgId', 'sellerOrganizationId');

        if ($buyerOrgId === null && $sellerOrgId === null) {
            $this->skip($event, 'trade event names neither party');

            return;
        }

        $entryDate = EventFacts::date($event, 'executedAt', 'tradedAt', 'occurredAt', 'createdAt')
            ?? (new DateTimeImmutable('today'))->format('Y-m-d');

        $buyerFee = EventFacts::intOrZero($event, 'buyerFeeRial', 'buyerFee', 'takerFee');
        $sellerFee = EventFacts::intOrZero($event, 'sellerFeeRial', 'sellerFee', 'makerFee');

        $description = EventFacts::string($event, 'description') ?? "معامله TRD-{$tradeId}";

        if ($buyerOrgId !== null) {
            $this->guard(fn () => $this->posting->postPurchase(
                organizationId: $buyerOrgId,
                tradeId: $tradeId,
                fineMg: $fineMg,
                grossRial: $grossRial,
                feeRial: $buyerFee,
                entryDate: $entryDate,
                counterpartyOrgId: $sellerOrgId,
                description: $description,
            ), $tradeId, 'purchase');
        }

        if ($sellerOrgId !== null) {
            $this->guard(fn () => $this->posting->postSale(
                organizationId: $sellerOrgId,
                tradeId: $tradeId,
                fineMg: $fineMg,
                grossRial: $grossRial,
                feeRial: $sellerFee,
                entryDate: $entryDate,
                counterpartyOrgId: $buyerOrgId,
                description: $description,
            ), $tradeId, 'sale');
        }
    }

    /**
     * A posting failure must not kill the listener for the other party, and
     * must not poison the queue: it is recorded and left for the reconciliation
     * job that compares trades against vouchers.
     *
     * @param  callable(): mixed  $post
     */
    private function guard(callable $post, int $tradeId, string $side): void
    {
        try {
            $post();
        } catch (Throwable $e) {
            Log::channel(config('logging.default'))->error('accounting: trade voucher failed', [
                'trade_id' => $tradeId,
                'side' => $side,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function skip(object $event, string $reason): void
    {
        Log::channel(config('logging.default'))->debug('accounting: trade event ignored', [
            'event' => $event::class,
            'reason' => $reason,
        ]);
    }
}
