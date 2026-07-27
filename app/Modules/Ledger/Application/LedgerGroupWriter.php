<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Contracts\GroupWriter;
use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Domain\TransactionGroup;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccountModel;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntryModel;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;
use Closure;
use InvalidArgumentException;

/**
 * The writer LedgerService::postGroup() hands to its callable.
 *
 * It is a thin, locked-down view of LedgerService::writeEntry(): callers can
 * post any number of legs into one group but cannot choose the group, skip the
 * balance assertion, or reach the ledger outside a transaction.
 *
 * Post legs in ascending organisation id where the operation allows it. Lock
 * ordering within an organisation is handled here; ordering across
 * organisations is the caller's, and postGroup() retries deadlocks three times.
 */
final class LedgerGroupWriter implements GroupWriter
{
    /**
     * @param  Closure(LedgerAccountModel, int, EntryType, TransactionGroup, LedgerReference, ?string, ?array<string, mixed>): LedgerEntryModel  $write
     * @param  Closure(int, AssetType, Bucket): LedgerAccountModel  $memberAccount
     * @param  Closure(SystemAccountCode, AssetType): LedgerAccountModel  $systemAccount
     */
    public function __construct(
        private readonly TransactionGroup $group,
        private readonly Closure $write,
        private readonly Closure $memberAccount,
        private readonly Closure $systemAccount,
    ) {}

    public function group(): TransactionGroup
    {
        return $this->group;
    }

    public function post(
        int $orgId,
        AssetType $asset,
        Bucket $bucket,
        int $signedAmount,
        EntryType $type,
        LedgerReference $ref,
        ?string $description = null,
        ?array $metadata = null,
    ): LedgerEntryId {
        $account = ($this->memberAccount)($orgId, $asset, $bucket);

        return ($this->write)($account, $signedAmount, $type, $this->group, $ref, $description, $metadata)
            ->entryId();
    }

    public function postAmount(
        int $orgId,
        Bucket $bucket,
        FineWeight|Rial $amount,
        int $sign,
        EntryType $type,
        LedgerReference $ref,
        ?string $description = null,
    ): LedgerEntryId {
        if ($sign !== 1 && $sign !== -1) {
            throw new InvalidArgumentException('Sign must be exactly +1 or -1');
        }

        $asset = LedgerService::assetOf($amount);
        $value = $asset->unwrap($amount);

        if ($value <= 0) {
            throw new InvalidArgumentException('Ledger operations take a strictly positive amount');
        }

        return $this->post($orgId, $asset, $bucket, $value * $sign, $type, $ref, $description);
    }

    public function postSystem(
        SystemAccountCode $code,
        AssetType $asset,
        int $signedAmount,
        EntryType $type,
        LedgerReference $ref,
        ?string $description = null,
        ?array $metadata = null,
    ): LedgerEntryId {
        $account = ($this->systemAccount)($code, $asset);

        return ($this->write)($account, $signedAmount, $type, $this->group, $ref, $description, $metadata)
            ->entryId();
    }
}
