<?php

declare(strict_types=1);

namespace App\Modules\Settlement;

use App\Modules\Settlement\Console\CheckOverdueSettlementsCommand;
use App\Modules\Settlement\Contracts\LotMovementPort;
use App\Modules\Settlement\Contracts\SettlementReaderInterface;
use App\Modules\Settlement\Contracts\TradeReaderInterface;
use App\Modules\Settlement\Domain\NettingCalculator;
use App\Modules\Settlement\Infrastructure\CustodyLotMovementAdapter;
use App\Modules\Settlement\Infrastructure\EloquentSettlementReader;
use App\Modules\Settlement\Infrastructure\EloquentTradeReader;
use App\Modules\Settlement\Listeners\OpenSettlementOnTradeExecuted;
use App\Modules\Shared\Concerns\ModuleServiceProvider;

/**
 * Wires the Settlement module.
 *
 * The TradeExecuted subscription is registered by **string name**, never by
 * class reference. Trading is being built concurrently and its classes may not
 * exist when this provider boots; Laravel's event dispatcher is perfectly happy
 * to listen for an event name that nothing has fired yet, and the listener
 * reads the payload defensively. So Settlement is wired for Trading before
 * Trading arrives, and nothing breaks in the meantime.
 */
final class SettlementServiceProvider extends ModuleServiceProvider
{
    /**
     * The event Trading fires when a match becomes a trade. Referenced as a
     * string so this file has no compile-time dependency on that module.
     */
    private const TRADE_EXECUTED = 'App\Modules\Trading\Events\TradeExecuted';

    protected function modulePath(): string
    {
        return __DIR__;
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [
            SettlementReaderInterface::class => EloquentSettlementReader::class,
            TradeReaderInterface::class => EloquentTradeReader::class,
        ];
    }

    /** @return array<class-string> */
    protected function consoleCommands(): array
    {
        return [
            CheckOverdueSettlementsCommand::class,
        ];
    }

    /** @return array<string, array<class-string>> */
    protected function listeners(): array
    {
        return [
            self::TRADE_EXECUTED => [OpenSettlementOnTradeExecuted::class],
        ];
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/settlement.php', 'goldb2b.settlement');

        parent::register();

        // The netting maths is stateless and pure, so one instance is plenty.
        $this->app->singleton(NettingCalculator::class);

        // Custody publishes LotDeliveryInterface, so the default is the real
        // adapter over it: a settled settlement moves the gold_lots rows, not
        // just the ledger. NullLotMovementPort stays available for a deployment
        // that runs Settlement without Custody — name it in
        // config('goldb2b.settlement.lot_movement_port').
        $this->app->bind(LotMovementPort::class, function (): LotMovementPort {
            /** @var ?string $configured */
            $configured = config('goldb2b.settlement.lot_movement_port');

            if (is_string($configured) && $configured !== '' && class_exists($configured)) {
                /** @var LotMovementPort $port */
                $port = $this->app->make($configured);

                return $port;
            }

            return $this->app->make(CustodyLotMovementAdapter::class);
        });
    }
}
