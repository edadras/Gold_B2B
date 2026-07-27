<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application\Commands;

use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\RfqVisibility;
use App\Modules\Trading\Domain\Side;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** A request for quote (§4.7). */
final readonly class CreateRfqCommand
{
    /** @param list<int> $recipientOrgIds */
    public function __construct(
        public int $organizationId,
        public int $userId,
        public string $instrumentCode,
        public Side $side,
        public FineWeight $quantity,
        public CarbonImmutable $expiresAt,
        public RfqVisibility $visibility = RfqVisibility::ALL_QUALIFIED,
        public array $recipientOrgIds = [],
        public bool $allowPartial = true,
        public ?int $minPurityX10 = null,
        public ?DeliveryType $deliveryType = null,
    ) {
        if ($quantity->isZero()) {
            throw new InvalidArgumentException('RFQ quantity must be positive');
        }

        if ($visibility->requiresRecipientList() && $recipientOrgIds === []) {
            throw new InvalidArgumentException('A SELECTED RFQ needs at least one recipient');
        }

        if (in_array($organizationId, $recipientOrgIds, true)) {
            throw new InvalidArgumentException('An RFQ cannot be addressed to its own requester');
        }
    }
}
