<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application;

use App\Modules\Dispute\Support\JalaliDate;
use Illuminate\Support\Facades\DB;

/**
 * Case numbers in the documented form DSP-1404-00142 (§13.9).
 *
 * Platform-wide sequence within a Jalali year, not per organisation: a case has
 * two parties and belongs to neither. Uniqueness is guaranteed by the unique
 * index on `case_number`; a collision means a concurrent filing, and the caller
 * retries with the next number.
 */
final class CaseNumberGenerator
{
    private const PREFIX = 'DSP';

    public function next(): string
    {
        return $this->at(0);
    }

    public function nextAfterCollision(int $attempt): string
    {
        return $this->at($attempt);
    }

    private function at(int $offset): string
    {
        $year = JalaliDate::fromGregorianString(now()->format('Y-m-d'))->year;
        $prefix = sprintf('%s-%04d-', self::PREFIX, $year);

        $used = DB::table('disputes')
            ->where('case_number', 'like', $prefix.'%')
            ->count();

        return sprintf('%s%05d', $prefix, $used + 1 + $offset);
    }
}
