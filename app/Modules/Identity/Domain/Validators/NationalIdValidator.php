<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Validators;

use App\Modules\Shared\ValueObjects\NumericInput;

/**
 * Iranian personal national id (کد ملی) — 10 digits with a mod-11 check digit.
 *
 * Algorithm verbatim from docs/03-domain/01-identity-kyc.md §1.4.
 */
final class NationalIdValidator
{
    private function __construct() {}

    /**
     * Strip Persian/Arabic digits, separators and whitespace, then left-pad to
     * ten characters: many Iranian ids legitimately start with zeros and get
     * truncated by spreadsheets on the way in.
     */
    public static function normalize(string $id): ?string
    {
        $digits = preg_replace('/\D/', '', NumericInput::normalizeDigits($id)) ?? '';

        if ($digits === '' || strlen($digits) > 10) {
            return null;
        }

        return str_pad($digits, 10, '0', STR_PAD_LEFT);
    }

    /** Validate an already-normalised or raw id. */
    public static function isValid(string $id): bool
    {
        $id = self::normalize($id);

        if ($id === null) {
            return false;
        }

        if (preg_match('/^\d{10}$/', $id) !== 1) {
            return false;
        }

        // Repdigits (0000000000, 1111111111, ...) satisfy the checksum but are
        // never issued.
        if (preg_match('/^(\d)\1{9}$/', $id) === 1) {
            return false;
        }

        $check = (int) $id[9];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $id[$i]) * (10 - $i);
        }
        $rem = $sum % 11;

        return $rem < 2 ? $check === $rem : $check === 11 - $rem;
    }

    /** "0084575948" -> "00•••••••8" for review screens. */
    public static function mask(string $id): string
    {
        $id = self::normalize($id);

        if ($id === null) {
            return '••••••••••';
        }

        return substr($id, 0, 2).str_repeat('•', 7).substr($id, -1);
    }
}
