<?php

declare(strict_types=1);

/*
 * Merged into config('goldb2b.reporting') by ReportingServiceProvider.
 */
return [
    // §15.8 — «آیا سبک است؟ (< 1000 رکورد، < 2 ثانیه)»
    'inline_row_threshold' => 1_000,

    // §15.8 — «لینک موقت (۲۴ ساعت)»
    'link_ttl_hours' => 24,

    // §15.8 — «Rate limit: ۲۰ گزارش در ساعت برای هر سازمان»
    'hourly_request_limit' => 20,

    // Where generated files land. S3 in production, per §15.8; the local disk
    // keeps the code path identical without needing a bucket.
    'disk' => env('REPORTING_DISK', 'local'),

    'queue' => env('REPORTING_QUEUE', 'reporting'),

    // §15.8 — «همه گزارش‌ها از read replica خوانده می‌شوند»، except the
    // instantaneous ledger reports, which must come from the primary.
    'read_connection' => env('REPORTING_READ_CONNECTION'),
];
