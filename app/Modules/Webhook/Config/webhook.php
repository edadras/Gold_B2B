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
];
