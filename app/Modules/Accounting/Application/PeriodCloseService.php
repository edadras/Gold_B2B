<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Exceptions\ClosedPeriodException;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriodModel;
use App\Modules\Shared\Support\JalaliDate;
use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opening, closing and guarding accounting periods (§9.7).
 *
 * Policy, stated once so it is not re-derived at each call site:
 *
 *  - A date with no period row is unmanaged and may be posted to. Members who
 *    never open a period should not be blocked from having books.
 *  - A date inside a CLOSED period is rejected outright. Closing means closed.
 *  - A correction for a closed date is posted into the current OPEN period
 *    instead of being rejected — that is what `postingDateFor()` is for, and
 *    it is the only sanctioned way past a closed period.
 *
 * There is no reopen(). Reopening a closed period invalidates every statement
 * already issued from it; the documented remedy is a correcting voucher.
 */
final readonly class PeriodCloseService
{
    public function __construct(private TrialBalanceService $trialBalance) {}

    /** The period covering $date, or null when the date is unmanaged. */
    public function periodFor(int $organizationId, string $date): ?AccountingPeriodModel
    {
        /** @var ?AccountingPeriodModel $period */
        $period = AccountingPeriodModel::query()
            ->where('organization_id', $organizationId)
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->orderBy('id')
            ->first();

        return $period;
    }

    public function isOpen(int $organizationId, string $date): bool
    {
        $period = $this->periodFor($organizationId, $date);

        return $period === null || $period->status === 'OPEN';
    }

    /**
     * @return ?int the period id the voucher belongs to, or null when unmanaged
     *
     * @throws ClosedPeriodException
     */
    public function assertOpen(int $organizationId, string $date): ?int
    {
        $period = $this->periodFor($organizationId, $date);

        if ($period === null) {
            return null;
        }

        if ($period->status !== 'OPEN') {
            throw new ClosedPeriodException($organizationId, $date, $period->period_code);
        }

        return (int) $period->id;
    }

    /**
     * Where a correcting voucher for $date should actually land.
     *
     * Unchanged when the date is postable; otherwise the first day of the
     * current open period, or today when the member keeps no periods.
     */
    public function postingDateFor(int $organizationId, string $date): string
    {
        if ($this->isOpen($organizationId, $date)) {
            return $date;
        }

        $current = $this->currentOpenPeriod($organizationId);
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        if ($current === null) {
            return $today;
        }

        $start = (string) $current->starts_on;
        $start = substr($start, 0, 10);

        // Prefer today when today already falls inside the open period, so the
        // correction is dated when it was actually made.
        return ($today >= $start && $today <= substr((string) $current->ends_on, 0, 10))
            ? $today
            : $start;
    }

    /** The OPEN period covering today, else the latest OPEN period. */
    public function currentOpenPeriod(int $organizationId): ?AccountingPeriodModel
    {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        /** @var ?AccountingPeriodModel $covering */
        $covering = AccountingPeriodModel::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'OPEN')
            ->where('starts_on', '<=', $today)
            ->where('ends_on', '>=', $today)
            ->first();

        if ($covering !== null) {
            return $covering;
        }

        /** @var ?AccountingPeriodModel $latest */
        $latest = AccountingPeriodModel::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'OPEN')
            ->orderByDesc('starts_on')
            ->first();

        return $latest;
    }

    /**
     * Open a period. The code is free-form (usually the Jalali year-month) so a
     * member with a non-standard fiscal calendar is not forced into ours.
     */
    public function open(
        int $organizationId,
        string $startsOn,
        string $endsOn,
        ?string $periodCode = null,
    ): AccountingPeriodModel {
        if ($endsOn < $startsOn) {
            throw new OperationNotPermittedException('Period end precedes its start');
        }

        $code = $periodCode ?? JalaliDate::fromGregorianString($startsOn)->yearMonth();

        $overlapping = AccountingPeriodModel::query()
            ->where('organization_id', $organizationId)
            ->where('starts_on', '<=', $endsOn)
            ->where('ends_on', '>=', $startsOn)
            ->exists();

        if ($overlapping) {
            throw new OperationNotPermittedException(
                "An accounting period already covers {$startsOn}..{$endsOn}"
            );
        }

        /** @var AccountingPeriodModel $period */
        $period = AccountingPeriodModel::query()->create([
            'organization_id' => $organizationId,
            'period_code' => $code,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => 'OPEN',
        ]);

        return $period;
    }

    /**
     * Close a period and snapshot its trial balance.
     *
     * The snapshot is what makes the closure meaningful: a statement reprinted
     * next year can be checked against the totals as they stood at close.
     */
    public function close(int $organizationId, string $periodCode, ?int $userId = null): AccountingPeriodModel
    {
        return DB::transaction(function () use ($organizationId, $periodCode, $userId): AccountingPeriodModel {
            /** @var ?AccountingPeriodModel $period */
            $period = AccountingPeriodModel::query()
                ->where('organization_id', $organizationId)
                ->where('period_code', $periodCode)
                ->lockForUpdate()
                ->first();

            if ($period === null) {
                throw new OperationNotPermittedException("No accounting period {$periodCode}");
            }

            if ($period->status === 'CLOSED') {
                return $period;
            }

            $balance = $this->trialBalance->build(
                $organizationId,
                substr((string) $period->starts_on, 0, 10),
                substr((string) $period->ends_on, 0, 10),
            );

            $period->update([
                'status' => 'CLOSED',
                'closed_at' => now(),
                'closed_by_user_id' => $userId,
                'closing_debit_rial' => $balance->totalDebitRial,
                'closing_credit_rial' => $balance->totalCreditRial,
                'closing_fine_mg' => $balance->totalDebitFineMg,
            ]);

            return $period;
        });
    }
}
