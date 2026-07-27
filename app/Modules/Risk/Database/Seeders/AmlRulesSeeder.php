<?php

declare(strict_types=1);

namespace App\Modules\Risk\Database\Seeders;

use App\Modules\Risk\Domain\AmlRuleAction;
use App\Modules\Risk\Domain\AmlRuleCategory;
use App\Modules\Risk\Domain\FlagSeverity;
use App\Modules\Risk\Infrastructure\Models\AmlRuleModel;
use Illuminate\Database\Seeder;

/**
 * The catalogue of docs/03-domain/12-aml-compliance.md §12.3 with its documented
 * default parameters.
 *
 * Rules that have no implementation class yet are seeded inactive: the row
 * documents the intent and gives compliance somewhere to tune, while the engine
 * skips it until code exists. The thresholds here are the doc's illustrative
 * values — §12 opens by warning that the real numbers must come from legal
 * counsel and current regulation.
 */
final class AmlRulesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogue() as $rule) {
            AmlRuleModel::query()->updateOrCreate(['code' => $rule['code']], $rule);
        }
    }

    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        $tradeEvents = ['TRADE_INTENT', 'TRADE_EXECUTED', 'ORDER_PLACED'];

        return [
            // ── A. Volume ────────────────────────────────────────────────────
            [
                'code' => 'VOL-01',
                'name' => 'معامله منفرد بزرگ',
                'description' => 'یک معامله با وزن بیش از آستانه اعلام‌شده.',
                'category' => AmlRuleCategory::VOLUME->value,
                'severity' => FlagSeverity::MEDIUM->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['threshold_mg' => 10_000_000],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 10,
                'is_active' => true,
            ],
            [
                'code' => 'VOL-02',
                'name' => 'حجم روزانه غیرعادی',
                'description' => 'حجم امروز بیش از ۵ برابر میانگین ۳۰ روزه خود عضو.',
                'category' => AmlRuleCategory::VOLUME->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => [
                    'multiplier' => 5,
                    'lookback_days' => 30,
                    'min_baseline_mg' => 100_000,
                ],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 20,
                'is_active' => true,
            ],
            [
                'code' => 'VOL-03',
                'name' => 'جهش ناگهانی از عضو کم‌فعالیت',
                'description' => 'افزایش ۱۰ برابری نسبت به الگوی عضو کم‌فعالیت. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::VOLUME->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::WARN->value,
                'parameters' => ['multiplier' => 10, 'lookback_days' => 30],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 30,
                'is_active' => false,
            ],
            [
                'code' => 'VOL-04',
                'name' => 'حجم بیش از ظرفیت اعلامی کسب‌وکار',
                'description' => 'حجم معاملات نسبت به اندازه اعلام‌شده واحد صنفی. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::VOLUME->value,
                'severity' => FlagSeverity::MEDIUM->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['capacity_multiplier' => 3],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 40,
                'is_active' => false,
            ],

            // ── B. Velocity ──────────────────────────────────────────────────
            [
                'code' => 'VEL-01',
                'name' => 'تعداد معامله در ساعت',
                'description' => 'بیش از ۵۰ معامله در یک ساعت.',
                'category' => AmlRuleCategory::VELOCITY->value,
                'severity' => FlagSeverity::MEDIUM->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['max_trades_per_hour' => 50, 'window_minutes' => 60],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 50,
                'is_active' => true,
            ],
            [
                'code' => 'VEL-02',
                'name' => 'معامله در ساعات غیرمعمول',
                'description' => 'معامله خارج از بازه ۰۸ تا ۲۰. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::VELOCITY->value,
                'severity' => FlagSeverity::LOW->value,
                'action' => AmlRuleAction::LOG->value,
                'parameters' => ['from_hour' => 8, 'to_hour' => 20],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 60,
                'is_active' => false,
            ],
            [
                'code' => 'VEL-03',
                'name' => 'معاملات پی‌درپی با یک طرف',
                'description' => 'بیش از ۲۰ معامله با یک طرف‌حساب در یک روز.',
                'category' => AmlRuleCategory::VELOCITY->value,
                'severity' => FlagSeverity::MEDIUM->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['max_trades_per_day' => 20],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 70,
                'is_active' => true,
            ],

            // ── C. Pattern ───────────────────────────────────────────────────
            [
                'code' => 'PAT-01',
                'name' => 'معامله رفت‌وبرگشتی',
                'description' => 'A→B سپس B→A در بازه کوتاه با حجم مشابه.',
                'category' => AmlRuleCategory::PATTERN->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['window_minutes' => 60, 'weight_tolerance_bps' => 500],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 80,
                'is_active' => true,
            ],
            [
                'code' => 'PAT-02',
                'name' => 'معامله دایره‌ای',
                'description' => 'چرخه A→B→C→A با حجم مشابه در بازه کوتاه.',
                'category' => AmlRuleCategory::PATTERN->value,
                'severity' => FlagSeverity::CRITICAL->value,
                'action' => AmlRuleAction::BLOCK->value,
                'parameters' => [
                    'window_minutes' => 120,
                    'max_depth' => 5,
                    'weight_tolerance_bps' => 500,
                ],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 90,
                'is_active' => true,
            ],
            [
                'code' => 'PAT-03',
                'name' => 'خرید و فروش فوری بدون سود',
                'description' => 'خرید و فروش همان مقدار در کمتر از ۱۰ دقیقه بدون سود معنادار.',
                'category' => AmlRuleCategory::PATTERN->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => [
                    'window_minutes' => 10,
                    'weight_tolerance_bps' => 500,
                    'max_profit_bps' => 50,
                ],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 100,
                'is_active' => true,
            ],
            [
                'code' => 'PAT-04',
                'name' => 'قیمت مکرر خارج از بازار',
                'description' => 'بیش از ۳ معامله با انحراف بیش از ۵٪ از قیمت مرجع. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::PATTERN->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['deviation_bps' => 500, 'min_trades' => 3],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 110,
                'is_active' => false,
            ],
            [
                'code' => 'PAT-05',
                'name' => 'ذوب فوری پس از خرید',
                'description' => 'ذوب در کمتر از ۲۴ ساعت پس از خرید. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::PATTERN->value,
                'severity' => FlagSeverity::MEDIUM->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['window_hours' => 24],
                'applies_to' => ['SETTLEMENT'],
                'evaluation_order' => 120,
                'is_active' => false,
            ],

            // ── D. Structuring ───────────────────────────────────────────────
            [
                'code' => 'STR-01',
                'name' => 'چند معامله کوچک زیر آستانه',
                'description' => 'حداقل ۵ معامله در روز، هرکدام ۹۰ تا ۹۹ درصد آستانه.',
                'category' => AmlRuleCategory::STRUCTURING->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => [
                    'threshold_mg' => 10_000_000,
                    'min_count' => 5,
                    'lower_bound_bps' => 9_000,
                    'upper_bound_bps' => 9_900,
                ],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 130,
                'is_active' => true,
            ],
            [
                'code' => 'STR-02',
                'name' => 'مجموع روزانه با طرف واحد بالای آستانه',
                'description' => 'مجموع معاملات کوچک با یک طرف از آستانه عبور می‌کند. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::STRUCTURING->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['threshold_mg' => 10_000_000],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 140,
                'is_active' => false,
            ],
            [
                'code' => 'STR-03',
                'name' => 'برداشت‌های متعدد کوچک',
                'description' => 'الگوی تقسیم‌بندی در برداشت‌ها. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::STRUCTURING->value,
                'severity' => FlagSeverity::MEDIUM->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['threshold_rial' => 10_000_000_000, 'min_count' => 5],
                'applies_to' => ['WITHDRAWAL'],
                'evaluation_order' => 150,
                'is_active' => false,
            ],

            // ── E. Counterparty ──────────────────────────────────────────────
            [
                'code' => 'CPT-01',
                'name' => 'معامله با عضو دارای پرچم فعال',
                'description' => 'طرف‌حساب پرچم باز با شدت متوسط یا بالاتر دارد.',
                'category' => AmlRuleCategory::COUNTERPARTY->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['min_severity' => 'MEDIUM'],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 160,
                'is_active' => true,
            ],
            [
                'code' => 'CPT-02',
                'name' => 'تمرکز بیش از ۸۰٪ حجم روی یک طرف',
                'description' => 'تمرکز حجم روی یک طرف‌حساب. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::COUNTERPARTY->value,
                'severity' => FlagSeverity::MEDIUM->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['concentration_bps' => 8_000, 'lookback_days' => 30],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 170,
                'is_active' => false,
            ],
            [
                'code' => 'CPT-03',
                'name' => 'طرف‌حساب جدید با حجم بسیار بالا',
                'description' => 'اولین معامله با یک طرف جدید و حجم بسیار بالا. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::COUNTERPARTY->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['threshold_mg' => 5_000_000],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 180,
                'is_active' => false,
            ],
            [
                'code' => 'CPT-04',
                'name' => 'تلاش برای Self-Trade',
                'description' => 'خریدار و فروشنده یک عضو هستند.',
                'category' => AmlRuleCategory::COUNTERPARTY->value,
                'severity' => FlagSeverity::CRITICAL->value,
                'action' => AmlRuleAction::BLOCK->value,
                'parameters' => [],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 5,
                'is_active' => true,
            ],

            // ── F. Behavioural ───────────────────────────────────────────────
            [
                'code' => 'BEH-01',
                'name' => 'ورود از موقعیت جغرافیایی غیرمعمول',
                'description' => 'ورود از موقعیتی خارج از الگوی عضو. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::BEHAVIORAL->value,
                'severity' => FlagSeverity::MEDIUM->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => [],
                'applies_to' => [],
                'evaluation_order' => 190,
                'is_active' => false,
            ],
            [
                'code' => 'BEH-02',
                'name' => 'تغییر ناگهانی الگوی ساعات فعالیت',
                'description' => 'تغییر محسوس در ساعات فعالیت عضو. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::BEHAVIORAL->value,
                'severity' => FlagSeverity::LOW->value,
                'action' => AmlRuleAction::LOG->value,
                'parameters' => [],
                'applies_to' => [],
                'evaluation_order' => 200,
                'is_active' => false,
            ],
            [
                'code' => 'BEH-03',
                'name' => 'تغییر حساب بانکی و سپس برداشت بزرگ',
                'description' => 'برداشت بزرگ در کمتر از ۲۴ ساعت پس از تغییر حساب بانکی.',
                'category' => AmlRuleCategory::BEHAVIORAL->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => [
                    'window_hours' => 24,
                    'min_amount_rial' => 1_000_000_000,
                    'min_fine_weight_mg' => 1_000_000,
                ],
                'applies_to' => ['WITHDRAWAL'],
                'evaluation_order' => 210,
                'is_active' => true,
            ],
            [
                'code' => 'BEH-04',
                'name' => 'چند تلاش ناموفق ورود سپس معامله بزرگ',
                'description' => 'ورودهای ناموفق پی‌درپی و سپس معامله بزرگ. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::BEHAVIORAL->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['failed_attempts' => 3, 'window_minutes' => 60],
                'applies_to' => $tradeEvents,
                'evaluation_order' => 220,
                'is_active' => false,
            ],

            // ── G. Sanctions ─────────────────────────────────────────────────
            [
                'code' => 'SAN-01',
                'name' => 'تطابق نام با فهرست محدودشده',
                'description' => 'تطابق دقیق با فهرست محدودشده. (بدون پیاده‌سازی — نیازمند منبع فهرست)',
                'category' => AmlRuleCategory::SANCTIONS->value,
                'severity' => FlagSeverity::CRITICAL->value,
                'action' => AmlRuleAction::BLOCK->value,
                'parameters' => [],
                'applies_to' => [],
                'evaluation_order' => 1,
                'is_active' => false,
            ],
            [
                'code' => 'SAN-02',
                'name' => 'تطابق جزئی با فهرست',
                'description' => 'تطابق fuzzy با فهرست محدودشده. (بدون پیاده‌سازی)',
                'category' => AmlRuleCategory::SANCTIONS->value,
                'severity' => FlagSeverity::HIGH->value,
                'action' => AmlRuleAction::FLAG->value,
                'parameters' => ['min_similarity_bps' => 8_500],
                'applies_to' => [],
                'evaluation_order' => 2,
                'is_active' => false,
            ],
        ];
    }
}
