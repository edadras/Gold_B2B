<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\MetalType;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Events\LedgerAccountCreated;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccountModel;
use App\Modules\Ledger\Infrastructure\Models\LedgerBalanceModel;
use Illuminate\Support\Facades\DB;

/**
 * Creates the ledger accounts an organisation needs, exactly once.
 *
 * A member gets the full set of docs/03-domain/03-ledger.md §3.2:
 * GOLD × {AVAILABLE, RESERVED, IN_SETTLEMENT, IN_DISPUTE} and
 * RIAL × the same four plus PAYABLE, which is the one bucket allowed to go
 * negative. Every account gets its ledger_balances row at the same time, so
 * writeEntry() always has a row to lock.
 *
 * Idempotent: re-running it for the same organisation creates nothing new.
 */
final class AccountProvisioner
{
    /**
     * @return array<int, int> ids of the accounts that exist afterwards
     */
    public function provisionMember(int $organizationId): array
    {
        $created = [];

        $ids = DB::transaction(function () use ($organizationId, &$created): array {
            $ids = [];

            foreach ([AssetType::GOLD, AssetType::RIAL] as $asset) {
                foreach (Bucket::forAsset($asset) as $bucket) {
                    [$account, $isNew] = $this->ensureAccount(
                        organizationId: $organizationId,
                        asset: $asset,
                        metal: $asset->defaultMetalType(),
                        bucket: $bucket,
                        code: null,
                        allowsNegative: $bucket->allowsNegative(),
                    );

                    $ids[] = $account->id;

                    if ($isNew) {
                        $created[] = $this->eventFor($account);
                    }
                }
            }

            return $ids;
        });

        // Rule 3: events leave the transaction before they are dispatched.
        foreach ($created as $event) {
            event($event);
        }

        return $ids;
    }

    /**
     * The system accounts of docs/04-data/02-schema-mysql.md §2.7, under
     * organization_id = 0. All of them allow negative balances: EXTERNAL_GOLD_IN
     * goes further negative with every deposit, and that is exactly what offsets
     * the member population's positive total so invariant I4 holds.
     *
     * @return array<int, int>
     */
    public function provisionSystemAccounts(): array
    {
        $created = [];

        $ids = DB::transaction(function () use (&$created): array {
            $ids = [];

            foreach (SystemAccountCode::seedDefinitions() as $definition) {
                [$account, $isNew] = $this->ensureAccount(
                    organizationId: LedgerAccountModel::SYSTEM_ORGANIZATION_ID,
                    asset: $definition['asset'],
                    metal: $definition['metal'],
                    bucket: $definition['bucket'],
                    code: $definition['code'],
                    allowsNegative: true,
                );

                $ids[] = $account->id;

                if ($isNew) {
                    $created[] = $this->eventFor($account);
                }
            }

            return $ids;
        });

        foreach ($created as $event) {
            event($event);
        }

        return $ids;
    }

    /**
     * @return array{0: LedgerAccountModel, 1: bool} the account and whether it was just created
     */
    private function ensureAccount(
        int $organizationId,
        AssetType $asset,
        ?MetalType $metal,
        Bucket $bucket,
        ?SystemAccountCode $code,
        bool $allowsNegative,
    ): array {
        $query = LedgerAccountModel::query()
            ->where('organization_id', $organizationId)
            ->where('asset_type', $asset->value)
            ->where('bucket', $bucket->value);

        $query = $code === null
            ? $query->whereNull('system_account_code')
            : $query->where('system_account_code', $code->value);

        $existing = $query->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $account = new LedgerAccountModel;
        $account->forceFill([
            'organization_id' => $organizationId,
            'asset_type' => $asset->value,
            'metal_type' => $metal?->value,
            'bucket' => $bucket->value,
            'system_account_code' => $code?->value,
            'currency' => $asset === AssetType::RIAL ? 'IRR' : null,
            'allows_negative' => $allowsNegative,
            'status' => LedgerAccountModel::STATUS_ACTIVE,
        ]);
        $account->save();

        LedgerBalanceModel::query()->create([
            'account_id' => $account->id,
            'balance' => 0,
            'entry_count' => 0,
            'version' => 0,
        ]);

        return [$account, true];
    }

    private function eventFor(LedgerAccountModel $account): LedgerAccountCreated
    {
        return new LedgerAccountCreated(
            accountId: $account->id,
            organizationId: $account->organization_id,
            assetType: $account->asset_type->value,
            metalType: $account->metal_type?->value,
            bucket: $account->bucket->value,
            systemAccountCode: $account->system_account_code,
            allowsNegative: (bool) $account->allows_negative,
        );
    }
}
