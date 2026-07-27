<?php

declare(strict_types=1);

namespace App\Modules\Notification\Contracts;

/**
 * A user who may receive a notification, as far as this module needs to know.
 *
 * Scalars only, and no name: the dispatcher needs an id, an organisation, a set
 * of role names for targeting, and an address per channel. Anything else about
 * the person belongs to Identity.
 */
final readonly class Recipient
{
    /**
     * @param  list<string>  $roles  role names, e.g. ['OWNER', 'TREASURER']
     * @param  list<string>  $pushTokens
     */
    public function __construct(
        public int $id,
        public int $organizationId,
        public array $roles = [],
        public ?string $mobile = null,
        public ?string $email = null,
        public array $pushTokens = [],
    ) {}

    /** @param  list<string>  $roles */
    public function hasAnyRole(array $roles): bool
    {
        return array_intersect($this->roles, $roles) !== [];
    }
}
