<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Domain;

/**
 * Payload hygiene shared by every broadcast event.
 *
 * §3.5 asks for "only the changed fields" and "no `_display` fields — the
 * client formats it itself", so a null reads as "unchanged / unknown" and is
 * dropped rather than sent as `null`. `false` and `0` are real values and are
 * kept: `requires_your_action: false` is information.
 */
final class Payload
{
    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function compact(array $fields): array
    {
        return array_filter($fields, static fn (mixed $v): bool => $v !== null);
    }
}
