<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Domain\Category;
use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Domain\NotificationCode;
use App\Modules\Notification\Domain\Priority;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The catalogue of §15.2 — complete, consistent and in Persian. */
final class NotificationCatalogueTest extends TestCase
{
    #[Test]
    public function the_catalogue_covers_all_five_categories(): void
    {
        $categories = array_unique(array_map(
            static fn (NotificationCode $c): string => $c->category()->value,
            NotificationCode::cases(),
        ));

        sort($categories);

        self::assertSame(
            ['ACCOUNT', 'ASSET', 'DISPUTE', 'SETTLEMENT', 'TRADING'],
            $categories,
        );
    }

    #[Test]
    public function the_doc_table_is_reproduced_case_by_case(): void
    {
        // A spot check across all five categories, taken straight from §15.2.
        $expected = [
            ['ORDER_PLACED', Category::TRADING, Priority::NORMAL, [Channel::IN_APP]],
            ['ORDER_FILLED', Category::TRADING, Priority::HIGH, [Channel::PUSH, Channel::IN_APP]],
            ['RFQ_ACCEPTED', Category::TRADING, Priority::CRITICAL, [Channel::PUSH, Channel::SMS]],
            ['PAYMENT_REQUIRED', Category::SETTLEMENT, Priority::HIGH, [Channel::PUSH, Channel::IN_APP]],
            ['SETTLEMENT_OVERDUE', Category::SETTLEMENT, Priority::CRITICAL, [Channel::PUSH, Channel::SMS]],
            ['GOLD_RESERVED', Category::ASSET, Priority::NORMAL, [Channel::IN_APP]],
            ['ASSAY_VARIANCE', Category::ASSET, Priority::CRITICAL, [Channel::PUSH, Channel::SMS]],
            ['LICENSE_EXPIRING_30', Category::ACCOUNT, Priority::NORMAL, [Channel::IN_APP, Channel::EMAIL]],
            ['TIER_UPGRADED', Category::ACCOUNT, Priority::NORMAL, [Channel::PUSH, Channel::IN_APP]],
            ['DISPUTE_MESSAGE', Category::DISPUTE, Priority::HIGH, [Channel::PUSH]],
            ['DISPUTE_RESOLVED', Category::DISPUTE, Priority::CRITICAL, [Channel::PUSH, Channel::SMS]],
        ];

        foreach ($expected as [$value, $category, $priority, $channels]) {
            $code = NotificationCode::from($value);

            self::assertSame($category, $code->category(), $value);
            self::assertSame($priority, $code->priority(), $value);
            self::assertSame($channels, $code->defaultChannels(), $value);
        }
    }

    #[Test]
    public function every_code_has_persian_templates_and_at_least_one_channel(): void
    {
        foreach (NotificationCode::cases() as $code) {
            self::assertNotSame('', $code->titleTemplate(), $code->value);
            self::assertNotSame('', $code->bodyTemplate(), $code->value);
            self::assertNotEmpty($code->defaultChannels(), $code->value);

            // Persian text, not an untranslated placeholder.
            self::assertMatchesRegularExpression(
                '/\p{Arabic}/u',
                $code->titleTemplate(),
                "{$code->value} title is not in Persian",
            );
            self::assertMatchesRegularExpression(
                '/\p{Arabic}/u',
                $code->bodyTemplate(),
                "{$code->value} body is not in Persian",
            );
        }
    }

    #[Test]
    public function templates_render_their_parameters(): void
    {
        $spec = new NotificationSpec(
            code: NotificationCode::ORDER_FILLED,
            organizationId: 1,
            params: ['weight' => '300', 'price' => '78,500,000'],
        );

        self::assertSame('سفارش شما اجرا شد — 300 گرم @ 78,500,000', $spec->body());
        self::assertSame('اجرای سفارش', $spec->title());
    }

    #[Test]
    public function an_unsupplied_placeholder_is_left_visible_rather_than_blanked(): void
    {
        $spec = new NotificationSpec(
            code: NotificationCode::PAYMENT_REQUIRED,
            organizationId: 1,
            params: ['amount' => '19,650,000,000'],
        );

        // A body reading "پرداخت 19,650,000,000 ریال تا " would look like a bug
        // in the product; leaving the token in makes it look like the bug it is.
        self::assertStringContainsString(':deadline', $spec->body());
    }

    #[Test]
    public function the_priority_and_category_come_from_the_catalogue_not_the_caller(): void
    {
        $spec = new NotificationSpec(
            code: NotificationCode::SETTLEMENT_OVERDUE,
            organizationId: 1,
        );

        self::assertSame(Priority::CRITICAL, $spec->priority());
        self::assertSame(Category::SETTLEMENT, $spec->category());
    }

    #[Test]
    public function the_aggregate_wording_states_the_count(): void
    {
        $body = NotificationCode::ORDER_FILLED->aggregateBody(7);

        self::assertStringContainsString('7', $body);
        self::assertMatchesRegularExpression('/\p{Arabic}/u', $body);
    }

    #[Test]
    public function critical_codes_are_exactly_the_ones_the_doc_marks_as_such(): void
    {
        $critical = array_values(array_map(
            static fn (NotificationCode $c): string => $c->value,
            array_filter(
                NotificationCode::cases(),
                static fn (NotificationCode $c): bool => $c->priority() === Priority::CRITICAL,
            ),
        ));

        sort($critical);

        self::assertSame([
            'ACCOUNT_RESTRICTED',
            'ASSAY_VARIANCE',
            'DISPUTE_OPENED_AGAINST',
            'DISPUTE_REPLY_DUE',
            'DISPUTE_RESOLVED',
            'LICENSE_EXPIRED',
            'PAYMENT_DECLARED',
            'RFQ_ACCEPTED',
            'SETTLEMENT_DEFAULTED',
            'SETTLEMENT_OVERDUE',
        ], $critical);
    }
}
