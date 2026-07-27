<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Validators;

use App\Modules\Shared\ValueObjects\NumericInput;

/**
 * Iranian IBAN (شبا) — "IR" + 24 digits, validated with the ISO 13616 mod-97
 * check (docs/03-domain/01-identity-kyc.md §1.4).
 *
 * bcmath is not guaranteed to be present, so the mod-97 remainder is computed
 * by chunked long division on the digit string. This never touches float and
 * never overflows a 64-bit int.
 */
final class IbanValidator
{
    private function __construct() {}

    /**
     * Accepts "IR12 0170 ...", "ir120170...", "120170..." (26 digits without
     * the country prefix is rejected — the prefix carries meaning) and Persian
     * digits. Returns the canonical uppercase, unspaced form.
     */
    public static function normalize(string $iban): ?string
    {
        $candidate = NumericInput::normalizeDigits($iban);
        $candidate = strtoupper(preg_replace('/[\s\-_.]/', '', $candidate) ?? '');

        if ($candidate === '') {
            return null;
        }

        return $candidate;
    }

    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban);

        if ($iban === null || preg_match('/^IR\d{24}$/', $iban) !== 1) {
            return false;
        }

        // Move the first four characters to the end and map letters to numbers
        // (A=10 ... Z=35, so I=18 and R=27).
        $rearranged = substr($iban, 4).'1827'.substr($iban, 2, 2);

        return self::mod97($rearranged) === 1;
    }

    /** Format for display: "IR12 0170 0000 0010 8888 8888 01". */
    public static function format(string $iban): string
    {
        $iban = self::normalize($iban);

        if ($iban === null) {
            return '';
        }

        return trim(chunk_split($iban, 4, ' '));
    }

    /** Digits 5..7 of an Iranian IBAN identify the bank. */
    public static function bankCode(string $iban): ?string
    {
        $iban = self::normalize($iban);

        if ($iban === null || preg_match('/^IR\d{24}$/', $iban) !== 1) {
            return null;
        }

        return substr($iban, 4, 3);
    }

    /**
     * Remainder of a decimal digit string modulo 97, computed in chunks small
     * enough that the intermediate value always fits in a native int.
     */
    private static function mod97(string $digits): int
    {
        if (preg_match('/^\d+$/', $digits) !== 1) {
            return -1;
        }

        $remainder = 0;
        $length = strlen($digits);

        for ($offset = 0; $offset < $length; $offset += 7) {
            $chunk = (string) $remainder.substr($digits, $offset, 7);
            $remainder = ((int) $chunk) % 97;
        }

        return $remainder;
    }
}
