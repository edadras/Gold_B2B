<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Shared\Support\JalaliDate;
use Illuminate\Support\Facades\DB;

/**
 * Voucher numbers: «ترتیبی به تفکیک سازمان و سال؛ بدون شکاف» (§9.7).
 *
 * Format GB-{jalali year}-{jalali month}-{sequence}, matching the sample
 * GB-1404-08-00142 in §9.6. The sequence counts within the organisation and
 * the Jalali YEAR, not the month, so it is gapless across a whole fiscal year
 * — the month in the middle is a human convenience, not part of the key.
 *
 * Gaplessness cannot come from an auto-increment (rolled-back transactions burn
 * values), so the number is derived from a count and protected by the
 * `uq_org_voucher` unique index: a racing pair collides and the loser retries.
 */
final class VoucherNumberGenerator
{
    private const PREFIX = 'GB';

    public function next(int $organizationId, string $entryDate): string
    {
        $jalali = JalaliDate::fromGregorianString($entryDate);

        return $this->format($jalali, $this->sequenceFor($organizationId, $jalali->year));
    }

    /** Next candidate after a collision: the same year, one further along. */
    public function nextAfterCollision(int $organizationId, string $entryDate, int $attempt): string
    {
        $jalali = JalaliDate::fromGregorianString($entryDate);

        return $this->format($jalali, $this->sequenceFor($organizationId, $jalali->year) + $attempt);
    }

    private function sequenceFor(int $organizationId, int $jalaliYear): int
    {
        $prefix = sprintf('%s-%04d-', self::PREFIX, $jalaliYear);

        $used = DB::table('journal_entries')
            ->where('organization_id', $organizationId)
            ->where('voucher_no', 'like', $prefix.'%')
            ->count();

        return $used + 1;
    }

    private function format(JalaliDate $jalali, int $sequence): string
    {
        return sprintf('%s-%04d-%02d-%05d', self::PREFIX, $jalali->year, $jalali->month, $sequence);
    }
}
