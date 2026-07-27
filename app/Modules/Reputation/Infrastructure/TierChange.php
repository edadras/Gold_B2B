<?php

declare(strict_types=1);

namespace App\Modules\Reputation\Infrastructure;

use App\Modules\Reputation\Domain\VerificationTier;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $organization_id
 * @property VerificationTier|null $from_tier
 * @property VerificationTier $to_tier
 * @property string $direction
 * @property int|null $reviewer_user_id
 * @property string|null $reason
 */
final class TierChange extends Model
{
    public const UPDATED_AT = null;

    public const PROMOTION = 'PROMOTION';

    public const DEMOTION = 'DEMOTION';

    protected $table = 'reputation_tier_changes';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'reviewer_user_id' => 'integer',
        'from_tier' => VerificationTier::class,
        'to_tier' => VerificationTier::class,
        'promotion_locked_until' => 'datetime',
        'created_at' => 'datetime',
    ];
}
