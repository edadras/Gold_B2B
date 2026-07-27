<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Modules\Identity\Domain\Role;
use App\Modules\Notification\Contracts\NotificationSpec;
use App\Modules\Notification\Domain\NotificationCode;
use App\Modules\Notification\Domain\RoleTargeting;
use App\Modules\Notification\Infrastructure\Notification;
use PHPUnit\Framework\Attributes\Test;

/** §15.4 rule 5 — role-based targeting. */
final class RoleTargetingTest extends NotificationTestCase
{
    #[Test]
    public function payment_required_reaches_the_treasurer_and_the_owner_but_not_the_viewer(): void
    {
        $treasurer = $this->recipient(1, ['TREASURER']);
        $owner = $this->recipient(2, ['OWNER']);
        $viewer = $this->recipient(3, ['VIEWER']);
        $trader = $this->recipient(4, ['TRADER']);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::PAYMENT_REQUIRED,
            organizationId: 1,
            params: ['amount' => '19,650,000,000', 'deadline' => '۱۷:۰۰'],
            subjectType: 'settlement',
            subjectId: 88_231,
        ));

        self::assertEqualsCanonicalizing([$treasurer->id, $owner->id], $result->targetedUserIds);
        self::assertNotContains($viewer->id, $result->targetedUserIds);
        self::assertNotContains($trader->id, $result->targetedUserIds);

        $notifiedUserIds = Notification::query()->pluck('user_id')->all();

        self::assertEqualsCanonicalizing([1, 2], $notifiedUserIds);
    }

    #[Test]
    public function order_filled_reaches_the_trader_and_the_owner(): void
    {
        $this->recipient(1, ['TRADER']);
        $this->recipient(2, ['OWNER']);
        $this->recipient(3, ['TREASURER']);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::ORDER_FILLED,
            organizationId: 1,
            params: ['weight' => '300', 'price' => '78,500,000'],
            subjectType: 'order',
            subjectId: 1,
        ));

        self::assertEqualsCanonicalizing([1, 2], $result->targetedUserIds);
    }

    #[Test]
    public function kyc_notifications_reach_the_owner_alone(): void
    {
        $this->recipient(1, ['OWNER']);
        $this->recipient(2, ['MANAGER']);
        $this->recipient(3, ['TRADER']);

        foreach ([
            NotificationCode::KYC_APPROVED,
            NotificationCode::KYC_INFO_REQUIRED,
            NotificationCode::KYC_REJECTED,
        ] as $code) {
            self::assertSame([Role::OWNER], RoleTargeting::rolesFor($code));
        }

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::KYC_APPROVED,
            organizationId: 1,
            subjectType: 'organization',
            subjectId: 1,
        ));

        self::assertSame([1], $result->targetedUserIds);
    }

    #[Test]
    public function dispute_notifications_reach_the_owner_and_the_manager(): void
    {
        $this->recipient(1, ['OWNER']);
        $this->recipient(2, ['MANAGER']);
        $this->recipient(3, ['ACCOUNTANT']);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::DISPUTE_RESOLVED,
            organizationId: 1,
            params: ['reference' => 'DSP-1'],
            subjectType: 'dispute',
            subjectId: 1,
        ));

        self::assertEqualsCanonicalizing([1, 2], $result->targetedUserIds);
    }

    #[Test]
    public function a_viewer_is_targeted_by_no_code_in_the_catalogue(): void
    {
        foreach (NotificationCode::cases() as $code) {
            self::assertNotContains(
                'VIEWER',
                RoleTargeting::roleNamesFor($code),
                "{$code->value} must not page a read-only account",
            );
        }
    }

    #[Test]
    public function a_user_holding_several_roles_is_notified_once(): void
    {
        $this->recipient(1, ['OWNER', 'TREASURER']);

        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::PAYMENT_REQUIRED,
            organizationId: 1,
            subjectType: 'settlement',
            subjectId: 1,
        ));

        self::assertSame([1], $result->targetedUserIds);
        self::assertSame(1, Notification::query()->count());
    }

    #[Test]
    public function an_explicit_recipient_list_overrides_role_targeting(): void
    {
        $this->recipient(1, ['OWNER']);
        $this->recipient(2, ['VIEWER']);

        // The person who must confirm a payment is a person, not a role.
        $result = $this->dispatcher->dispatch(new NotificationSpec(
            code: NotificationCode::PAYMENT_REQUIRED,
            organizationId: 1,
            subjectType: 'settlement',
            subjectId: 1,
            userIds: [2],
        ));

        self::assertSame([2], $result->targetedUserIds);
    }

    #[Test]
    public function every_code_targets_at_least_one_role(): void
    {
        foreach (NotificationCode::cases() as $code) {
            self::assertNotEmpty(
                RoleTargeting::rolesFor($code),
                "{$code->value} would be sent to nobody",
            );
        }
    }
}
