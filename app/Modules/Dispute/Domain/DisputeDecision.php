<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain;

/**
 * The seven possible outcomes of docs/03-domain/13-dispute.md §13.7.
 *
 * The reputation consequences in §13.8 are attached here rather than to the
 * ResolutionService, because who is penalised is a property of the verdict, not
 * of the code path that happens to apply it. Reputation itself is a separate
 * module and is notified by event; nothing here writes a score.
 */
enum DisputeDecision: string
{
    case CLAIM_UPHELD_FULL = 'CLAIM_UPHELD_FULL';
    case CLAIM_UPHELD_PARTIAL = 'CLAIM_UPHELD_PARTIAL';
    case CLAIM_REJECTED = 'CLAIM_REJECTED';
    case SETTLED_BY_AGREEMENT = 'SETTLED_BY_AGREEMENT';
    case WITHDRAWN = 'WITHDRAWN';
    case SPLIT_LIABILITY = 'SPLIT_LIABILITY';
    case SYSTEM_FAULT = 'SYSTEM_FAULT';

    public function label(): string
    {
        return match ($this) {
            self::CLAIM_UPHELD_FULL => 'ادعا کاملاً وارد',
            self::CLAIM_UPHELD_PARTIAL => 'ادعا جزئاً وارد',
            self::CLAIM_REJECTED => 'ادعا رد شد',
            self::SETTLED_BY_AGREEMENT => 'توافق طرفین',
            self::WITHDRAWN => 'معترض پس گرفت',
            self::SPLIT_LIABILITY => 'مسئولیت مشترک',
            self::SYSTEM_FAULT => 'خطای سامانه',
        };
    }

    /** The «اجرا» column of the §13.7 table. */
    public function execution(): string
    {
        return match ($this) {
            self::CLAIM_UPHELD_FULL => 'برگشت کامل معامله یا جبران کامل',
            self::CLAIM_UPHELD_PARTIAL => 'جبران به میزان تعیین‌شده',
            self::CLAIM_REJECTED => 'آزادسازی قفل، بدون تغییر',
            self::SETTLED_BY_AGREEMENT => 'اجرای مفاد توافق',
            self::WITHDRAWN => 'آزادسازی قفل',
            self::SPLIT_LIABILITY => 'تقسیم بار بین دو طرف',
            self::SYSTEM_FAULT => 'جبران توسط سامانه، بدون اثر بر Reputation طرفین',
        };
    }

    /** Whether the verdict can move value between the parties. */
    public function permitsAward(): bool
    {
        return match ($this) {
            self::CLAIM_REJECTED, self::WITHDRAWN => false,
            default => true,
        };
    }

    /**
     * Who loses, in reputation terms (§13.8), or null when nobody does.
     *
     * SYSTEM_FAULT deliberately penalises neither party, and a respondent who
     * accepts quickly is not punished for it — «پذیرش سریع اشتباه خود: بدون اثر
     * منفی — رفتار مطلوب تشویق می‌شود».
     */
    public function reputationLoser(): ?DisputeParty
    {
        return match ($this) {
            self::CLAIM_UPHELD_FULL, self::CLAIM_UPHELD_PARTIAL => DisputeParty::RESPONDENT,
            self::CLAIM_REJECTED => DisputeParty::CLAIMANT,
            self::SPLIT_LIABILITY => DisputeParty::BOTH,
            self::SETTLED_BY_AGREEMENT, self::WITHDRAWN, self::SYSTEM_FAULT => null,
        };
    }

    /**
     * Whether a rejected claim may additionally be marked frivolous
     * («ادعای بی‌اساس … با تشخیص سوءنیت»). Only a mediator sets that flag.
     */
    public function canBeFrivolous(): bool
    {
        return $this === self::CLAIM_REJECTED;
    }

    /** Whether the platform, not a member, funds the remedy. */
    public function isPlatformLiable(): bool
    {
        return $this === self::SYSTEM_FAULT;
    }
}
