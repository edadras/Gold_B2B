<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Application\EventPostingService;
use App\Modules\Accounting\Contracts\PostingContext;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Accounting\Domain\SourceType;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «جریمه تأخیر» from the §9.3 table: ۵۵۰۱ جریمه against ۱۱۰۳ موجودی ریالی.
 *
 * Fires on a late-settlement penalty raised by Settlement. Bound by string name
 * for the same reason as the other listeners in this directory.
 */
final readonly class PostPenaltyVoucher
{
    public function __construct(private EventPostingService $posting) {}

    public function handle(object $event): void
    {
        $organizationId = EventFacts::int($event, 'organizationId', 'orgId', 'defaulterOrgId', 'payerOrgId');
        $amount = EventFacts::int($event, 'penaltyRial', 'amountRial', 'amount');
        $sourceId = EventFacts::int($event, 'penaltyId', 'settlementId', 'id');

        if ($organizationId === null || $amount === null || $sourceId === null || $amount <= 0) {
            Log::channel(config('logging.default'))->debug('accounting: penalty event ignored', [
                'event' => $event::class,
            ]);

            return;
        }

        $entryDate = EventFacts::date($event, 'chargedAt', 'occurredAt', 'createdAt')
            ?? (new DateTimeImmutable('today'))->format('Y-m-d');

        try {
            $this->posting->postSimple(
                PostingRule::PENALTY,
                new PostingContext(
                    organizationId: $organizationId,
                    sourceId: $sourceId,
                    entryDate: $entryDate,
                    sourceType: SourceType::PENALTY,
                    description: EventFacts::string($event, 'description') ?? 'جریمه تأخیر تسویه',
                    amountRial: $amount,
                    counterpartyOrgId: EventFacts::int($event, 'counterpartyOrgId', 'payeeOrgId'),
                ),
            );
        } catch (Throwable $e) {
            Log::channel(config('logging.default'))->error('accounting: penalty voucher failed', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
