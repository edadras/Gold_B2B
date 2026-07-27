<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Validators;

use App\Modules\Shared\ValueObjects\NumericInput;

/**
 * Iranian mobile numbers arrive in every conceivable shape — 09121234567,
 * +98 912 123 4567, 0098912..., ۰۹۱۲۱۲۳۴۵۶۷. The database stores exactly one
 * shape, `98XXXXXXXXXX`, because `users.mobile` is the login identifier and
 * carries a UNIQUE key (docs/03-domain/01-identity-kyc.md §1.4).
 */
final class MobileNormalizer
{
    private function __construct() {}

    /** @return string|null canonical `98XXXXXXXXXX`, or null when unusable */
    public static function normalize(string $mobile): ?string
    {
        $digits = preg_replace('/\D/', '', NumericInput::normalizeDigits($mobile)) ?? '';

        if ($digits === '') {
            return null;
        }

        // Strip whichever international/trunk prefix was used. Only one is
        // removed, so "0098..." is handled by the 0098 branch and never by
        // falling through to the "0" branch.
        $national = preg_replace('/^(0098|98|0)/', '', $digits, 1) ?? '';

        return preg_match('/^9\d{9}$/', $national) === 1 ? '98'.$national : null;
    }

    public static function isValid(string $mobile): bool
    {
        return self::normalize($mobile) !== null;
    }

    /** Canonical form back to the local shape users recognise: 09121234567. */
    public static function toLocal(string $mobile): ?string
    {
        $canonical = self::normalize($mobile);

        return $canonical === null ? null : '0'.substr($canonical, 2);
    }

    /** "989121234567" -> "0912•••4567" for notifications and audit views. */
    public static function mask(string $mobile): string
    {
        $canonical = self::normalize($mobile);

        if ($canonical === null) {
            return '•••••••••••';
        }

        $local = '0'.substr($canonical, 2);

        return substr($local, 0, 4).'•••'.substr($local, -4);
    }
}
