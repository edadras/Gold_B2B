<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Console;

use App\Modules\Accounting\Application\PeriodCloseService;
use App\Modules\Accounting\Application\TrialBalanceService;
use App\Modules\Accounting\Events\AccountingPeriodClosed;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriodModel;
use Illuminate\Console\Command;

/**
 * Closes a member's accounting period after checking that its books foot.
 *
 * A period whose trial balance does not balance is refused unless --force is
 * given: closing over a discrepancy freezes the error into a snapshot that
 * every later statement is checked against.
 */
final class CloseAccountingPeriodCommand extends Command
{
    protected $signature = 'accounting:close-period
        {organization : Organisation id}
        {period : Period code, e.g. 1404-08}
        {--user= : User id performing the close}
        {--force : Close even when the trial balance does not foot}';

    protected $description = 'Close an accounting period and snapshot its trial balance';

    public function handle(PeriodCloseService $periods, TrialBalanceService $trialBalance): int
    {
        $organizationId = (int) $this->argument('organization');
        $periodCode = (string) $this->argument('period');

        /** @var ?AccountingPeriodModel $existing */
        $existing = AccountingPeriodModel::query()
            ->where('organization_id', $organizationId)
            ->where('period_code', $periodCode)
            ->first();

        if ($existing === null) {
            $this->error("No accounting period {$periodCode} for organisation {$organizationId}");

            return self::FAILURE;
        }

        $balance = $trialBalance->build(
            $organizationId,
            substr((string) $existing->starts_on, 0, 10),
            substr((string) $existing->ends_on, 0, 10),
        );

        $this->line(sprintf(
            'Trial balance %s..%s — debit %d, credit %d, gold %d/%d',
            $balance->fromDate,
            $balance->toDate,
            $balance->totalDebitRial,
            $balance->totalCreditRial,
            $balance->totalDebitFineMg,
            $balance->totalCreditFineMg,
        ));

        if (! $balance->isBalanced() && ! (bool) $this->option('force')) {
            $this->error(sprintf(
                'Refusing to close: rial difference %d, gold difference %d. Use --force to override.',
                $balance->rialDifference(),
                $balance->goldDifference(),
            ));

            return self::FAILURE;
        }

        $userOption = $this->option('user');
        $closed = $periods->close(
            $organizationId,
            $periodCode,
            $userOption === null ? null : (int) $userOption,
        );

        event(new AccountingPeriodClosed(
            organizationId: $organizationId,
            periodCode: $periodCode,
            startsOn: substr((string) $closed->starts_on, 0, 10),
            endsOn: substr((string) $closed->ends_on, 0, 10),
            closingDebitRial: (int) $closed->closing_debit_rial,
            closingCreditRial: (int) $closed->closing_credit_rial,
            closingFineMg: (int) $closed->closing_fine_mg,
        ));

        $this->info("Period {$periodCode} closed for organisation {$organizationId}.");

        return self::SUCCESS;
    }
}
