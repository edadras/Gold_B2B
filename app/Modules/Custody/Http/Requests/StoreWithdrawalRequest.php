<?php

declare(strict_types=1);

namespace App\Modules\Custody\Http\Requests;

use App\Modules\Custody\Application\Commands\WithdrawalRequestCommand;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /vault/withdrawals` — 🔑 idempotent, ✍️ signed.
 *
 * This is step 1 of the four-step withdrawal of docs §6.4: the lots go
 * RESERVED here, a second user approves, a platform vault officer issues the
 * waybill, and the one-time code is presented at the counter. Nothing leaves
 * the building on the strength of this call alone.
 */
final class StoreWithdrawalRequest extends FormRequest
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
            'lot_ids' => ['required', 'array', 'min:1'],
            'lot_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'receiver_name' => ['sometimes', 'nullable', 'string', 'max:191'],
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

    public function toCommand(int $organizationId, int $requestedByUserId): WithdrawalRequestCommand
    {
        return new WithdrawalRequestCommand(
            vaultId: (int) $this->validated('vault_id'),
            ownerOrganizationId: $organizationId,
            lotIds: $this->lotIds(),
            requestedByUserId: $requestedByUserId,
            receiverName: $this->validated('receiver_name'),
            reason: $this->validated('reason'),
        );
    }
}
