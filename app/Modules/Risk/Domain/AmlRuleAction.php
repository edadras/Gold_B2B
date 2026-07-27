<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain;

/** What the engine does when a rule matches. §12.2. */
enum AmlRuleAction: string
{
    case LOG = 'LOG';
    case FLAG = 'FLAG';
    case WARN = 'WARN';
    case BLOCK = 'BLOCK';

    public function raisesFlag(): bool
    {
        return $this !== self::LOG;
    }

    public function blocksOperation(): bool
    {
        return $this === self::BLOCK;
    }
}
