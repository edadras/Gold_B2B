<?php

declare(strict_types=1);

namespace App\Modules\Trading\Infrastructure\Models;

use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\RfqStatus;
use App\Modules\Trading\Domain\RfqVisibility;
use App\Modules\Trading\Domain\SettlementType;
use App\Modules\Trading\Domain\Side;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $rfq_code
 * @property int $instrument_id
 * @property int $organization_id
 * @property Side $side
 * @property int $quantity_mg
 * @property int $accepted_mg
 * @property RfqVisibility $visibility
 * @property ?array<int, int> $recipient_org_ids
 * @property bool $allow_partial
 * @property RfqStatus $status
 * @property CarbonImmutable $expires_at
 */
final class Rfq extends Model
{
    protected $table = 'rfqs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'side' => Side::class,
            'status' => RfqStatus::class,
            'visibility' => RfqVisibility::class,
            'settlement_type' => SettlementType::class,
            'delivery_type' => DeliveryType::class,
            'recipient_org_ids' => 'array',
            'allow_partial' => 'boolean',
            'quantity_mg' => 'integer',
            'accepted_mg' => 'integer',
            'min_purity_x10' => 'integer',
            'expires_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<RfqQuote, $this> */
    public function quotes(): HasMany
    {
        return $this->hasMany(RfqQuote::class, 'rfq_id');
    }

    public function remainingMg(): int
    {
        return $this->quantity_mg - $this->accepted_mg;
    }

    public function isVisibleTo(int $organizationId): bool
    {
        if ($this->visibility === RfqVisibility::SELECTED) {
            return in_array($organizationId, $this->recipient_org_ids ?? [], true);
        }

        return true;
    }
}
