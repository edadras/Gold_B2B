<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Validators;

use App\Modules\Shared\ValueObjects\NumericInput;

/**
 * Iranian legal-entity national id (شناسه ملی اشخاص حقوقی) — 11 digits.
 *
 * Algorithm verbatim from docs/03-domain/01-identity-kyc.md §1.4.
 */
final class LegalIdValidator
{
    /** Positional coefficients defined by the national registry spec. */
    private const COEFFICIENTS = [29, 27, 23, 19, 17, 29, 27, 23, 19, 17];

    private function __construct() {}

    public static function normalize(string $id): ?string
    {
        $digits = preg_replace('/\D/', '', NumericInput::normalizeDigits($id)) ?? '';

        if ($digits === '' || strlen($digits) > 11) {
            return null;
        }

        return str_pad($digits, 11, '0', STR_PAD_LEFT);
    }

    public static function isValid(string $id): bool
    {
        $id = self::normalize($id);

        if ($id === null) {
            return false;
        }

        if (preg_match('/^\d{11}$/', $id) !== 1) {
            return false;
        }

        // The 10th digit ("decimal") drives the offset; 5 is never issued.
        $decimal = (int) $id[9];
        if ($decimal === 5) {
            return false;
        }

        $prefix = (int) substr($id, 0, 10);
        if ($prefix === 0) {
            return false;
        }

        $base = $decimal + 2;
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (((int) $id[$i]) + $base) * self::COEFFICIENTS[$i];
        }

        $rem = $sum % 11;
        $rem = $rem === 10 ? 0 : $rem;

        return $rem === (int) $id[10];
    }

    public static function mask(string $id): string
    {
        $id = self::normalize($id);

        if ($id === null) {
            return '•••••••••••';
        }

        return substr($id, 0, 3).str_repeat('•', 7).substr($id, -1);
    }
}
