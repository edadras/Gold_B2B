<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Domain\OrganizationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Never updated, never deleted — corrections are new rows.
 *
 * @property OrganizationStatus|null $from_status
 * @property OrganizationStatus $to_status
 */
class OrganizationStatusEvent extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $table = 'organization_status_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_status' => OrganizationStatus::class,
            'to_status' => OrganizationStatus::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
