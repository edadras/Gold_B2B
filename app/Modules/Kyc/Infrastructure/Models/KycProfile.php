<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Models;

use App\Modules\Kyc\Database\Factories\KycProfileFactory;
use App\Modules\Kyc\Domain\KycStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $organization_id
 * @property KycStatus $status
 */
class KycProfile extends Model
{
    /** @use HasFactory<KycProfileFactory> */
    use HasFactory;

    protected $table = 'kyc_profiles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => KycStatus::class,
            'tax_registered' => 'boolean',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'next_review_due_at' => 'date',
            'submission_count' => 'integer',
        ];
    }

    protected static function newFactory(): KycProfileFactory
    {
        return KycProfileFactory::new();
    }

    /** @return HasMany<KycReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(KycReview::class);
    }
}
