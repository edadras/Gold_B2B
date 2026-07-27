<?php

declare(strict_types=1);

/*
 * Merged into config('goldb2b.dispute') by DisputeServiceProvider.
 *
 * The two deadlines already exist in config/goldb2b.php; mergeConfigFrom keeps
 * those values and only fills in what is missing, so the platform config stays
 * authoritative and this file supplies the module-only settings.
 */
return [
    // §13.6 مرحله ۱ — «مهلت ۲۴ ساعت»
    'reply_deadline_hours' => 24,

    // §13.6 مرحله ۲ — «مهلت ۴۸ ساعت»
    'negotiation_hours' => 48,

    // How far ahead DISPUTE_REPLY_DUE warns (§15.2 — «۴ ساعت تا پایان مهلت»).
    'reply_reminder_hours' => 4,

    // §13.8 — «اگر ۳ اختلاف بازنده در ۹۰ روز ► بازبینی ریسک». Emitted as part
    // of DisputeReputationAssessed; Reputation decides what to do with it.
    'risk_review_lost_disputes' => 3,
    'risk_review_window_days' => 90,
];
