<?php

declare(strict_types=1);

namespace App\Modules\Custody;

use App\Modules\Custody\Application\LotAllocator;
use App\Modules\Custody\Contracts\AssayReaderInterface;
use App\Modules\Custody\Contracts\GoldLotRepositoryInterface;
use App\Modules\Custody\Contracts\LotAllocatorInterface;
use App\Modules\Custody\Contracts\LotDeliveryInterface;
use App\Modules\Custody\Infrastructure\Adapters\LotDeliveryService;
use App\Modules\Custody\Infrastructure\Repositories\EloquentAssayReader;
use App\Modules\Custody\Infrastructure\Repositories\EloquentGoldLotRepository;
use App\Modules\Shared\Concerns\ModuleServiceProvider;

/**
 * Wires the Custody module: migrations, contract bindings and the module's own
 * configuration (merged under goldb2b.custody so the shared config file stays
 * untouched).
 */
final class CustodyServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    /** @return array<class-string, class-string> */
    protected function bindings(): array
    {
        return [
            GoldLotRepositoryInterface::class => EloquentGoldLotRepository::class,
            AssayReaderInterface::class => EloquentAssayReader::class,
            LotAllocatorInterface::class => LotAllocator::class,
            // The write side: Custody owns lot mutation, so Custody publishes
            // it rather than leaving other modules to reach into Application/.
            LotDeliveryInterface::class => LotDeliveryService::class,
        ];
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/custody.php', 'goldb2b.custody');

        parent::register();
    }
}
