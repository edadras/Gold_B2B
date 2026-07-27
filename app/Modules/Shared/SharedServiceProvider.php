<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Modules\Shared\Audit\AuditRecorder;
use App\Modules\Shared\Calculation\TradeValueCalculator;
use App\Modules\Shared\Concerns\ModuleServiceProvider;
use App\Modules\Shared\Http\RateLimiters;
use App\Modules\Shared\Support\SettingsRepository;

final class SharedServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
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
