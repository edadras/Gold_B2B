<?php

declare(strict_types=1);

namespace App\Modules\Trading\Application\Commands;

use App\Modules\Shared\ValueObjects\FineWeight;
use App\Modules\Shared\ValueObjects\PricePerFineGram;
use App\Modules\Trading\Domain\DeliveryType;
use App\Modules\Trading\Domain\Side;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** The opening terms of a bilateral negotiation (§4.6). */
final readonly class CreateOtcOfferCommand
{
    public function __construct(
        public int $organizationId,
        public int $counterpartyOrganizationId,
        public int $userId,
        public string $instrumentCode,
        public Side $side,
        public FineWeight $quantity,
        public PricePerFineGram $price,
        public CarbonImmutable $expiresAt,
        public ?int $minPurityX10 = null,
        public ?DeliveryType $deliveryType = null,
    ) {
        if ($organizationId === $counterpartyOrganizationId) {
            throw new InvalidArgumentException('An OTC offer needs two distinct organisations');
        }

        if ($quantity->isZero()) {
            throw new InvalidArgumentException('OTC quantity must be positive');
        }
    }
}
