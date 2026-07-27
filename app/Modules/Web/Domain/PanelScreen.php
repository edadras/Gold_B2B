<?php

declare(strict_types=1);

namespace App\Modules\Web\Domain;

/**
 * The screens the trader panel exposes — docs/08-frontend-web/01-web-panels.md
 * §1.2 and §1.3–1.6.
 *
 * The panel is a single-page application: every one of these is served by the
 * same shell document and resolved client-side. The enum exists anyway because
 * two things need the list on the server:
 *
 *   1. route registration, so `/app/ledger` is a real URL that survives a
 *      refresh and can be bookmarked, rather than a fragment; and
 *   2. the document `<title>`, which is the only piece of a screen the browser
 *      shows before the bundle has parsed.
 *
 * `path()` is the client route, not a Laravel route name, so the JS router and
 * the server agree on one spelling.
 */
enum PanelScreen: string
{
    case TERMINAL = 'terminal';
    case LEDGER = 'ledger';
    case SETTLEMENTS = 'settlements';
    case ORDERS = 'orders';
    case TRADES = 'trades';
    case LOTS = 'lots';
    case COUNTERPARTIES = 'counterparties';
    case REPORTS = 'reports';

    /** URL path under the panel prefix. */
    public function path(): string
    {
        return $this->value;
    }

    /** Persian title, shown in the tab and the page header. */
    public function title(): string
    {
        return match ($this) {
            self::TERMINAL => 'ترمینال معاملاتی',
            self::LEDGER => 'دفتر کل',
            self::SETTLEMENTS => 'صف تسویه',
            self::ORDERS => 'سفارش‌ها',
            self::TRADES => 'معاملات',
            self::LOTS => 'شمش‌ها',
            self::COUNTERPARTIES => 'طرف‌حساب‌ها',
            self::REPORTS => 'گزارش‌ها',
        };
    }

    /** The screen a bare `/app` lands on. */
    public static function default(): self
    {
        return self::TERMINAL;
    }

    /** @return list<array{key: string, path: string, title: string}> */
    public static function navigation(): array
    {
        return array_map(
            static fn (self $screen): array => [
                'key' => $screen->value,
                'path' => $screen->path(),
                'title' => $screen->title(),
            ],
            self::cases(),
        );
    }
}
