<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application;

use App\Modules\Shared\Exceptions\OperationNotPermittedException;
use App\Modules\Shared\Support\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime-editable business parameters (§1.8, «SystemSettings»): fee rates,
 * limits, market hours.
 *
 * Only the keys named in EDITABLE may be touched from the panel. An open-ended
 * key/value editor over `system_settings` is a remote-code-execution-by-config
 * hazard: a typo creates a phantom key that silently falls back to a default,
 * and a deliberate one can point the platform at a different price source.
 *
 * Every change is audited with before and after, because a fee rate that
 * changed at 02:00 with no record is indistinguishable from fraud.
 */
final class SettingsAdminService
{
    /**
     * key => [label, type, group]. Types match `system_settings.value_type`.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const EDITABLE = [
        'market.open_time' => ['ساعت بازگشایی بازار', 'string', 'market'],
        'market.close_time' => ['ساعت بسته شدن بازار', 'string', 'market'],
        'market.pre_open_time' => ['شروع پیش‌گشایش', 'string', 'market'],

        'fee.default_taker_x100k' => ['کارمزد taker (در ۱۰۰٬۰۰۰)', 'int', 'fees'],
        'fee.default_maker_x100k' => ['کارمزد maker (در ۱۰۰٬۰۰۰)', 'int', 'fees'],

        'settlement.default_deadline_hours' => ['مهلت پیش‌فرض تسویه (ساعت)', 'int', 'settlement'],
        'settlement.overdue_grace_minutes' => ['مهلت ارفاقی (دقیقه)', 'int', 'settlement'],
        'settlement.penalty_daily_x100k' => ['جریمه تأخیر روزانه (در ۱۰۰٬۰۰۰)', 'int', 'settlement'],
        'settlement.penalty_cap_x100k' => ['سقف جریمه (در ۱۰۰٬۰۰۰)', 'int', 'settlement'],

        'risk.new_member_max_order_mg' => ['سقف سفارش عضو جدید (میلی‌گرم)', 'int', 'limits'],
        'risk.new_member_daily_mg' => ['سقف حجم روزانه عضو جدید (میلی‌گرم)', 'int', 'limits'],

        'pricing.max_source_staleness_s' => ['حداکثر کهنگی قیمت مرجع (ثانیه)', 'int', 'pricing'],
        'pricing.circuit_breaker_bps' => ['آستانه توقف بازار (bps)', 'int', 'pricing'],
        'pricing.max_order_deviation_bps' => ['حداکثر انحراف قیمت سفارش (bps)', 'int', 'pricing'],

        'dispute.reply_deadline_hours' => ['مهلت پاسخ به اختلاف (ساعت)', 'int', 'dispute'],
        'dispute.negotiation_hours' => ['مهلت مذاکره (ساعت)', 'int', 'dispute'],
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AdminAuditor $auditor,
    ) {}

    /**
     * @return array<string, list<array{key: string, label: string, type: string, value: mixed, description: ?string}>>
     */
    public function grouped(): array
    {
        $descriptions = $this->descriptions();
        $out = [];

        foreach (self::EDITABLE as $key => [$label, $type, $group]) {
            $out[$group][] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'value' => $this->settings->get($key),
                'description' => $descriptions[$key] ?? null,
            ];
        }

        return $out;
    }

    public function update(string $key, string $rawValue, int $actorUserId, string $note): void
    {
        if (! array_key_exists($key, self::EDITABLE)) {
            $this->auditor->denied(
                'admin.settings.update',
                'SystemSetting',
                null,
                "key {$key} is not editable from the panel",
                $actorUserId,
            );

            throw new OperationNotPermittedException("تنظیم «{$key}» از این صفحه قابل ویرایش نیست.");
        }

        if (trim($note) === '') {
            throw new OperationNotPermittedException('تغییر تنظیمات بدون یادداشت مجاز نیست.');
        }

        [, $type] = self::EDITABLE[$key];

        $before = $this->settings->get($key);
        $value = $this->cast($key, $rawValue, $type);

        $this->settings->set($key, $value, $type, $actorUserId);

        $this->auditor->action(
            action: 'admin.settings.update',
            subjectType: 'SystemSetting',
            subjectId: null,
            note: $note,
            before: ['key' => $key, 'value' => $before],
            after: ['key' => $key, 'value' => $value],
            actorId: $actorUserId,
        );
    }

    private function cast(string $key, string $raw, string $type): int|bool|string
    {
        $raw = trim($raw);

        return match ($type) {
            'int' => $this->toInt($key, $raw),
            'bool' => in_array(mb_strtolower($raw), ['1', 'true', 'yes', 'on'], true),
            default => $raw,
        };
    }

    private function toInt(string $key, string $raw): int
    {
        // Money and limits are integers in their smallest unit. A value that is
        // not an integer is a mistake, not something to coerce quietly.
        if (! preg_match('/^-?\d+$/', $raw)) {
            throw new OperationNotPermittedException("مقدار «{$key}» باید عدد صحیح باشد.");
        }

        return (int) $raw;
    }

    /** @return array<string, string> */
    private function descriptions(): array
    {
        if (! Schema::hasTable('system_settings')) {
            return [];
        }

        $out = [];

        foreach (DB::table('system_settings')->get(['key', 'description']) as $row) {
            if ($row->description !== null) {
                $out[(string) $row->key] = (string) $row->description;
            }
        }

        return $out;
    }
}
