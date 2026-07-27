<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Contracts;

use App\Modules\Ledger\Domain\AssetType;
use App\Modules\Ledger\Domain\Bucket;
use App\Modules\Ledger\Domain\EntryType;
use App\Modules\Ledger\Domain\LedgerEntryId;
use App\Modules\Ledger\Domain\LedgerReference;
use App\Modules\Ledger\Domain\SystemAccountCode;
use App\Modules\Ledger\Domain\TransactionGroup;
use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\Rial;

/**
 * Handed to the callable given to LedgerInterface::postGroup().
 *
 * Lets a caller post an arbitrary number of legs into one transaction_group;
 * postGroup() asserts Σ = 0 per asset once the callable returns, so a forgotten
 * counter-leg — the failure mode of worked example 7 — rolls the whole thing back.
 *
 * Signs are explicit: pass a negative amount for a debit leg.
 */
interface GroupWriter
{
    public function group(): TransactionGroup;

    /**
     * Post one leg against a member organisation's account.
     *
     * @param  int  $signedAmount  milligrams or rial; negative debits
     * @param  ?array<string, mixed>  $metadata
     */
    public function post(
        int $orgId,
        AssetType $asset,
        Bucket $bucket,
        int $signedAmount,
        EntryType $type,
        LedgerReference $ref,
        ?string $description = null,
        ?array $metadata = null,
    ): LedgerEntryId;

    /**
     * Same as post(), but the asset comes from the value object and the sign is
     * given separately (+1 credit, -1 debit).
     */
    public function postAmount(
        int $orgId,
        Bucket $bucket,
        FineWeight|Rial $amount,
        int $sign,
        EntryType $type,
        LedgerReference $ref,
        ?string $description = null,
    ): LedgerEntryId;

    /**
     * Post one leg against a system account (organization_id = 0).
     *
     * @param  ?array<string, mixed>  $metadata
     */
    public function postSystem(
        SystemAccountCode $code,
        AssetType $asset,
        int $signedAmount,
        EntryType $type,
        LedgerReference $ref,
        ?string $description = null,
        ?array $metadata = null,
    ): LedgerEntryId;
}
