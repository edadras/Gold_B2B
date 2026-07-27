<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Modules\Shared\Audit\AuditRecorder;
use App\Modules\Shared\Calculation\TradeValueCalculator;
use App\Modules\Shared\Concerns\ModuleServiceProvider;
use App\Modules\Shared\Contracts\PayoutAccountDirectory;
use App\Modules\Shared\Contracts\ReferencePriceOracle;
use App\Modules\Shared\Contracts\VerificationTierDirectory;
use App\Modules\Shared\Http\RateLimiters;
use App\Modules\Shared\Support\NullPayoutAccountDirectory;
use App\Modules\Shared\Support\NullReferencePriceOracle;
use App\Modules\Shared\Support\NullVerificationTierDirectory;
use App\Modules\Shared\Support\SettingsRepository;

final class SharedServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    /**
     * Safe defaults for the ports Shared declares. Every one of these is
     * replaced by a real adapter when the owning module is present; binding a
     * null implementation here keeps a partial deployment bootable.
     *
     * @return array<class-string, class-string>
     */
    protected function bindings(): array
    {
        return [
            VerificationTierDirectory::class => NullVerificationTierDirectory::class,
            ReferencePriceOracle::class => NullReferencePriceOracle::class,
            PayoutAccountDirectory::class => NullPayoutAccountDirectory::class,
        ];
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(TradeValueCalculator::class);
        $this->app->singleton(AuditRecorder::class);
        $this->app->singleton(SettingsRepository::class);
    }

    public function boot(): void
    {
        parent::boot();

        RateLimiters::register();
    }
}
