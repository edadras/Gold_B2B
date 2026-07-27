<?php

declare(strict_types=1);

namespace App\Modules\Trading\Domain\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * The order violates one of the instrument's trading parameters: tick size, lot
 * size, minimum or maximum quantity (§4.1).
 */
final class InvalidOrderException extends DomainException
{
    /** @param array<string, mixed> $context */
    private function __construct(
        private readonly string $code,
        private readonly string $persianMessage,
        private readonly array $context,
        string $developerMessage,
    ) {
        parent::__construct($developerMessage);
    }

    public static function belowMinimum(int $quantityMg, int $minimumMg): self
    {
        return new self(
            'ORDER_BELOW_MINIMUM',
            'حجم سفارش کمتر از حداقل مجاز این ابزار است.',
            ['quantity_mg' => $quantityMg, 'minimum_mg' => $minimumMg],
            "Order quantity {$quantityMg}mg is below the instrument minimum {$minimumMg}mg",
        );
    }

    public static function aboveMaximum(int $quantityMg, int $maximumMg): self
    {
        return new self(
            'ORDER_ABOVE_MAXIMUM',
            'حجم سفارش بیشتر از حداکثر مجاز این ابزار است.',
            ['quantity_mg' => $quantityMg, 'maximum_mg' => $maximumMg],
            "Order quantity {$quantityMg}mg exceeds the instrument maximum {$maximumMg}mg",
        );
    }

    public static function notALotMultiple(int $quantityMg, int $lotSizeMg): self
    {
        return new self(
            'ORDER_LOT_SIZE',
            'حجم سفارش باید مضربی از گام وزنی ابزار باشد.',
            ['quantity_mg' => $quantityMg, 'lot_size_mg' => $lotSizeMg],
            "Order quantity {$quantityMg}mg is not a multiple of lot size {$lotSizeMg}mg",
        );
    }

    public static function notATickMultiple(int $priceRial, int $tickSizeRial): self
    {
        return new self(
            'ORDER_TICK_SIZE',
            'قیمت باید مضربی از گام قیمتی ابزار باشد.',
            ['price_rial' => $priceRial, 'tick_size_rial' => $tickSizeRial],
            "Price {$priceRial} is not a multiple of tick size {$tickSizeRial}",
        );
    }

    public static function missingPrice(): self
    {
        return new self(
            'ORDER_PRICE_REQUIRED',
            'برای سفارش محدود باید قیمت مشخص شود.',
            [],
            'A LIMIT order requires a price',
        );
    }

    public static function instrumentNotTradable(string $code, string $status): self
    {
        return new self(
            'INSTRUMENT_NOT_TRADABLE',
            'این ابزار در حال حاضر قابل معامله نیست.',
            ['instrument' => $code, 'status' => $status],
            "Instrument {$code} is {$status}",
        );
    }

    public static function noMarketDepthForMarketOrder(): self
    {
        return new self(
            'NO_MARKET_DEPTH',
            'در حال حاضر سفارش مقابلی برای اجرای سفارش بازار وجود ندارد.',
            [],
            'A MARKET order needs a resting opposite side to size its reservation',
        );
    }

    public static function notCancellable(int $orderId, string $status): self
    {
        return new self(
            'ORDER_NOT_CANCELLABLE',
            'این سفارش دیگر قابل لغو نیست.',
            ['order_id' => $orderId, 'status' => $status],
            "Order {$orderId} is {$status} and cannot be cancelled",
        );
    }

    public function errorCode(): string
    {
        return $this->code;
    }

    public function userMessage(): string
    {
        return $this->persianMessage;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->context;
    }
}
