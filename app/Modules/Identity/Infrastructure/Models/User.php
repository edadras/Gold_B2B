<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Domain\BlindIndex;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Modules\Identity\Domain\UserStatus;
use App\Modules\Identity\Domain\Validators\NationalIdValidator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A human login, always scoped to exactly one organisation.
 *
 * Note the auth column is `password_hash`, not `password`: the schema names it
 * after what it holds. getAuthPassword() bridges that back to the framework.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $mobile
 * @property UserStatus $status
 * @property int $failed_login_count
 * @property CarbonInterface|null $locked_until
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    protected $table = 'users';

    protected $guarded = ['id'];

    protected $hidden = [
        'password_hash',
        'remember_token',
        'two_factor_secret_enc',
        'two_factor_recovery_enc',
        'national_id_enc',
        'national_id_hash',
    ];

    /** Cached permission set for the life of the request. */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'national_id_enc' => 'encrypted',
            'two_factor_secret_enc' => 'encrypted',
            'two_factor_recovery_enc' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'failed_login_count' => 'integer',
            'two_factor_last_counter' => 'integer',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot(['organization_id', 'assigned_by_user_id', 'assigned_at', 'expires_at']);
    }

    /** @return HasMany<UserSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /** @return HasMany<UserDevice, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /** @return HasMany<Representative, $this> */
    public function representatives(): HasMany
    {
        return $this->hasMany(Representative::class);
    }

    public function setNationalId(?string $rawNationalId): void
    {
        $normalized = $rawNationalId === null ? null : NationalIdValidator::normalize($rawNationalId);

        $this->national_id_enc = $normalized;
        $this->national_id_hash = $normalized === null ? null : BlindIndex::forNationalId($normalized);
    }

    /**
     * Role names held by this user, ignoring expired grants.
     *
     * @return list<RoleEnum>
     */
    public function roleEnums(): array
    {
        $now = now();

        return $this->roles
            ->filter(static function (Role $role) use ($now): bool {
                $expiresAt = $role->pivot?->getAttribute('expires_at');

                // Pivot attributes are not cast, so normalise before comparing:
                // a raw string compared against a Carbon instance silently
                // evaluates the wrong way round.
                return $expiresAt === null || Carbon::parse($expiresAt)->greaterThan($now);
            })
            ->map(static fn (Role $role): ?RoleEnum => RoleEnum::tryFrom($role->name))
            ->filter()
            ->values()
            ->all();
    }

    public function hasRole(RoleEnum $role): bool
    {
        return in_array($role, $this->roleEnums(), true);
    }

    /**
     * Permission set derived from the user's roles.
     *
     * Read from the enum rather than the pivot table so that a permission added
     * to a role in code takes effect without a data migration; the tables exist
     * for introspection and for the admin UI.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        $names = [];
        foreach ($this->roleEnums() as $role) {
            foreach ($role->permissions() as $permission) {
                $names[$permission->value] = true;
            }
        }

        return $this->permissionCache = array_keys($names);
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissionNames(), true);
    }

    /** Drop the memoised permission set after a role change. */
    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    public function isPlatformStaff(): bool
    {
        foreach ($this->roleEnums() as $role) {
            if ($role->isPlatformRole()) {
                return true;
            }
        }

        return false;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null
            && ! in_array($this->two_factor_secret_enc, [null, ''], true);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
