<?php

declare(strict_types=1);

namespace App\Modules\Counterparty\Infrastructure;

use App\Modules\Counterparty\Domain\MovementKind;
use Illuminate\Database\Eloquent\Model;

/**
 * One delta applied to one side of a relation. Append-only: corrections are new
 * reversing rows, never edits, so a statement reprinted next year still shows
 * what the parties saw at the time.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $counterparty_org_id
 * @property int $gold_delta_mg
 * @property int $rial_delta
 * @property MovementKind $kind
 * @property string|null $reference
 * @property string|null $description
 */
final class CounterpartyMovement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'counterparty_movements';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'counterparty_org_id' => 'integer',
        'gold_delta_mg' => 'integer',
        'rial_delta' => 'integer',
        'kind' => MovementKind::class,
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
