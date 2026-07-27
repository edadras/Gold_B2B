<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain;

use App\Modules\Identity\Domain\OrganizationType;

/**
 * Document kinds and which of them are mandatory per member type —
 * docs/03-domain/01-identity-kyc.md §1.3.
 */
enum DocumentType: string
{
    // --- عضو حقیقی — individual -------------------------------------------
    case NATIONAL_CARD_FRONT = 'NATIONAL_CARD_FRONT';
    case NATIONAL_CARD_BACK = 'NATIONAL_CARD_BACK';
    case BUSINESS_LICENSE = 'BUSINESS_LICENSE';
    case SELFIE_WITH_NATIONAL_CARD = 'SELFIE_WITH_NATIONAL_CARD';
    case UNION_MEMBERSHIP_CERTIFICATE = 'UNION_MEMBERSHIP_CERTIFICATE';
    case BIRTH_CERTIFICATE_LAST_PAGE = 'BIRTH_CERTIFICATE_LAST_PAGE';
    case PREMISES_DEED_OR_LEASE = 'PREMISES_DEED_OR_LEASE';

    // --- عضو حقوقی — legal entity -----------------------------------------
    case OFFICIAL_GAZETTE_ESTABLISHMENT = 'OFFICIAL_GAZETTE_ESTABLISHMENT';
    case OFFICIAL_GAZETTE_LATEST_CHANGES = 'OFFICIAL_GAZETTE_LATEST_CHANGES';
    case ARTICLES_OF_ASSOCIATION = 'ARTICLES_OF_ASSOCIATION';
    case DIRECTORS_NATIONAL_CARDS = 'DIRECTORS_NATIONAL_CARDS';
    case SIGNATURE_CERTIFICATE = 'SIGNATURE_CERTIFICATE';
    case ECONOMIC_CODE = 'ECONOMIC_CODE';
    case REPRESENTATIVE_INTRODUCTION_LETTER = 'REPRESENTATIVE_INTRODUCTION_LETTER';

    // --- common ------------------------------------------------------------
    case BANK_ACCOUNT_PROOF = 'BANK_ACCOUNT_PROOF';
    case OTHER = 'OTHER';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Documents without which a dossier may not be SUBMITTED.
     *
     * @return list<self>
     */
    public static function requiredFor(OrganizationType $type): array
    {
        return match ($type) {
            OrganizationType::INDIVIDUAL => [
                self::NATIONAL_CARD_FRONT,
                self::NATIONAL_CARD_BACK,
                self::BUSINESS_LICENSE,
                self::SELFIE_WITH_NATIONAL_CARD,
            ],
            OrganizationType::LEGAL_ENTITY => [
                self::OFFICIAL_GAZETTE_ESTABLISHMENT,
                self::OFFICIAL_GAZETTE_LATEST_CHANGES,
                self::ARTICLES_OF_ASSOCIATION,
                self::BUSINESS_LICENSE,
                self::DIRECTORS_NATIONAL_CARDS,
                self::SIGNATURE_CERTIFICATE,
                self::ECONOMIC_CODE,
            ],
        };
    }

    public function isRequiredFor(OrganizationType $type): bool
    {
        return in_array($this, self::requiredFor($type), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NATIONAL_CARD_FRONT => 'تصویر روی کارت ملی',
            self::NATIONAL_CARD_BACK => 'تصویر پشت کارت ملی',
            self::BUSINESS_LICENSE => 'تصویر جواز کسب',
            self::SELFIE_WITH_NATIONAL_CARD => 'سلفی با کارت ملی',
            self::UNION_MEMBERSHIP_CERTIFICATE => 'گواهی عضویت اتحادیه',
            self::BIRTH_CERTIFICATE_LAST_PAGE => 'آخرین صفحه شناسنامه',
            self::PREMISES_DEED_OR_LEASE => 'سند یا اجاره‌نامه محل کسب',
            self::OFFICIAL_GAZETTE_ESTABLISHMENT => 'روزنامه رسمی تأسیس',
            self::OFFICIAL_GAZETTE_LATEST_CHANGES => 'آخرین روزنامه رسمی تغییرات',
            self::ARTICLES_OF_ASSOCIATION => 'اساسنامه',
            self::DIRECTORS_NATIONAL_CARDS => 'کارت ملی مدیرعامل و هیئت‌مدیره',
            self::SIGNATURE_CERTIFICATE => 'گواهی امضا',
            self::ECONOMIC_CODE => 'کد اقتصادی',
            self::REPRESENTATIVE_INTRODUCTION_LETTER => 'معرفی‌نامه نمایندگان مجاز',
            self::BANK_ACCOUNT_PROOF => 'مدرک حساب بانکی',
            self::OTHER => 'سایر',
        };
    }
}
