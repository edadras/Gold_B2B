<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Models;

use App\Modules\Identity\Domain\BlindIndex;
use App\Modules\Identity\Domain\Validators\IbanValidator;
use App\Modules\Kyc\Domain\BankAccountStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @property int $organization_id
 * @property BankAccountStatus $status
 */
class BankAccount extends Model
{
    protected $table = 'bank_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['iban_enc', 'iban_hash'];

    protected function casts(): array
    {
        return [
            'status' => BankAccountStatus::class,
            'iban_enc' => 'encrypted',
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /** Writes ciphertext, blind index and bank code together. */
    public function setIban(string $rawIban): void
    {
        if (! IbanValidator::isValid($rawIban)) {
            throw new InvalidArgumentException('Invalid Iranian IBAN');
        }

        $normalized = IbanValidator::normalize($rawIban);

        $this->iban_enc = $normalized;
        $this->iban_hash = BlindIndex::forIban((string) $normalized);
        $this->bank_code = IbanValidator::bankCode((string) $normalized);
    }

    public function maskedIban(): string
    {
        $iban = (string) ($this->iban_enc ?? '');

        return $iban === '' ? '' : substr($iban, 0, 6).str_repeat('•', 16).substr($iban, -4);
    }

    /** @param  Builder<self>  $query */
    public function scopeWithIban(Builder $query, string $iban): Builder
    {
        $normalized = IbanValidator::normalize($iban);

        return $query->where('iban_hash', BlindIndex::forIban((string) $normalized));
    }
}
