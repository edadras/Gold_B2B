<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application;

/**
 * Human-facing codes: ORD-00044101, TRD-00088231, RFQ-00001204, QTE-00003821,
 * OTC-00000012 — the formats used throughout docs/11-appendix/03-worked-examples.md.
 *
 * Derived from the row's auto-increment id rather than a counter of its own, so
 * a code is unique for exactly the same reason the id is and no extra sequence
 * has to be locked.
 */
final class CodeGenerator
{
    private const WIDTH = 8;

    public static function order(int $id): string
    {
        return self::format('ORD', $id);
    }

    public static function trade(int $id): string
    {
        return self::format('TRD', $id);
    }

    public static function rfq(int $id): string
    {
        return self::format('RFQ', $id);
    }

    public static function quote(int $id): string
    {
        return self::format('QTE', $id);
    }

    public static function otcOffer(int $id): string
    {
        return self::format('OTC', $id);
    }

    private static function format(string $prefix, int $id): string
    {
        return $prefix.'-'.str_pad((string) $id, self::WIDTH, '0', STR_PAD_LEFT);
    }
}
