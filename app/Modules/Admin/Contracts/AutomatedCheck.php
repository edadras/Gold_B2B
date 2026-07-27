<?php

declare(strict_types=1);

namespace App\Modules\Admin\Contracts;

/**
 * One line of the automated-check panel on the KYC review page. `passed` is
 * nullable: "not determinable" is a distinct answer from "failed", and showing
 * it as a failure trains officers to ignore the panel.
 */
final readonly class AutomatedCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public ?bool $passed,
        public string $detail,
    ) {}

    public function tone(): string
    {
        return match ($this->passed) {
            true => 'ok',
            false => 'bad',
            null => 'muted',
        };
    }
}
