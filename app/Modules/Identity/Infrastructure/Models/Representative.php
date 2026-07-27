<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Domain\AuthorityType;
use App\Modules\Identity\Domain\BlindIndex;
use App\Modules\Identity\Domain\RepresentativeStatus;
use App\Modules\Identity\Domain\Validators\NationalIdValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Authority granted by a member to a named person (docs §1.7).
 *
 * @property int $organization_id
 * @property AuthorityType $authority_type
 * @property RepresentativeStatus $status
 */
class Representative extends Model
{
    protected $table = 'representatives';

    protected $guarded = ['id'];

    protected $hidden = ['national_id_enc', 'national_id_hash'];

    protected function casts(): array
    {
        return [
            'authority_type' => AuthorityType::class,
            'status' => RepresentativeStatus::class,
            'national_id_enc' => 'encrypted',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'daily_limit_mg' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setNationalId(?string $rawNationalId): void
    {
        $normalized = $rawNationalId === null ? null : NationalIdValidator::normalize($rawNationalId);

        $this->national_id_enc = $normalized;
        $this->national_id_hash = $normalized === null ? null : BlindIndex::forNationalId($normalized);
    }

    /** Valid today and covering the requested authority. */
    public function isEffective(AuthorityType $needed): bool
    {
        if ($this->status !== RepresentativeStatus::ACTIVE) {
            return false;
        }

        $today = now()->startOfDay();

        if ($this->valid_from !== null && $this->valid_from->greaterThan($today)) {
            return false;
        }

        if ($this->valid_until !== null && $this->valid_until->lessThan($today)) {
            return false;
        }

        return $this->authority_type->covers($needed);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RepresentativeStatus::ACTIVE->value);
    }
}
