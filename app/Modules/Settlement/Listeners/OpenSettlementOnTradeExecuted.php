<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Listeners;

use App\Modules\Settlement\Application\Commands\OpenSettlementCommand;
use App\Modules\Settlement\Application\OpenSettlementService;
use App\Modules\Settlement\Contracts\TradeReaderInterface;
use App\Modules\Settlement\Contracts\TradeSnapshot;
use App\Modules\Settlement\Domain\SettlementType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "معامله ≠ تسویه" — the first line of docs/03-domain/05-settlement.md. A trade
 * says the parties agreed; a settlement is what makes it real. This listener is
 * the seam between the two engines, and it is deliberately the only place
 * Settlement knows Trading exists.
 *
 * Trading is being built concurrently, so nothing here is type-hinted against
 * it: the provider registers this listener by the event's string name, the
 * event arrives as a plain object, and every field is read defensively. A
 * property Trading has not added yet falls back to the TradeReader, and then to
 * a documented default. That is the difference between a module that is late
 * and a module that is broken.
 *
 * Failures are logged rather than rethrown. A trade that could not be settled
 * automatically is an operational problem an operator resolves; letting the
 * exception escape would roll back Trading's own commit, which is not this
 * module's decision to make.
 */
final readonly class OpenSettlementOnTradeExecuted
{
    public function __construct(
        private OpenSettlementService $settlements,
        private TradeReaderInterface $trades,
    ) {}

    public function handle(object $event): void
    {
        try {
            $command = $this->toCommand($event);

            if ($command === null) {
                return;
            }

            $this->settlements->openAndAwaitPayment($command);
        } catch (Throwable $e) {
            Log::error('Failed to open a settlement for an executed trade', [
                'event' => $event::class,
                'trade_id' => $this->int($event, ['tradeId', 'trade_id', 'id']),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function toCommand(object $event): ?OpenSettlementCommand
    {
        $tradeId = $this->int($event, ['tradeId', 'trade_id', 'id']);

        if ($tradeId === null || $tradeId <= 0) {
            return null;
        }

        $snapshot = $this->trades->find($tradeId);

        $buyer = $this->int($event, ['buyerOrganizationId', 'buyer_organization_id', 'buyerOrgId'])
            ?? $snapshot?->buyerOrganizationId;
        $seller = $this->int($event, ['sellerOrganizationId', 'seller_organization_id', 'sellerOrgId'])
            ?? $snapshot?->sellerOrganizationId;
        $fineMg = $this->int($event, ['quantityFineMg', 'quantity_fine_mg', 'fineWeightMg', 'quantityMg'])
            ?? $snapshot?->quantityFineMg;
        $gross = $this->int($event, ['grossAmountRial', 'gross_amount_rial', 'cashAmountRial'])
            ?? $snapshot?->grossAmountRial;

        if ($buyer === null || $seller === null || $fineMg === null || $gross === null) {
            Log::warning('TradeExecuted did not carry enough detail to open a settlement', [
                'trade_id' => $tradeId,
                'event' => $event::class,
            ]);

            return null;
        }

        return OpenSettlementCommand::forTrade(
            tradeId: $tradeId,
            buyerOrganizationId: $buyer,
            sellerOrganizationId: $seller,
            fineWeightMg: $fineMg,
            cashAmountRial: $gross,
            buyerFeeRial: $this->int($event, ['buyerFeeRial', 'buyer_fee_rial'])
                ?? $snapshot?->buyerFeeRial ?? 0,
            sellerFeeRial: $this->int($event, ['sellerFeeRial', 'seller_fee_rial'])
                ?? $snapshot?->sellerFeeRial ?? 0,
            settlementType: $this->settlementType($event, $snapshot),
            deadlineAt: $this->deadline($event, $snapshot),
        );
    }

    private function settlementType(object $event, ?TradeSnapshot $snapshot): SettlementType
    {
        $raw = $this->string($event, ['settlementType', 'settlement_type']) ?? $snapshot?->settlementType;

        return $raw === null ? SettlementType::T0 : (SettlementType::tryFrom($raw) ?? SettlementType::T0);
    }

    private function deadline(object $event, ?TradeSnapshot $snapshot): ?CarbonImmutable
    {
        $raw = $this->string($event, ['settlementDeadline', 'settlement_deadline', 'deadlineAt'])
            ?? $snapshot?->settlementDeadline;

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Read the first property that exists and looks like an integer.
     *
     * @param  list<string>  $names
     */
    private function int(object $event, array $names): ?int
    {
        foreach ($names as $name) {
            if (! property_exists($event, $name)) {
                continue;
            }

            /** @var mixed $value */
            $value = $event->{$name};

            if (is_int($value)) {
                return $value;
            }

            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /** @param list<string> $names */
    private function string(object $event, array $names): ?string
    {
        foreach ($names as $name) {
            if (! property_exists($event, $name)) {
                continue;
            }

            /** @var mixed $value */
            $value = $event->{$name};

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
