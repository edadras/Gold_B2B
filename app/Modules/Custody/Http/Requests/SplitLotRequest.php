<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Requests;

use App\Modules\Custody\Application\Commands\SplitLotCommand;
use App\Modules\Custody\Application\Commands\SplitPart;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Weight;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /lots/{id}/split` — 🔑 idempotent, ✍️ signed.
 *
 * Each requested part is denominated EITHER by gross weight ("cut me a 40 g
 * piece") OR by fine weight ("cut me enough to deliver 250 g of pure gold"),
 * never both: the two resolve differently — the fine form rounds the gross
 * weight up through formula F2 so the child can definitely cover the promise —
 * and accepting both would leave the caller guessing which one won.
 *
 * `physical_loss_gross_mg` is swarf lost to the saw, a measured quantity.
 * Rounding loss is derived by SplitService and must never be requested.
 */
final class SplitLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'parts' => ['required', 'array', 'min:1'],
            'parts.*.gross_weight_mg' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'parts.*.fine_weight_mg' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'parts.*.serial_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'parts.*.label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'physical_loss_gross_mg' => ['sometimes', 'integer', 'min:0'],
            'remainder_child' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(static function (Validator $validator): void {
            /** @var array{parts?: array<int, array<string, mixed>>} $data */
            $data = $validator->getData();

            foreach ($data['parts'] ?? [] as $index => $part) {
                if (! is_array($part)) {
                    continue;
                }

                $hasGross = ($part['gross_weight_mg'] ?? null) !== null;
                $hasFine = ($part['fine_weight_mg'] ?? null) !== null;

                if ($hasGross === $hasFine) {
                    $validator->errors()->add(
                        "parts.{$index}.gross_weight_mg",
                        'هر بخش باید دقیقاً یکی از وزن ناخالص یا وزن خالص را داشته باشد.',
                    );
                }
            }
        });
    }

    public function toCommand(int $parentLotId, int $requestedByUserId): SplitLotCommand
    {
        /** @var array<int, array<string, mixed>> $parts */
        $parts = $this->validated('parts');

        return new SplitLotCommand(
            parentLotId: $parentLotId,
            parts: array_values(array_map(
                static function (array $part): SplitPart {
                    $serial = isset($part['serial_number']) ? (string) $part['serial_number'] : null;
                    $label = isset($part['label']) ? (string) $part['label'] : null;

                    return ($part['gross_weight_mg'] ?? null) !== null
                        ? SplitPart::byGross(Weight::fromMilligrams((int) $part['gross_weight_mg']), $serial, $label)
                        : SplitPart::byFine(FineWeight::fromMilligrams((int) $part['fine_weight_mg']), $serial, $label);
                },
                $parts,
            )),
            requestedByUserId: $requestedByUserId,
            physicalLossGrossMg: (int) ($this->validated('physical_loss_gross_mg') ?? 0),
            remainderChild: (bool) ($this->validated('remainder_child') ?? true),
            reason: $this->validated('reason'),
        );
    }
}
