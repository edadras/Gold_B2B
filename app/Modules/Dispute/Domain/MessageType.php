<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain;

/**
 * What a message in the negotiation room is (§13.6 مرحله ۲).
 *
 * A settlement proposal is distinguished from ordinary chat because accepting
 * one produces a binding verdict — «پذیرش پیشنهاد ► رأی توافقی، بدون نیاز به
 * اپراتور» — so it cannot be an ordinary message with a number in the text.
 */
enum MessageType: string
{
    case MESSAGE = 'MESSAGE';
    case SETTLEMENT_PROPOSAL = 'SETTLEMENT_PROPOSAL';
    case PROPOSAL_ACCEPTED = 'PROPOSAL_ACCEPTED';
    case PROPOSAL_REJECTED = 'PROPOSAL_REJECTED';

    public function isProposal(): bool
    {
        return $this === self::SETTLEMENT_PROPOSAL;
    }

    public function label(): string
    {
        return match ($this) {
            self::MESSAGE => 'پیام',
            self::SETTLEMENT_PROPOSAL => 'پیشنهاد تسویه',
            self::PROPOSAL_ACCEPTED => 'پذیرش پیشنهاد',
            self::PROPOSAL_REJECTED => 'رد پیشنهاد',
        };
    }
}
