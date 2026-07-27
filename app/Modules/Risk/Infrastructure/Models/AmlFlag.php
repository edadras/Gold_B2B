<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Models;

use App\Modules\Risk\Domain\FlagSeverity;
use App\Modules\Risk\Domain\FlagStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $rule_code
 * @property int $organization_id
 * @property int|null $user_id
 * @property FlagSeverity $severity
 * @property FlagStatus $status
 * @property string $summary
 * @property array<string, mixed> $context
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property CarbonImmutable $raised_at
 * @property int|null $assigned_to_user_id
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by_user_id
 * @property string|null $resolution_notes
 * @property string|null $action_taken
 * @property CarbonImmutable|null $reported_at
 * @property string|null $report_reference
 */
final class AmlFlag extends Model
{
    protected $table = 'aml_flags';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'severity' => FlagSeverity::class,
            'status' => FlagStatus::class,
            'context' => 'array',
            'raised_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'reported_at' => 'immutable_datetime',
        ];
    }

    /**
     * Flags that still count against the member: everything not in a final
     * state. Used by the AML component of F19 and by rule CPT-01.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [
            FlagStatus::OPEN->value,
            FlagStatus::UNDER_REVIEW->value,
            FlagStatus::ENHANCED_REVIEW->value,
            FlagStatus::ESCALATED->value,
        ]);
    }
}
