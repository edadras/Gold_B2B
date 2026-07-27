<?php

declare(strict_types=1);

namespace App\Modules\Notification\Contracts;

use App\Modules\Notification\Domain\Category;
use App\Modules\Notification\Domain\NotificationCode;
use App\Modules\Notification\Domain\Priority;

/**
 * What a caller asks for: an event that happened, to whom, about what.
 *
 * Category and priority are *not* parameters — they come from the code's entry
 * in the catalogue (§15.2). A caller that could set its own priority would
 * eventually mark everything CRITICAL, and CRITICAL is precisely the level that
 * ignores the user's preferences.
 */
final readonly class NotificationSpec
{
    /**
     * @param  array<string, string|int>  $params  values for the template's `:name` placeholders
     * @param  array<string, mixed>|null  $actionPayload
     * @param  list<int>|null  $userIds  explicit recipients; null means "resolve by role"
     */
    public function __construct(
        public NotificationCode $code,
        public int $organizationId,
        public array $params = [],
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?string $actionType = null,
        public ?array $actionPayload = null,
        public ?array $userIds = null,
    ) {}

    public function category(): Category
    {
        return $this->code->category();
    }

    public function priority(): Priority
    {
        return $this->code->priority();
    }

    public function title(): string
    {
        return $this->render($this->code->titleTemplate());
    }

    public function body(): string
    {
        return $this->render($this->code->bodyTemplate());
    }

    /** Replaces `:name` placeholders; an unsupplied placeholder is left visible rather than blanked. */
    private function render(string $template): string
    {
        $replacements = [];

        foreach ($this->params as $key => $value) {
            $replacements[':'.$key] = (string) $value;
        }

        // Longest keys first, so `:weight` cannot be eaten by `:w`.
        uksort($replacements, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return strtr($template, $replacements);
    }
}
