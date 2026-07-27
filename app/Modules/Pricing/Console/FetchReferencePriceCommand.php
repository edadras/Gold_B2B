<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Console;

use App\Modules\Pricing\Application\PriceIngestionService;
use App\Modules\Pricing\Application\ReferencePriceService;
use App\Modules\Pricing\Infrastructure\Drivers\PriceDriverRegistry;
use App\Modules\Pricing\Infrastructure\Models\PriceSource;
use Illuminate\Console\Command;

/**
 * Polls every enabled source through its registered driver, pushes what comes
 * back through the §7.4 filters, then recomputes the intrinsic value (F6).
 *
 * Drivers are pluggable; the ones shipped here (manual, stub) perform no
 * network I/O.
 */
final class FetchReferencePriceCommand extends Command
{
    protected $signature = 'pricing:fetch-reference
        {--source= : limit to one price_sources.code}
        {--instrument= : instrument id the reference price is stored against}';

    protected $description = 'Poll price drivers, validate the ticks and recompute the reference price';

    public function handle(
        PriceDriverRegistry $registry,
        PriceIngestionService $ingestion,
        ReferencePriceService $references,
    ): int {
        $query = PriceSource::query()->where('is_enabled', true)->orderBy('priority')->orderBy('id');

        if (is_string($this->option('source')) && $this->option('source') !== '') {
            $query->where('code', $this->option('source'));
        }

        /** @var list<PriceSource> $sources */
        $sources = $query->get()->all();

        if ($sources === []) {
            $this->warn('No enabled price sources configured.');

            return self::SUCCESS;
        }

        $accepted = 0;
        $rejected = 0;

        foreach ($sources as $source) {
            if (! $registry->has($source->driver)) {
                $this->warn("Source [{$source->code}] uses unregistered driver [{$source->driver}] — skipped.");

                continue;
            }

            $driver = $registry->get($source->driver);

            if (! $driver->supports($source->price_type)) {
                continue;
            }

            $tick = $driver->fetch($source->price_type);

            if ($tick === null) {
                $this->line("Source [{$source->code}] returned no data.");

                continue;
            }

            $result = $ingestion->ingest($tick->toIncomingTick((int) $source->id));

            if ($result->accepted) {
                $accepted++;
                $this->line("Source [{$source->code}] accepted: {$result->effectiveValue}");
            } else {
                $rejected++;
                $this->warn("Source [{$source->code}] rejected: {$result->reason?->value}");
            }
        }

        $instrumentId = (int) ($this->option('instrument') ?? config('goldb2b.pricing.default_instrument_id', 1));
        $reference = $references->compute($instrumentId);

        if ($reference === null) {
            $this->error('Reference price could not be computed — no usable ounce and FX pair.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Reference price for instrument %d: %s rial/g (accepted %d, rejected %d)',
            $instrumentId,
            number_format((float) $reference->fine_gram_rial, 0, '.', ','),
            $accepted,
            $rejected,
        ));

        return self::SUCCESS;
    }
}
