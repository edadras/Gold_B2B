<?php

declare(strict_types=1);

namespace App\Modules\Kyc;

use App\Modules\Kyc\Application\DocumentService;
use App\Modules\Kyc\Console\CheckExpiringLicensesCommand;
use App\Modules\Kyc\Contracts\KycDirectory;
use App\Modules\Kyc\Infrastructure\EloquentKycDirectory;
use App\Modules\Shared\Concerns\ModuleServiceProvider;

final class KycServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bindings(): array
    {
        return [
            KycDirectory::class => EloquentKycDirectory::class,
        ];
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(DocumentService::class);
    }

    public function boot(): void
    {
        parent::boot();

    }

    protected function consoleCommands(): array
    {
        return [CheckExpiringLicensesCommand::class];
    }
}
