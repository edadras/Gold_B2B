<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Requests;

use App\Modules\Custody\Application\Commands\MergeLotsCommand;
use App\Modules\Custody\Domain\Enums\LotShape;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /lots/merge` — 🔑 idempotent, ✍️ signed.
 *
 * `distinct` on the ids matters: MergeLotsCommand refuses a repeated id, and
 * catching it here turns a business exception into a field error. Without the
 * check a caller could ask to merge lot 7 with lot 7 and, if the guard ever
 * slipped, double its metal.
 */
final class MergeLotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lot_ids' => ['required', 'array', 'min:2'],
            'lot_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'output_shape' => ['sometimes', 'nullable', 'string', Rule::in(array_map(
                static fn (LotShape $shape): string => $shape->value,
                LotShape::cases(),
            ))],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<int> */
    public function lotIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('lot_ids');

        return array_values(array_map('intval', $ids));
    }

    public function toCommand(int $requestedByUserId): MergeLotsCommand
    {
        $shape = $this->validated('output_shape');

        return new MergeLotsCommand(
            lotIds: $this->lotIds(),
            requestedByUserId: $requestedByUserId,
            outputShape: is_string($shape) && $shape !== '' ? LotShape::from($shape) : null,
            serialNumber: $this->validated('serial_number'),
            reason: $this->validated('reason'),
        );
    }
}
