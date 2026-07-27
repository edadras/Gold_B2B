<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Domain\Category;
use App\Modules\Notification\Domain\Channel;
use App\Modules\Notification\Domain\NotificationCode;
use App\Modules\Notification\Domain\Priority;
use App\Modules\Notification\Infrastructure\NotificationDelivery;
use PHPUnit\Framework\Attributes\Test;

/** §15.4 rule 6 — SMS is reserved for critical, financial and security codes. */
final class SmsRestrictionTest extends NotificationTestCase
{
    #[Test]
    public function a_chatty_code_never_sends_an_sms_even_when_the_user_asked_for_one(): void
    {
        $trader = $this->recipient(1, ['TRADER']);

        // The user actively enabled SMS for trading notifications.
        $this->setPreference($trader->id, Category::TRADING, ['sms' => true, 'push' => true]);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::ORDER_FILLED,
            organizationId: 1,
            params: ['weight' => '300', 'price' => '78,500,000'],
            subjectType: 'order',
            subjectId: 1,
        ));

        self::assertTrue($result->usedChannel(Channel::PUSH->value));
        self::assertFalse(
            $result->usedChannel(Channel::SMS->value),
            'ORDER_FILLED is neither critical, financial-with-immediate-effect nor security',
        );
    }

    #[Test]
    public function a_financial_code_at_high_priority_may_use_sms(): void
    {
        $treasurer = $this->recipient(1, ['TREASURER']);

        $this->setPreference($treasurer->id, Category::SETTLEMENT, ['sms' => true]);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::SETTLEMENT_DUE_SOON,
            organizationId: 1,
            params: ['hours' => 2],
            subjectType: 'settlement',
            subjectId: 1,
        ));

        self::assertTrue($result->usedChannel(Channel::SMS->value));
    }

    #[Test]
    public function a_security_code_may_use_sms(): void
    {
        $owner = $this->recipient(1, ['OWNER']);

        $this->setPreference($owner->id, Category::ACCOUNT, ['sms' => true]);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::NEW_LOGIN,
            organizationId: 1,
        ));

        self::assertTrue($result->usedChannel(Channel::SMS->value));
    }

    #[Test]
    public function a_user_who_left_sms_off_gets_none_even_on_an_eligible_code(): void
    {
        $treasurer = $this->recipient(1, ['TREASURER']);

        // Default preferences: SMS off.
        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::SETTLEMENT_DUE_SOON,
            organizationId: 1,
            params: ['hours' => 2],
            subjectType: 'settlement',
            subjectId: 1,
        ));

        self::assertFalse($result->usedChannel(Channel::SMS->value));
        self::assertTrue($result->usedChannel(Channel::PUSH->value));

        // Eligibility is a ceiling, not a floor: only CRITICAL overrides it.
        self::assertTrue(NotificationCode::SETTLEMENT_DUE_SOON->allowsSms());
        self::assertSame(Priority::HIGH, NotificationCode::SETTLEMENT_DUE_SOON->priority());
        self::assertSame(1, $treasurer->id);
    }

    #[Test]
    public function every_code_whose_catalogue_default_includes_sms_is_eligible_for_it(): void
    {
        foreach (NotificationCode::cases() as $code) {
            if (! in_array(Channel::SMS, $code->defaultChannels(), true)) {
                continue;
            }

            self::assertTrue(
                $code->allowsSms(),
                "{$code->value} defaults to SMS but rule 6 would strip it — the catalogue contradicts itself",
            );
        }
    }

    #[Test]
    public function sms_eligibility_is_exactly_critical_financial_or_security(): void
    {
        foreach (NotificationCode::cases() as $code) {
            $expected = $code->priority() === Priority::CRITICAL
                || $code->isFinancial()
                || $code->isSecurity();

            self::assertSame($expected, $code->allowsSms(), $code->value);
        }
    }

    #[Test]
    public function a_recipient_without_a_mobile_number_leaves_a_skipped_delivery(): void
    {
        $this->recipient(1, ['OWNER'], mobile: null, pushTokens: []);

        $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::SETTLEMENT_OVERDUE,
            organizationId: 1,
            subjectType: 'settlement',
            subjectId: 1,
        ));

        $statuses = NotificationDelivery::query()
            ->pluck('status')
            ->all();

        // "We never sent it" is a question support gets asked; silence is not
        // an answer.
        self::assertContains('SKIPPED', $statuses);
    }
}
