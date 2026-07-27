<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Models;

use App\Modules\Risk\Domain\LimitIncreaseStatus;
use App\Modules\Risk\Domain\LimitType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $requested_by_user_id
 * @property LimitType $limit_type
 * @property int $current_value
 * @property int $requested_value
 * @property string|null $justification
 * @property LimitIncreaseStatus $status
 * @property list<string> $failed_prerequisites
 * @property array<string, mixed> $prerequisite_snapshot
 * @property int|null $reviewed_by_user_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $decision_notes
 * @property int|null $required_collateral_rial
 * @property CarbonImmutable|null $next_review_at
 */
final class LimitIncreaseRequest extends Model
{
    protected $table = 'limit_increase_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'limit_type' => LimitType::class,
            'status' => LimitIncreaseStatus::class,
            'current_value' => 'int',
            'requested_value' => 'int',
            'failed_prerequisites' => 'array',
            'prerequisite_snapshot' => 'array',
            'required_collateral_rial' => 'int',
            'reviewed_at' => 'immutable_datetime',
            'next_review_at' => 'immutable_datetime',
        ];
    }
}
