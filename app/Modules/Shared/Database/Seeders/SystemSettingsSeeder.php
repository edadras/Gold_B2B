<?php

declare(strict_types=1);

namespace App\Modules\Shared\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Runtime-editable business parameters.
 *
 * These are the values an operator changes without a deploy. Their defaults
 * come from docs/04-data/02-schema-mysql.md §2.7; the fee and penalty figures
 * there are illustrative and must be set commercially before going live.
 */
final class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['market.open_time', '09:00', 'string', 'ساعت بازگشایی بازار'],
            ['market.close_time', '17:30', 'string', 'ساعت بسته شدن بازار'],
            ['market.pre_open_time', '08:45', 'string', 'شروع پیش‌گشایش'],
            ['settlement.default_deadline_hours', 8, 'int', 'مهلت پیش‌فرض تسویه'],
            ['settlement.overdue_grace_minutes', 0, 'int', 'مهلت ارفاقی پس از سررسید'],
            ['settlement.penalty_daily_x100k', 50, 'int', 'جریمه تأخیر روزانه (۰.۰۵٪)'],
            ['settlement.penalty_cap_x100k', 10000, 'int', 'سقف جریمه (۱۰٪)'],
            ['fee.default_taker_x100k', 150, 'int', 'کارمزد taker (۰.۱۵٪)'],
            ['fee.default_maker_x100k', 100, 'int', 'کارمزد maker (۰.۱۰٪)'],
            ['risk.new_member_max_order_mg', 2_000_000, 'int', 'سقف سفارش عضو جدید'],
            ['risk.new_member_daily_mg', 10_000_000, 'int', 'سقف حجم روزانه عضو جدید'],
            ['pricing.max_source_staleness_s', 300, 'int', 'حداکثر کهنگی قیمت مرجع'],
            ['pricing.circuit_breaker_bps', 300, 'int', 'آستانه توقف بازار (۳٪)'],
            ['pricing.max_order_deviation_bps', 1000, 'int', 'حداکثر انحراف قیمت سفارش'],
            ['dispute.reply_deadline_hours', 24, 'int', 'مهلت پاسخ به اختلاف'],
            ['dispute.negotiation_hours', 48, 'int', 'مهلت مذاکره'],
        ];

        foreach ($settings as [$key, $value, $type, $description]) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                    'value_type' => $type,
                    'description' => $description,
                    'updated_at' => now(),
                ],
            );
        }
    }
}
