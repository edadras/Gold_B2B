<?php

declare(strict_types=1);

namespace App\Modules\Trading\Http\Resources;

use App\Modules\Shared\Http\Resources\ApiResource;
use App\Modules\Shared\Http\Support\Display;
use App\Modules\Trading\Infrastructure\Models\RfqQuote;
use Illuminate\Http\Request;

/**
 * One answer to an RFQ.
 *
 * The soft reservation is exposed to the quoter alone: it is the quoter's own
 * balance commitment, and showing it to the requester would reveal how much
 * inventory the quoter is sitting on.
 *
 * @mixin RfqQuote
 */
final class RfqQuoteResource extends ApiResource
{
    public function __construct(mixed $resource, private readonly int $viewerOrganizationId)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RfqQuote $quote */
        $quote = $this->resource;

        $isQuoter = (int) $quote->quoter_organization_id === $this->viewerOrganizationId;

        return array_filter([
            'id' => (int) $quote->id,
            'quote_code' => (string) $quote->quote_code,
            'rfq_id' => (int) $quote->rfq_id,
            'quoter_organization_id' => (int) $quote->quoter_organization_id,
            'is_mine' => $isQuoter,
            'quantity_mg' => (int) $quote->quantity_mg,
            'accepted_mg' => (int) $quote->accepted_mg,
            'remaining_mg' => $quote->remainingMg(),
            'price_per_gram_rial' => (int) $quote->price_per_gram_rial,
            'soft_reserved_mg' => $isQuoter ? (int) $quote->soft_reserved_mg : null,
            'soft_reserved_rial' => $isQuoter ? (int) $quote->soft_reserved_rial : null,
            'status' => $quote->status->value,
            'valid_until' => Display::iso($quote->valid_until),
            'responded_at' => Display::iso($quote->responded_at),
        ], static fn ($v) => $v !== null) + $this->display($request, [
            'quantity_display' => Display::grams((int) $quote->quantity_mg),
            'price_display' => Display::rial((int) $quote->price_per_gram_rial),
            'valid_until_jalali' => Display::jalali($quote->valid_until),
        ]);
    }
}
