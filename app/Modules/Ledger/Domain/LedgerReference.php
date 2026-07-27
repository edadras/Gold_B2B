<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * What caused an entry: the source document every ledger row points back at.
 *
 * Stored as (reference_type, reference_id) so the ledger never holds a foreign
 * key into another module's tables — see AGENT_BRIEF rule 7.
 */
final readonly class LedgerReference implements JsonSerializable, Stringable
{
    public const ORDER = 'order';

    public const TRADE = 'trade';

    public const SETTLEMENT = 'settlement';

    public const DISPUTE = 'dispute';

    public const CUSTODY = 'custody';

    public const ADJUSTMENT = 'adjustment';

    public const TEST = 'test';

    private function __construct(public string $type, public int $id)
    {
        if ($type === '' || strlen($type) > 50) {
            throw new InvalidArgumentException('Reference type must be 1..50 characters');
        }

        if ($id < 0) {
            throw new InvalidArgumentException("Reference id cannot be negative, got: {$id}");
        }
    }

    public static function of(string $type, int $id): self
    {
        return new self($type, $id);
    }

    public static function order(int $orderId): self
    {
        return new self(self::ORDER, $orderId);
    }

    public static function trade(int $tradeId): self
    {
        return new self(self::TRADE, $tradeId);
    }

    public static function settlement(int $settlementId): self
    {
        return new self(self::SETTLEMENT, $settlementId);
    }

    public static function dispute(int $disputeId): self
    {
        return new self(self::DISPUTE, $disputeId);
    }

    public static function custody(int $operationId): self
    {
        return new self(self::CUSTODY, $operationId);
    }

    public static function adjustment(int $adjustmentId): self
    {
        return new self(self::ADJUSTMENT, $adjustmentId);
    }

    /** Fixture reference for tests and fixtures only. */
    public static function test(int $id = 1): self
    {
        return new self(self::TEST, $id);
    }

    /** Canonical "type:id" form — this is what the hash chain covers. */
    public function key(): string
    {
        return $this->type.':'.$this->id;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    /** @return array{type: string, id: int} */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }

    public function __toString(): string
    {
        return $this->key();
    }
}
