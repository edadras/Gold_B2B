<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain;

/** `dispute_evidences.evidence_type` — docs/03-domain/13-dispute.md §13.5. */
enum EvidenceType: string
{
    case DOCUMENT = 'DOCUMENT';
    case PHOTO = 'PHOTO';
    case VIDEO = 'VIDEO';
    case ASSAY_REPORT = 'ASSAY_REPORT';
    case BANK_STATEMENT = 'BANK_STATEMENT';
    case WITNESS_STATEMENT = 'WITNESS_STATEMENT';
    case SYSTEM_LOG = 'SYSTEM_LOG';

    public function label(): string
    {
        return match ($this) {
            self::DOCUMENT => 'سند',
            self::PHOTO => 'عکس',
            self::VIDEO => 'ویدئو',
            self::ASSAY_REPORT => 'گواهی ری‌گیری',
            self::BANK_STATEMENT => 'صورت‌حساب بانکی',
            self::WITNESS_STATEMENT => 'شهادت',
            self::SYSTEM_LOG => 'لاگ سامانه',
        };
    }

    /**
     * Evidence only the platform can produce, so a member may not submit it —
     * a member-uploaded "system log" would be worth nothing as evidence.
     */
    public function isSystemGenerated(): bool
    {
        return $this === self::SYSTEM_LOG;
    }
}
