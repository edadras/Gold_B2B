<?php

declare(strict_types=1);

namespace App\Modules\Risk;

use App\Modules\Risk\Aml\AmlRuleRegistry;
use App\Modules\Risk\Aml\Rules\BankChangeThenWithdrawalRule;
use App\Modules\Risk\Aml\Rules\CircularTradeRule;
use App\Modules\Risk\Aml\Rules\FlaggedCounterpartyRule;
use App\Modules\Risk\Aml\Rules\ImmediateFlipRule;
use App\Modules\Risk\Aml\Rules\LargeSingleTradeRule;
use App\Modules\Risk\Aml\Rules\RepeatedCounterpartyRule;
use App\Modules\Risk\Aml\Rules\RoundTripTradeRule;
use App\Modules\Risk\Aml\Rules\SelfTradeRule;
use App\Modules\Risk\Aml\Rules\StructuringRule;
use App\Modules\Risk\Aml\Rules\TradeVelocityRule;
use App\Modules\Risk\Aml\Rules\UnusualDailyVolumeRule;
use App\Modules\Risk\Application\AmlRuleEngine;
use App\Modules\Risk\Application\RiskGuard;
use App\Modules\Risk\Contracts\AmlEvaluatorInterface;
use App\Modules\Risk\Contracts\MemberActivityReaderInterface;
use App\Modules\Risk\Contracts\OrganizationStatusReaderInterface;
use App\Modules\Risk\Contracts\PenaltyCalculatorInterface;
use App\Modules\Risk\Contracts\RiskGuardInterface;
use App\Modules\Risk\Contracts\TradeHistoryReaderInterface;
use App\Modules\Risk\Contracts\TradingExposureReaderInterface;
use App\Modules\Risk\Domain\PenaltyCalculator;
use App\Modules\Risk\Infrastructure\NullMemberActivityReader;
use App\Modules\Risk\Infrastructure\NullOrganizationStatusReader;
use App\Modules\Risk\Infrastructure\NullTradeHistoryReader;
use App\Modules\Risk\Infrastructure\NullTradingExposureReader;
use App\Modules\Shared\Concerns\ModuleServiceProvider;

final class RiskServiceProvider extends ModuleServiceProvider
{
    /** The AML rules that have an implementation today. */
    private const RULE_CLASSES = [
        SelfTradeRule::class,
        LargeSingleTradeRule::class,
        UnusualDailyVolumeRule::class,
        TradeVelocityRule::class,
        RepeatedCounterpartyRule::class,
        RoundTripTradeRule::class,
        CircularTradeRule::class,
        ImmediateFlipRule::class,
        StructuringRule::class,
        FlaggedCounterpartyRule::class,
        BankChangeThenWithdrawalRule::class,
    ];

    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bindings(): array
    {
        return [
            RiskGuardInterface::class => RiskGuard::class,
            AmlEvaluatorInterface::class => AmlRuleEngine::class,
            PenaltyCalculatorInterface::class => PenaltyCalculator::class,

            // Replaced by the owning modules as they come online. Each null
            // implementation is documented with why its answer is the safe one.
            TradeHistoryReaderInterface::class => NullTradeHistoryReader::class,
            TradingExposureReaderInterface::class => NullTradingExposureReader::class,
            OrganizationStatusReaderInterface::class => NullOrganizationStatusReader::class,
            MemberActivityReaderInterface::class => NullMemberActivityReader::class,
        ];
    }

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/config.php', 'goldb2b.risk');

        $this->app->singleton(AmlRuleRegistry::class, function ($app): AmlRuleRegistry {
            $registry = new AmlRuleRegistry;

            foreach (self::RULE_CLASSES as $class) {
                $registry->register($app->make($class));
            }

            return $registry;
        });
    }
}
