<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Models;

use App\Modules\Kyc\Domain\LicenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property LicenseStatus $status
 * @property Carbon $expires_at
 */
class BusinessLicense extends Model
{
    protected $table = 'business_licenses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => LicenseStatus::class,
            'issued_at' => 'date',
            'expires_at' => 'date',
            'verified_at' => 'datetime',
            'reminder_sent_30d' => 'boolean',
            'reminder_sent_10d' => 'boolean',
            'reminder_sent_1d' => 'boolean',
            'expiry_enforced' => 'boolean',
        ];
    }

    /** Whole days from today until expiry; negative once expired. */
    public function daysUntilExpiry(?Carbon $asOf = null): int
    {
        $asOf ??= Carbon::now();

        return (int) $asOf->copy()->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay(), false);
    }

    public function isExpired(?Carbon $asOf = null): bool
    {
        return $this->daysUntilExpiry($asOf) < 0;
    }

    /** @param  Builder<self>  $query */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LicenseStatus::VALID->value,
            LicenseStatus::EXPIRING_SOON->value,
        ]);
    }
}
