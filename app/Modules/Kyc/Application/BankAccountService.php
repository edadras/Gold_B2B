<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application;

use App\Modules\Identity\Domain\Validators\IbanValidator;
use App\Modules\Kyc\Domain\BankAccountStatus;
use App\Modules\Kyc\Infrastructure\Models\BankAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Member bank accounts — docs/03-domain/01-identity-kyc.md §1.4.
 *
 * ADDED FOR THE HTTP LAYER. The model knew how to encrypt an IBAN and build
 * its blind index, but nothing owned the three rules around that:
 *
 *   · exactly one primary account per member, maintained transactionally —
 *     two rows flagged primary would make settlement pick one at random;
 *   · the platform-wide unique blind index is an AML control, so its violation
 *     is a business answer (BANK_ACCOUNT_DUPLICATE), not a 500;
 *   · a new account is always PENDING. A member declaring its own account
 *     VERIFIED would bypass the payment-verification step entirely.
 *
 * None of that may live in a controller, hence this service.
 */
final class BankAccountService
{
    /**
     * @return list<BankAccount> primary first, then oldest first
     */
    public function forOrganization(int $organizationId): array
    {
        return BankAccount::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function find(int $bankAccountId): ?BankAccount
    {
        /** @var BankAccount|null */
        return BankAccount::query()->find($bankAccountId);
    }

    /**
     * @param  array{
     *     iban: string,
     *     bank_name: string,
     *     account_holder_name: string,
     *     account_no?: string|null,
     *     is_primary?: bool,
     *     document_id?: int|null,
     * }  $attributes
     *
     * @throws KycOperationException on an invalid or already-registered IBAN
     */
    public function create(int $organizationId, array $attributes): BankAccount
    {
        $account = new BankAccount;

        try {
            $account->setIban((string) $attributes['iban']);
        } catch (InvalidArgumentException) {
            throw KycOperationException::invalidIban();
        }

        $account->fill([
            'organization_id' => $organizationId,
            'bank_name' => $attributes['bank_name'],
            'account_holder_name' => $attributes['account_holder_name'],
            'account_no' => $attributes['account_no'] ?? null,
            'document_id' => $attributes['document_id'] ?? null,
            // Verification is a platform decision, never the member's.
            'status' => BankAccountStatus::PENDING,
            'is_primary' => false,
        ]);

        // The first account a member registers is its primary one by
        // definition — settlement needs somewhere to send the money.
        $isFirst = ! BankAccount::query()->where('organization_id', $organizationId)->exists();
        $wantsPrimary = ($attributes['is_primary'] ?? false) === true || $isFirst;

        try {
            return DB::transaction(function () use ($account, $organizationId, $wantsPrimary): BankAccount {
                $account->is_primary = $wantsPrimary;
                $account->save();

                if ($wantsPrimary) {
                    $this->demoteOthers($organizationId, (int) $account->id);
                }

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            throw KycOperationException::duplicateIban();
        }
    }

    /** Move the primary flag onto an account the member already owns. */
    public function markPrimary(BankAccount $account): BankAccount
    {
        return DB::transaction(function () use ($account): BankAccount {
            $account->is_primary = true;
            $account->save();

            $this->demoteOthers((int) $account->organization_id, (int) $account->id);

            return $account;
        });
    }

    /**
     * Remove an account.
     *
     * The row is deleted rather than disabled because it is reference data the
     * member typed, not a financial record: settlements store the IBAN they
     * paid to on the settlement itself. When the primary account goes, the
     * oldest survivor is promoted so the member is never left without one.
     */
    public function delete(BankAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            $organizationId = (int) $account->organization_id;
            $wasPrimary = (bool) $account->is_primary;

            $account->delete();

            if (! $wasPrimary) {
                return;
            }

            /** @var BankAccount|null $successor */
            $successor = BankAccount::query()
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->first();

            if ($successor !== null) {
                $successor->is_primary = true;
                $successor->save();
            }
        });
    }

    /** Normalised form of a raw IBAN, for callers that want to echo it back. */
    public function normalizeIban(string $iban): ?string
    {
        return IbanValidator::isValid($iban) ? IbanValidator::normalize($iban) : null;
    }

    private function demoteOthers(int $organizationId, int $keepId): void
    {
        BankAccount::query()
            ->where('organization_id', $organizationId)
            ->whereKeyNot($keepId)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }
}
