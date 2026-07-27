<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Requests;

use App\Modules\Ledger\Domain\Bucket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `GET /ledger/{gold|rial}` — cursor pagination plus an optional bucket filter. */
final class LedgerIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'bucket' => ['sometimes', Rule::in(array_map(static fn (Bucket $b): string => $b->value, Bucket::cases()))],
        ];
    }

    public function bucket(): ?Bucket
    {
        $bucket = $this->query('bucket');

        return is_string($bucket) ? Bucket::tryFrom($bucket) : null;
    }
}
