<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

use App\Modules\Reporting\Domain\DateRange;
use App\Modules\Shared\Support\IntMath;
use JsonSerializable;

/**
 * A finished flow report, and — the part that matters — its reconciliation.
 *
 * §15.7 states the rule as an obligation, not a nicety:
 *
 *   «قاعده الزامی: هر گزارش گردش باید در انتها یک بررسی تطبیق داشته باشد که
 *    نشان دهد مانده پایانی با محاسبه مستقل از دفتر کل یکی است. اگر یکی نبود،
 *    گزارش با هشدار قرمز نمایش داده می‌شود.»
 *
 * So the object carries both numbers — the closing the report's own lines add
 * up to, and an independent read of the same balance — and cannot be
 * constructed without them. A caller can print a discrepant report, but it
 * cannot print one without knowing it is discrepant: `isReconciled` is false
 * and `discrepancy` says by how much.
 *
 * Failing loudly is deliberate. A flow report that quietly prints a wrong
 * closing balance is worse than no report, because a member will act on it.
 */
final readonly class FlowReport implements JsonSerializable
{
    public function __construct(
        public int $organizationId,
        public DateRange $range,
        /** 'GOLD' (fine milligrams) or 'RIAL'. */
        public string $unit,
        public FlowFacts $facts,
        /** Closing balance read independently, from the ledger itself. */
        public int $independentClosing,
        /** False when the independent read could not be obtained at all. */
        public bool $independentReadAvailable = true,
    ) {}

    public function computedClosing(): int
    {
        return $this->facts->computedClosing();
    }

    /** Computed minus independent: zero is the only acceptable answer. */
    public function discrepancy(): int
    {
        return IntMath::sub($this->computedClosing(), $this->independentClosing);
    }

    /**
     * An unavailable independent read is NOT reconciled.
     *
     * Treating "could not check" as "checked and fine" is the exact failure the
     * §15.7 rule exists to prevent.
     */
    public function isReconciled(): bool
    {
        return $this->independentReadAvailable && $this->discrepancy() === 0;
    }

    /** What §15.7 calls «هشدار قرمز». */
    public function warning(): ?string
    {
        if ($this->isReconciled()) {
            return null;
        }

        if (! $this->independentReadAvailable) {
            return 'تطبیق انجام نشد: مانده مستقل از دفتر کل در دسترس نیست.';
        }

        return sprintf(
            'مغایرت تطبیق: مانده محاسبه‌شده %s، مانده دفتر کل %s، اختلاف %s.',
            number_format($this->computedClosing()),
            number_format($this->independentClosing),
            number_format($this->discrepancy()),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'range' => $this->range->jsonSerialize(),
            'unit' => $this->unit,
            'opening' => $this->facts->opening,
            'inflows' => $this->facts->inflows,
            'outflows' => $this->facts->outflows,
            'adjustments' => $this->facts->adjustments,
            'closing_computed' => $this->computedClosing(),
            'closing_independent' => $this->independentClosing,
            'discrepancy' => $this->discrepancy(),
            'is_reconciled' => $this->isReconciled(),
            'warning' => $this->warning(),
        ];
    }
}
