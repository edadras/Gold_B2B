<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Requests;

use App\Modules\Custody\Application\Commands\DepositCommand;
use App\Modules\Custody\Application\Commands\DepositPiece;
use App\Modules\Custody\Domain\Enums\LotShape;
use App\Modules\Custody\Domain\Enums\PuritySource;
use App\Modules\Shared\ValueObjects\Purity;
use App\Modules\Shared\ValueObjects\Weight;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /vault/deposits` — 🔑 idempotent.
 *
 * One entry per physical piece: the vault books one lot per piece, because a
 * lot is a piece of metal, not a quantity.
 *
 * `purity_source` is accepted but constrained, and the consequence is real: a
 * piece declared ASSAYED without a certificate from an accredited laboratory
 * is caught downstream, while DECLARED is booked UNDER_ASSAY and cannot be
 * traded until AssayService records a certificate (docs §6.3 step 6).
 *
 * Weights are integer milligrams and purity an integer ten-thousandth. No
 * float ever appears on this path — a gram-valued decimal here would be a
 * rounding error measured in money.
 */
final class StoreDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vault_id' => ['required', 'integer', 'min:1'],
            'vault_box_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'pieces' => ['required', 'array', 'min:1'],
            'pieces.*.gross_weight_mg' => ['required', 'integer', 'min:1'],
            'pieces.*.purity_x10' => ['required', 'integer', 'min:1', 'max:10000'],
            'pieces.*.purity_source' => ['required', 'string', Rule::in(array_map(
                static fn (PuritySource $source): string => $source->value,
                PuritySource::cases(),
            ))],
            'pieces.*.shape' => ['sometimes', 'nullable', 'string', Rule::in(array_map(
                static fn (LotShape $shape): string => $shape->value,
                LotShape::cases(),
            ))],
            'pieces.*.serial_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'pieces.*.hallmark_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'pieces.*.refiner_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'pieces.*.vault_box_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function toCommand(int $organizationId, int $userId): DepositCommand
    {
        /** @var array<int, array<string, mixed>> $pieces */
        $pieces = $this->validated('pieces');

        $boxId = $this->validated('vault_box_id');

        return new DepositCommand(
            vaultId: (int) $this->validated('vault_id'),
            ownerOrganizationId: $organizationId,
            pieces: array_values(array_map(
                static fn (array $piece): DepositPiece => new DepositPiece(
                    gross: Weight::fromMilligrams((int) $piece['gross_weight_mg']),
                    purity: Purity::fromScaled((int) $piece['purity_x10']),
                    puritySource: PuritySource::from((string) $piece['purity_source']),
                    shape: isset($piece['shape']) && is_string($piece['shape'])
                        ? LotShape::from($piece['shape'])
                        : LotShape::BAR,
                    serialNumber: isset($piece['serial_number']) ? (string) $piece['serial_number'] : null,
                    hallmarkCode: isset($piece['hallmark_code']) ? (string) $piece['hallmark_code'] : null,
                    refinerId: isset($piece['refiner_id']) ? (int) $piece['refiner_id'] : null,
                    vaultBoxId: isset($piece['vault_box_id']) ? (int) $piece['vault_box_id'] : null,
                ),
                $pieces,
            )),
            // The member requests it; at this stage of the flow the same user
            // is recorded as executing it, because there is no counter step in
            // the API — the physical hand-over is a vault-officer action.
            requestedByUserId: $userId,
            executedByUserId: $userId,
            defaultVaultBoxId: $boxId === null ? null : (int) $boxId,
            reason: $this->validated('reason'),
        );
    }
}
