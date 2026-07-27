<?php

declare(strict_types=1);

namespace App\Modules\Risk\Aml;

/**
 * Maps aml_rules.code onto the class that implements it. A row whose code has
 * no implementation is skipped rather than fatal — the catalogue in §12.3 is
 * larger than what is coded today, and an unimplemented rule must not take the
 * whole engine down.
 */
final class AmlRuleRegistry
{
    /** @var array<string, AmlRule> */
    private array $rules = [];

    /** @param iterable<AmlRule> $rules */
    public function __construct(iterable $rules = [])
    {
        foreach ($rules as $rule) {
            $this->register($rule);
        }
    }

    public function register(AmlRule $rule): self
    {
        $this->rules[$rule->code()] = $rule;

        return $this;
    }

    public function has(string $code): bool
    {
        return isset($this->rules[$code]);
    }

    public function get(string $code): ?AmlRule
    {
        return $this->rules[$code] ?? null;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->rules);
    }
}
