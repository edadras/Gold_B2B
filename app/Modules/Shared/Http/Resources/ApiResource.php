<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Resources;

use App\Modules\Shared\Http\Support\Display;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base API resource.
 *
 * Wrapping is disabled: ApiResponse owns the `data` / `meta` / `links` envelope
 * (docs/05-api/01-conventions.md §1.4), and a resource that wrapped itself
 * would produce `data.data`.
 *
 * Two rules every subclass follows:
 *   · money is an int rial and weight an int milligram — never a float, never
 *     a string;
 *   · `_display` and `_jalali` companions are additive and suppressible with
 *     `?include_display=false`.
 */
abstract class ApiResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Merge display companions only when the client wants them.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function display(Request $request, array $fields): array
    {
        return Display::enabledFor($request) ? array_filter($fields, static fn ($v) => $v !== null) : [];
    }

    /** @param iterable<mixed> $items */
    public static function forCollection(iterable $items): AnonymousResourceCollection
    {
        return static::collection($items);
    }
}
