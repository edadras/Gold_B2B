<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /members/search?q= — docs §2.11.
 *
 * The two-character floor is a privacy control, not ergonomics: a one-character
 * query against a member directory is a way of paging through the entire
 * membership, and this endpoint is reachable by every role in every member.
 * `max:100` closes the other end — an enormous LIKE pattern is a cheap way to
 * make the database do expensive work.
 */
final class MemberSearchRequest extends FormRequest
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function term(): string
    {
        return trim((string) $this->validated('q'));
    }

    public function limit(): int
    {
        $limit = $this->validated('limit');

        return $limit === null ? self::DEFAULT_LIMIT : (int) $limit;
    }
}
