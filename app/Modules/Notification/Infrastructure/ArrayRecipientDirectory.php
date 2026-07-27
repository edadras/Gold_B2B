<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure;

use App\Modules\Notification\Contracts\Recipient;
use App\Modules\Notification\Contracts\RecipientDirectory;

/**
 * An in-memory directory.
 *
 * Two uses: tests that are about dispatch rules rather than about Identity's
 * schema, and a deployment slice where Identity is not present. Bind it through
 * `goldb2b.notification.recipient_directory` to use it outside a test.
 */
final class ArrayRecipientDirectory implements RecipientDirectory
{
    /** @var array<int, Recipient> */
    private array $recipients = [];

    /** @param  list<Recipient>  $recipients */
    public function __construct(array $recipients = [])
    {
        foreach ($recipients as $recipient) {
            $this->add($recipient);
        }
    }

    public function add(Recipient $recipient): self
    {
        $this->recipients[$recipient->id] = $recipient;

        return $this;
    }

    /** @return list<Recipient> */
    public function usersInOrganization(int $organizationId): array
    {
        return array_values(array_filter(
            $this->recipients,
            static fn (Recipient $r): bool => $r->organizationId === $organizationId,
        ));
    }

    /**
     * @param  list<string>  $roles
     * @return list<Recipient>
     */
    public function usersWithRoles(int $organizationId, array $roles): array
    {
        return array_values(array_filter(
            $this->usersInOrganization($organizationId),
            static fn (Recipient $r): bool => $r->hasAnyRole($roles),
        ));
    }

    public function find(int $userId): ?Recipient
    {
        return $this->recipients[$userId] ?? null;
    }
}
