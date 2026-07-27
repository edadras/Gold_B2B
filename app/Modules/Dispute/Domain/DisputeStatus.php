<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain;

/**
 * The dispute state machine of docs/11-appendix/02-state-machines.md §2.8.
 *
 * The appendix is authoritative here. The flow drawing in §13.3 of the domain
 * document shows two extra boxes — NO_REPLY and a DISPUTED branch — which the
 * appendix folds into a single transition each: a missed reply deadline goes
 * straight to UNDER_MEDIATION, and a rejection or partial acceptance goes to
 * NEGOTIATION. Modelling those as states would add two nodes that nothing can
 * ever leave except in one direction, so the appendix's shape is what is
 * implemented.
 *
 * §2.14 rule 2: nothing changes state without consulting allowedTransitions().
 */
enum DisputeStatus: string
{
    case OPENED = 'OPENED';
    case AWAITING_REPLY = 'AWAITING_REPLY';
    case ACCEPTED_BY_RESPONDENT = 'ACCEPTED_BY_RESPONDENT';
    case NEGOTIATION = 'NEGOTIATION';
    case UNDER_MEDIATION = 'UNDER_MEDIATION';
    case AWAITING_EVIDENCE = 'AWAITING_EVIDENCE';
    case AWAITING_REASSAY = 'AWAITING_REASSAY';
    case RESOLVED = 'RESOLVED';
    case EXECUTED = 'EXECUTED';
    case WITHDRAWN = 'WITHDRAWN';

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::OPENED => [
                self::AWAITING_REPLY,
                self::WITHDRAWN,
            ],
            self::AWAITING_REPLY => [
                self::ACCEPTED_BY_RESPONDENT,
                self::NEGOTIATION,
                self::UNDER_MEDIATION,
                self::WITHDRAWN,
            ],
            self::ACCEPTED_BY_RESPONDENT => [
                self::RESOLVED,
            ],
            self::NEGOTIATION => [
                self::RESOLVED,
                self::UNDER_MEDIATION,
                self::WITHDRAWN,
            ],
            self::UNDER_MEDIATION => [
                self::AWAITING_EVIDENCE,
                self::AWAITING_REASSAY,
                self::RESOLVED,
            ],
            self::AWAITING_EVIDENCE, self::AWAITING_REASSAY => [
                self::UNDER_MEDIATION,
            ],
            self::RESOLVED => [
                self::EXECUTED,
            ],
            self::WITHDRAWN, self::EXECUTED => [],   // نهایی
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Still consuming operator or member attention. */
    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    /** States whose clock is running, and what runs out (see §13.6). */
    public function hasDeadline(): bool
    {
        return $this === self::AWAITING_REPLY || $this === self::NEGOTIATION;
    }

    /**
     * Where an expired deadline sends the case.
     *
     * Both roads lead to the operator: silence from the respondent and failure
     * to agree are equally a signal that the parties will not settle it alone.
     */
    public function onDeadlineExpiry(): ?self
    {
        return $this->hasDeadline() ? self::UNDER_MEDIATION : null;
    }

    /** Whether the disputed amount is still held (§13.4). */
    public function holdsFunds(): bool
    {
        return $this !== self::EXECUTED && $this !== self::WITHDRAWN;
    }

    public function label(): string
    {
        return match ($this) {
            self::OPENED => 'ثبت شده',
            self::AWAITING_REPLY => 'در انتظار پاسخ',
            self::ACCEPTED_BY_RESPONDENT => 'پذیرفته‌شده توسط طرف مقابل',
            self::NEGOTIATION => 'در مذاکره',
            self::UNDER_MEDIATION => 'در میانجی‌گری',
            self::AWAITING_EVIDENCE => 'در انتظار مدرک',
            self::AWAITING_REASSAY => 'در انتظار ری‌گیری ثالث',
            self::RESOLVED => 'رأی صادر شده',
            self::EXECUTED => 'اجرا شده',
            self::WITHDRAWN => 'پس گرفته شده',
        };
    }
}
