<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Domain;

/**
 * The subscribable event catalogue — docs/05-api/03-realtime-webhooks.md §3.12,
 * complete and in the document's own order.
 *
 * This enum is a PUBLIC CONTRACT. A member writes these strings into their
 * registration and their accounting software switches on them; renaming a case
 * value is a breaking API change, and removing one silently stops delivering
 * events somebody is billing against. Add, never rename.
 *
 * The producing modules are not referenced here at all. Webhook may depend only
 * on Shared and Identity, so the map from a domain event class to one of these
 * types lives in Listeners\DispatchWebhooksForDomainEvent keyed by class *name*
 * as a string — a module that is not deployed simply produces no events.
 */
enum WebhookEventType: string
{
    // معاملات — trades
    case TRADE_EXECUTED = 'trade.executed';
    case ORDER_FILLED = 'order.filled';
    case ORDER_PARTIALLY_FILLED = 'order.partially_filled';
    case ORDER_CANCELLED = 'order.cancelled';
    case ORDER_REJECTED = 'order.rejected';

    // تسویه — settlement
    case SETTLEMENT_OPENED = 'settlement.opened';
    case SETTLEMENT_PAYMENT_DECLARED = 'settlement.payment_declared';
    case SETTLEMENT_PAYMENT_CONFIRMED = 'settlement.payment_confirmed';
    case SETTLEMENT_COMPLETED = 'settlement.completed';
    case SETTLEMENT_OVERDUE = 'settlement.overdue';
    case SETTLEMENT_CANCELLED = 'settlement.cancelled';
    case SETTLEMENT_REVERSED = 'settlement.reversed';

    // دفتر — ledger
    case BALANCE_UPDATED = 'balance.updated';
    case LEDGER_ENTRY_CREATED = 'ledger.entry_created';

    // طلای فیزیکی — physical gold
    case LOT_CREATED = 'lot.created';
    case LOT_OWNERSHIP_TRANSFERRED = 'lot.ownership_transferred';
    case LOT_SPLIT = 'lot.split';
    case LOT_MERGED = 'lot.merged';
    case LOT_ASSAY_RECORDED = 'lot.assay_recorded';
    case LOT_DEPOSITED = 'lot.deposited';
    case LOT_WITHDRAWN = 'lot.withdrawn';

    // RFQ / OTC
    case RFQ_RECEIVED = 'rfq.received';
    case RFQ_QUOTED = 'rfq.quoted';
    case RFQ_ACCEPTED = 'rfq.accepted';
    case OTC_OFFER_RECEIVED = 'otc.offer_received';
    case OTC_OFFER_ACCEPTED = 'otc.offer_accepted';

    // حساب — account
    case ORGANIZATION_STATUS_CHANGED = 'organization.status_changed';
    case LICENSE_EXPIRING = 'license.expiring';
    case LIMIT_CHANGED = 'limit.changed';

    // اختلاف — dispute
    case DISPUTE_OPENED = 'dispute.opened';
    case DISPUTE_RESOLVED = 'dispute.resolved';

    // تهاتر — netting
    case NETTING_PROPOSED = 'netting.proposed';
    case NETTING_EXECUTED = 'netting.executed';

    /**
     * Not part of §3.12 and deliberately NOT subscribable: it is what
     * POST /webhooks/{id}/test sends. A member cannot register for it, so a
     * test ping can never be confused with a real business event, and the
     * receiver's `type` switch does not need a case for it.
     */
    public const TEST_EVENT_TYPE = 'webhook.test';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** The §3.12 heading a type sits under; used to group the UI's checkbox list. */
    public function group(): string
    {
        return match ($this) {
            self::TRADE_EXECUTED, self::ORDER_FILLED, self::ORDER_PARTIALLY_FILLED,
            self::ORDER_CANCELLED, self::ORDER_REJECTED => 'TRADING',

            self::SETTLEMENT_OPENED, self::SETTLEMENT_PAYMENT_DECLARED,
            self::SETTLEMENT_PAYMENT_CONFIRMED, self::SETTLEMENT_COMPLETED,
            self::SETTLEMENT_OVERDUE, self::SETTLEMENT_CANCELLED,
            self::SETTLEMENT_REVERSED => 'SETTLEMENT',

            self::BALANCE_UPDATED, self::LEDGER_ENTRY_CREATED => 'LEDGER',

            self::LOT_CREATED, self::LOT_OWNERSHIP_TRANSFERRED, self::LOT_SPLIT,
            self::LOT_MERGED, self::LOT_ASSAY_RECORDED, self::LOT_DEPOSITED,
            self::LOT_WITHDRAWN => 'PHYSICAL',

            self::RFQ_RECEIVED, self::RFQ_QUOTED, self::RFQ_ACCEPTED,
            self::OTC_OFFER_RECEIVED, self::OTC_OFFER_ACCEPTED => 'RFQ_OTC',

            self::ORGANIZATION_STATUS_CHANGED, self::LICENSE_EXPIRING,
            self::LIMIT_CHANGED => 'ACCOUNT',

            self::DISPUTE_OPENED, self::DISPUTE_RESOLVED => 'DISPUTE',

            self::NETTING_PROPOSED, self::NETTING_EXECUTED => 'NETTING',
        };
    }
}
