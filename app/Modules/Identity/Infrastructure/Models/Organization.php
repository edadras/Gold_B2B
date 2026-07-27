<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Database\Factories\OrganizationFactory;
use App\Modules\Identity\Domain\BlindIndex;
use App\Modules\Identity\Domain\ComplianceState;
use App\Modules\Identity\Domain\OrganizationStatus;
use App\Modules\Identity\Domain\OrganizationType;
use App\Modules\Identity\Domain\RiskLevel;
use App\Modules\Identity\Domain\Validators\LegalIdValidator;
use App\Modules\Identity\Domain\Validators\NationalIdValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The member organisation. Owner of every balance in the system.
 *
 * Deliberately has NO tenant global scope: an organisation *is* the tenant, and
 * platform compliance staff must be able to list organisations other than their
 * own. Tenancy for member-facing endpoints is enforced explicitly by
 * PermissionChecker.
 *
 * @property int $id
 * @property OrganizationType $type
 * @property OrganizationStatus $status
 * @property string $display_name
 * @property RiskLevel $risk_level
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $table = 'organizations';

    protected $guarded = ['id'];

    protected $hidden = [
        'national_id_enc',
        'national_id_hash',
        'legal_id_enc',
        'legal_id_hash',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'status' => OrganizationStatus::class,
            'risk_level' => RiskLevel::class,
            'compliance_state' => ComplianceState::class,
            'is_platform' => 'boolean',
            'national_id_enc' => 'encrypted',
            'legal_id_enc' => 'encrypted',
            'established_at' => 'date',
            'activated_at' => 'datetime',
            'restricted_at' => 'datetime',
            'suspended_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** @return HasMany<Representative, $this> */
    public function representatives(): HasMany
    {
        return $this->hasMany(Representative::class);
    }

    /** @return HasMany<OrganizationStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrganizationStatusEvent::class);
    }

    /**
     * Writes both the ciphertext and the blind index in one place so they can
     * never drift apart.
     */
    public function setNationalId(?string $rawNationalId): void
    {
        $normalized = $rawNationalId === null ? null : NationalIdValidator::normalize($rawNationalId);

        $this->national_id_enc = $normalized;
        $this->national_id_hash = $normalized === null ? null : BlindIndex::forNationalId($normalized);
    }

    public function setLegalId(?string $rawLegalId): void
    {
        $normalized = $rawLegalId === null ? null : LegalIdValidator::normalize($rawLegalId);

        $this->legal_id_enc = $normalized;
        $this->legal_id_hash = $normalized === null ? null : BlindIndex::forLegalId($normalized);
    }

    /** @param  Builder<self>  $query */
    public function scopeWithNationalId(Builder $query, string $nationalId): Builder
    {
        $normalized = NationalIdValidator::normalize($nationalId);

        return $query->where(
            'national_id_hash',
            $normalized === null ? null : BlindIndex::forNationalId($normalized)
        );
    }

    /** @param  Builder<self>  $query */
    public function scopeWithLegalId(Builder $query, string $legalId): Builder
    {
        $normalized = LegalIdValidator::normalize($legalId);

        return $query->where(
            'legal_id_hash',
            $normalized === null ? null : BlindIndex::forLegalId($normalized)
        );
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationStatus::ACTIVE;
    }
}
