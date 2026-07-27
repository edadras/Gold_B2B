<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Models;

use App\Modules\Identity\Domain\BlindIndex;
use App\Modules\Identity\Domain\Validators\NationalIdValidator;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $organization_id
 */
class Signatory extends Model
{
    protected $table = 'signatories';

    protected $guarded = ['id'];

    protected $hidden = ['national_id_enc', 'national_id_hash'];

    protected function casts(): array
    {
        return [
            'national_id_enc' => 'encrypted',
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function setNationalId(?string $rawNationalId): void
    {
        $normalized = $rawNationalId === null ? null : NationalIdValidator::normalize($rawNationalId);

        $this->national_id_enc = $normalized;
        $this->national_id_hash = $normalized === null ? null : BlindIndex::forNationalId($normalized);
    }
}
