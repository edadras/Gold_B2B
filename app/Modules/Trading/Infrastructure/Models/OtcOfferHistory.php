<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One negotiation round. Append-only — a dispute is resolved by reading it.
 *
 * @property int $id
 * @property int $otc_offer_id
 * @property int $round_no
 * @property string $action
 */
final class OtcOfferHistory extends Model
{
    public const ACTION_CREATED = 'CREATED';

    public const ACTION_COUNTERED = 'COUNTERED';

    public const ACTION_ACCEPTED = 'ACCEPTED';

    public const ACTION_REJECTED = 'REJECTED';

    public const ACTION_CANCELLED = 'CANCELLED';

    public const ACTION_EXPIRED = 'EXPIRED';

    public $timestamps = false;

    protected $table = 'otc_offer_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'round_no' => 'integer',
            'quantity_mg' => 'integer',
            'price_rial' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
