<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Models;

use App\Modules\Trading\Domain\RfqQuoteStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $quote_code
 * @property int $rfq_id
 * @property int $quoter_organization_id
 * @property int $quantity_mg
 * @property int $accepted_mg
 * @property int $price_per_gram_rial
 * @property int $soft_reserved_mg
 * @property int $soft_reserved_rial
 * @property ?int $hard_reservation_entry_id
 * @property RfqQuoteStatus $status
 * @property CarbonImmutable $valid_until
 */
final class RfqQuote extends Model
{
    protected $table = 'rfq_quotes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RfqQuoteStatus::class,
            'quantity_mg' => 'integer',
            'accepted_mg' => 'integer',
            'price_per_gram_rial' => 'integer',
            'soft_reserved_mg' => 'integer',
            'soft_reserved_rial' => 'integer',
            'hard_reservation_entry_id' => 'integer',
            'valid_until' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Rfq, $this> */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function remainingMg(): int
    {
        return $this->quantity_mg - $this->accepted_mg;
    }
}
