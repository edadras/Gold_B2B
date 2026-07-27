<?php

declare(strict_types=1);

/*
 * Webhook module defaults, merged into `goldb2b.webhook` by the module provider.
 *
 * Everything the subsystem treats as a constant lives here rather than inline in
 * a class (AGENT_BRIEF rule 6). The retry ladder and the timeout are copied
 * verbatim from docs/05-api/03-realtime-webhooks.md §3.10; changing them changes
 * a documented contract, so they are values in one place, not scattered literals.
 */
return [
    /*
     * §3.10 «تلاش / تأخیر» — seconds of delay BEFORE each attempt.
     * Attempt 1 is immediate; the remaining six are 30s, 2m, 10m, 1h, 6h, 24h.
     * The length of this list IS the maximum attempt count (7).
     */
    'retry_delays' => [0, 30, 120, 600, 3_600, 21_600, 86_400],

    // §3.10 «timeout هر تلاش: ۱۰ ثانیه».
    'timeout_seconds' => 10,

    // §3.10 «پس از ۷۲ ساعت وضعیت DISABLED» — measured from the first failure
    // that was not followed by a success.
    'disable_after_failing_hours' => 72,

    // §3.9 / §4.8 «درخواست با انحراف بیش از ۵ دقیقه رد می‌شود». Used by verify()
    // and published to receivers as the window they should enforce.
    'signature_tolerance_seconds' => 300,

    // The `api_version` field of every payload (§3.8).
    'api_version' => 'v1',

    // How many delivery rows /webhooks/{id}/deliveries returns (§3.13).
    'delivery_page_size' => 50,

    // A member integrating one accounting package needs one endpoint; a handful
    // covers a staging copy and a couple of departments. The cap exists so that
    // a single event cannot fan out into an unbounded number of outbound
    // requests, which is a denial-of-service amplifier pointed at ourselves.
    'max_per_organization' => 10,

    // Deliveries older than this are pruned by webhooks:prune. The payload of a
    // trade is member data; it does not live in a debugging table forever.
    'delivery_retention_days' => 30,

    /*
     * ─────────────────────────── SSRF policy ───────────────────────────
     * See Application\OutboundUrlGuard for the full reasoning. The defaults are
     * the safe ones: HTTPS only, public destinations only, no allowlist.
     *
     * `allow_insecure_destinations` is the single switch a developer flips to
     * point a webhook at their laptop. It is env-driven and defaults to false,
     * so a production deploy that never sets the variable is safe by omission.
     * Even when it is true, `insecure_host_allowlist` must name the host — the
     * switch relaxes the rule for hosts you listed, it does not disable it.
     */
    'allow_insecure_destinations' => (bool) env('WEBHOOK_ALLOW_INSECURE_DESTINATIONS', false),
    'insecure_host_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WEBHOOK_INSECURE_HOST_ALLOWLIST', '')),
    ))),

    /*
     * Ports that are never a member's HTTPS listener but are very often an
     * internal service one hop from the application server. Blocking them costs
     * nothing legitimate and removes the most valuable SSRF targets even in the
     * event the IP checks are somehow satisfied (e.g. a public IP that NATs to
     * an internal box).
     */
    'blocked_ports' => [
        22, 23, 25, 445, 465, 587, 1433, 1521, 2049, 2375, 2376, 3306, 3389,
        5432, 5601, 5672, 6379, 8086, 9042, 9092, 9200, 9300, 11211, 27017,
    ],

    // Response bodies are read only far enough to record an error message.
    'max_error_length' => 500,

    /*
     * ───────────────── Domain event class => §3.12 catalogue type ─────────────────
     *
     * The whole integration surface of this module, in one place, AS STRINGS.
     *
     * Webhook may depend on Shared and Identity only, so not one of these
     * classes may be imported anywhere in the module. Keeping the map in config
     * rather than in a class constant has three consequences, all wanted:
     *
     *   · Listeners\DispatchWebhooksForDomainEvent and WebhookServiceProvider
     *     read the SAME list, so the set of events we subscribe to and the set
     *     we know how to translate cannot drift apart;
     *   · a module that is not deployed simply never fires its entry — the row
     *     sits here inert and nothing breaks;
     *   · a deployment can add or remove a producer without a code change, and
     *     a test can register a double.
     *
     * Several ledger events map onto `balance.updated`: §3.12 gives members one
     * balance event, and which internal movement caused it is visible in the
     * payload's own fields rather than in a separate event type.
     */
    'event_map' => [
        // معاملات
        'App\Modules\Trading\Events\TradeExecuted' => 'trade.executed',
        'App\Modules\Trading\Events\OrderFilled' => 'order.filled',
        'App\Modules\Trading\Events\OrderPartiallyFilled' => 'order.partially_filled',
        'App\Modules\Trading\Events\OrderCancelled' => 'order.cancelled',
        'App\Modules\Trading\Events\OrderRejected' => 'order.rejected',

        // تسویه
        'App\Modules\Settlement\Events\SettlementOpened' => 'settlement.opened',
        'App\Modules\Settlement\Events\PaymentDeclared' => 'settlement.payment_declared',
        'App\Modules\Settlement\Events\PaymentConfirmed' => 'settlement.payment_confirmed',
        'App\Modules\Settlement\Events\SettlementCompleted' => 'settlement.completed',
        'App\Modules\Settlement\Events\SettlementOverdue' => 'settlement.overdue',
        'App\Modules\Settlement\Events\SettlementCancelled' => 'settlement.cancelled',
        'App\Modules\Settlement\Events\SettlementReversed' => 'settlement.reversed',

        // دفتر
        'App\Modules\Ledger\Events\TransferCompleted' => 'balance.updated',
        'App\Modules\Ledger\Events\BalanceReserved' => 'balance.updated',
        'App\Modules\Ledger\Events\ReservationReleased' => 'balance.updated',
        'App\Modules\Ledger\Events\EntryReversed' => 'ledger.entry_created',

        // طلای فیزیکی
        'App\Modules\Custody\Events\GoldLotCreated' => 'lot.created',
        'App\Modules\Custody\Events\OwnershipTransferred' => 'lot.ownership_transferred',
        'App\Modules\Custody\Events\LotSplit' => 'lot.split',
        'App\Modules\Custody\Events\LotsMerged' => 'lot.merged',
        'App\Modules\Custody\Events\AssayRecorded' => 'lot.assay_recorded',
        'App\Modules\Custody\Events\LotEnteredVault' => 'lot.deposited',
        'App\Modules\Custody\Events\LotLeftVault' => 'lot.withdrawn',

        // RFQ / OTC
        'App\Modules\Trading\Events\RfqCreated' => 'rfq.received',
        'App\Modules\Trading\Events\RfqQuoted' => 'rfq.quoted',
        'App\Modules\Trading\Events\RfqAccepted' => 'rfq.accepted',
        'App\Modules\Trading\Events\OtcOfferCreated' => 'otc.offer_received',
        'App\Modules\Trading\Events\OtcOfferAccepted' => 'otc.offer_accepted',

        // حساب
        'App\Modules\Identity\Events\OrganizationStatusChanged' => 'organization.status_changed',
        'App\Modules\Kyc\Events\LicenseExpiring' => 'license.expiring',
        'App\Modules\Risk\Events\RiskProfileChanged' => 'limit.changed',

        // اختلاف
        'App\Modules\Dispute\Events\DisputeOpened' => 'dispute.opened',
        'App\Modules\Dispute\Events\DisputeResolved' => 'dispute.resolved',

        // تهاتر
        'App\Modules\Settlement\Events\NettingProposed' => 'netting.proposed',
        'App\Modules\Settlement\Events\NettingExecuted' => 'netting.executed',
    ],
];
