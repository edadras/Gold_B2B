<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Models;

use App\Modules\Kyc\Domain\KycDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Never updated, never deleted.
 *
 * @property KycDecision $decision
 * @property string $notes
 */
class KycReview extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $table = 'kyc_reviews';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'decision' => KycDecision::class,
            'automated_checks' => 'array',
            'missing_items' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<KycProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(KycProfile::class, 'kyc_profile_id');
    }
}
