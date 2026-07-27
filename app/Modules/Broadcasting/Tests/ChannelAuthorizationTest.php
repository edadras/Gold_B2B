<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests;

use App\Modules\Broadcasting\Application\ChannelAuthorizer;
use App\Modules\Broadcasting\Domain\ChannelName;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Domain\UserStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The tenancy boundary of §3.1 step 3: «سرور بررسی می‌کند که کاربر متعلق به
 * سازمان ۱۸۴ باشد».
 *
 * A subscription is authorised once and then streams indefinitely, so these
 * are the highest-value assertions in the module. Every one of them is written
 * as "user from A tries B", not "authorizer returns true for its own input".
 */
final class ChannelAuthorizationTest extends BroadcastingTestCase
{
    private function authorizer(): ChannelAuthorizer
    {
        return $this->app->make(ChannelAuthorizer::class);
    }

    #[Test]
    public function a_member_may_join_their_own_organization_channel(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);

        self::assertTrue(
            $this->authorizer()->mayJoinOrganization($user->id, $organization->id),
        );
    }

    #[Test]
    public function a_member_of_organization_a_is_refused_organization_b(): void
    {
        $a = $this->organization();
        $b = $this->organization();
        $user = $this->member($a);

        self::assertNotSame($a->id, $b->id);

        self::assertFalse(
            $this->authorizer()->mayJoinOrganization($user->id, $b->id),
            'a member must never reach another organisation\'s channel',
        );
    }

    /**
     * The same refusal, stated over every channel the organisation owns —
     * the settlement, RFQ and notification children are separate registrations
     * in routes/channels.php and each one is a separate chance to get it wrong.
     */
    #[Test]
    #[DataProvider('organizationChannels')]
    public function every_organization_channel_is_refused_across_the_tenant_boundary(string $suffix): void
    {
        $a = $this->organization();
        $b = $this->organization();
        $user = $this->member($a);

        $own = ChannelName::wire(ChannelName::organization($a->id).$suffix);
        $other = ChannelName::wire(ChannelName::organization($b->id).$suffix);

        self::assertTrue($this->authorizer()->mayJoin($user->id, $own), $own);
        self::assertFalse($this->authorizer()->mayJoin($user->id, $other), $other);
    }

    /** @return array<string, array{0: string}> */
    public static function organizationChannels(): array
    {
        return [
            'orders and balances' => [''],
            'settlement' => ['.settlement'],
            'rfq' => ['.rfq'],
            'notification' => ['.notification'],
        ];
    }

    #[Test]
    public function a_member_is_refused_the_platform_channels(): void
    {
        $user = $this->member($this->organization(), [Role::OWNER]);

        self::assertFalse(
            $this->authorizer()->mayJoinPlatform($user->id),
            'an OWNER is the most senior role a member has, and is still not staff',
        );

        self::assertFalse($this->authorizer()->mayJoin($user->id, 'private-admin.monitoring'));
        self::assertFalse($this->authorizer()->mayJoin($user->id, 'private-admin.alerts'));
    }

    #[Test]
    public function platform_staff_may_join_the_platform_channels(): void
    {
        $staff = $this->staff([Role::PLATFORM_ADMIN]);

        self::assertTrue($this->authorizer()->mayJoinPlatform($staff->id));
        self::assertTrue($this->authorizer()->mayJoin($staff->id, 'private-admin.monitoring'));
        self::assertTrue($this->authorizer()->mayJoin($staff->id, 'private-admin.alerts'));
    }

    #[Test]
    public function every_platform_role_counts_as_staff(): void
    {
        foreach (Role::platformRoles() as $role) {
            $staff = $this->staff([$role]);

            self::assertTrue(
                $this->authorizer()->mayJoinPlatform($staff->id),
                $role->value.' is a platform role and must reach the monitoring channel',
            );
        }
    }

    /**
     * Staff are not given a back door onto member channels. If a compliance
     * officer needs a member's order flow they read it through the audited
     * admin REST endpoints, not through an unrevoked firehose.
     */
    #[Test]
    public function platform_staff_do_not_get_member_channels(): void
    {
        $member = $this->organization();
        $staff = $this->staff([Role::PLATFORM_ADMIN]);

        self::assertFalse(
            $this->authorizer()->mayJoin($staff->id, ChannelName::wire(ChannelName::organization($member->id))),
        );
    }

    #[Test]
    public function a_user_may_join_only_their_own_personal_channel(): void
    {
        $organization = $this->organization();
        $one = $this->member($organization);
        $two = $this->member($organization, [Role::OWNER]);

        self::assertTrue($this->authorizer()->mayJoinUser($one->id, $one->id));
        self::assertFalse(
            $this->authorizer()->mayJoinUser($two->id, $one->id),
            'a colleague — even the OWNER — is not on a personal channel',
        );
    }

    #[Test]
    public function a_suspended_user_is_refused_even_their_own_organization(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);

        $user->status = UserStatus::SUSPENDED;
        $user->save();

        self::assertFalse(
            $this->authorizer()->mayJoinOrganization($user->id, $organization->id),
            'the token is not the authority; the account state is',
        );
    }

    #[Test]
    public function an_unknown_user_is_refused_everything(): void
    {
        $organization = $this->organization();

        self::assertFalse($this->authorizer()->mayJoinOrganization(999_999, $organization->id));
        self::assertFalse($this->authorizer()->mayJoinPlatform(999_999));
        self::assertFalse($this->authorizer()->mayJoinUser(999_999, 999_999));
    }

    /**
     * The channel name is attacker-controlled. Each of these is a spelling
     * that must not resolve to a channel the caller owns.
     */
    #[Test]
    public function malformed_channel_names_are_refused(): void
    {
        $organization = $this->organization();
        $user = $this->member($organization);
        $id = $organization->id;

        $refused = [
            'private-org.'.$id.'abc',
            'private-org.0'.$id,
            'private-org.'.$id.'.settlement.extra',
            'private-org.-'.$id,
            'private-org.99999999999999999999',
            'private-org.',
            'private-org.'.$id.' ',
            'private-orgs.'.$id,
            'private-something-else',
            '',
        ];

        foreach ($refused as $channel) {
            self::assertFalse(
                $this->authorizer()->mayJoin($user->id, $channel),
                sprintf('[%s] must not be treated as a channel this user owns', $channel),
            );
        }
    }

    #[Test]
    public function public_channels_need_no_membership(): void
    {
        $user = $this->member($this->organization());

        self::assertTrue($this->authorizer()->mayJoin($user->id, 'market.GOLD-995-T0'));
        self::assertTrue($this->authorizer()->mayJoin($user->id, 'market.status'));
        self::assertTrue($this->authorizer()->mayJoin($user->id, 'reference-price'));
    }
}
