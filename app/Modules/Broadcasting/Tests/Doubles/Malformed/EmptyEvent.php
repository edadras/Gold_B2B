<?php

declare(strict_types=1);

namespace App\Modules\Broadcasting\Tests\Doubles\Malformed;

/**
 * An event with no properties whatsoever — the degenerate case. Every listener
 * must return without broadcasting and without throwing.
 */
final readonly class EmptyEvent
{
    public function __construct() {}
}
