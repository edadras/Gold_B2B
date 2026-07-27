<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Contracts\PnlFacts;
use App\Modules\Reporting\Contracts\ReportingDataSource;
use App\Modules\Reporting\Domain\DateRange;

/**
 * «صورت سود و زیان» — §9.4, with the informational block of §9.5 kept beneath
 * a line rather than mixed into it.
 *
 * The two totals the report ends on are both real and both wanted:
 *
 *   accounting profit — realised only, what the books say
 *   economic profit   — plus the change in value of what is still held
 *
 * §9.5 is explicit that showing both is the point: «معامله‌گر سود اقتصادی را
 * می‌خواهد، حسابدار سود حسابداری را». Collapsing them into one number would
 * mislead one of the two readers, and there is no way to know which.
 */
final readonly class PnlReport
{
    public function __construct(private ReportingDataSource $data) {}

    /**
     * @return array{
     *     facts: PnlFacts,
     *     statement: array<int, array{label: string, rial: int, is_total: bool}>,
     *     informational: array<int, array{label: string, rial: int, available: bool}>
     * }
     */
    public function build(int $organizationId, DateRange $range): array
    {
        $facts = $this->data->pnl($organizationId, $range);

        $statement = [
            ['label' => 'درآمد فروش طلا', 'rial' => $facts->salesRevenue, 'is_total' => false],
            ['label' => 'بهای تمام‌شده کالای فروش‌رفته', 'rial' => -$facts->costOfGoodsSold, 'is_total' => false],
            ['label' => 'سود ناخالص', 'rial' => $facts->grossProfit(), 'is_total' => true],
        ];

        foreach ($facts->operatingExpenses as $label => $amount) {
            $statement[] = ['label' => '    '.$label, 'rial' => -$amount, 'is_total' => false];
        }

        $statement[] = ['label' => 'جمع هزینه‌ها', 'rial' => -$facts->totalExpenses(), 'is_total' => true];
        $statement[] = [
            'label' => 'سود خالص عملیاتی',
            'rial' => $facts->netOperatingProfit(),
            'is_total' => true,
        ];

        return [
            'facts' => $facts,
            'statement' => $statement,
            // §9.4's «اطلاعاتی (غیر از دفتر)» — carried separately so it can
            // never be added into the statement by accident.
            'informational' => [
                [
                    'label' => 'سود تحقق‌نیافته موجودی',
                    'rial' => $facts->unrealizedRial,
                    'available' => $facts->unrealizedAvailable,
                ],
                [
                    'label' => 'ارزش روز موجودی',
                    'rial' => $facts->closingMarketValue,
                    'available' => $facts->unrealizedAvailable,
                ],
                [
                    'label' => 'جمع اقتصادی',
                    'rial' => $facts->economicProfit(),
                    'available' => $facts->unrealizedAvailable,
                ],
            ],
        ];
    }
}
