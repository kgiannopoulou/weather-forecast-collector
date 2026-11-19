<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Weather\CollectionResult;
use App\Weather\ForecastCollector;
use App\Weather\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CollectForecasts extends Command
{
    protected $signature = 'forecasts:collect
        {--provider=* : Only these provider slugs (default: all enabled)}
        {--location=* : Only these location ids (default: all active)}
        {--force : Refetch even if today\'s snapshot already exists}';

    protected $description = 'Fetch and store today\'s forecast snapshot for every active location and enabled provider';

    public function handle(ProviderRegistry $registry, ForecastCollector $collector): int
    {
        try {
            $providers = $this->option('provider')
                ? array_map(fn (string $slug) => $registry->get($slug), $this->option('provider'))
                : array_values($registry->enabled());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $locations = Location::query()
            ->where('is_active', true)
            ->when($this->option('location'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        if ($locations->isEmpty() || $providers === []) {
            $this->warn('Nothing to collect: no active locations or no enabled providers.');

            return self::SUCCESS;
        }

        // Snapshots are labelled with the UTC date they were taken on.
        $runDate = CarbonImmutable::now('UTC')->startOfDay();
        $rows = [];
        $failures = 0;

        foreach ($locations as $location) {
            foreach ($providers as $provider) {
                $result = $collector->collect($location, $provider, $runDate, (bool) $this->option('force'));
                $failures += $result->isFailure() ? 1 : 0;

                $rows[] = [
                    $location->name,
                    $provider->slug(),
                    $result->outcome,
                    $result->outcome === CollectionResult::COLLECTED ? $result->hourlyCount : '-',
                    $result->outcome === CollectionResult::COLLECTED ? $result->dailyCount : '-',
                    $result->message,
                ];
            }
        }

        $this->info("Forecast snapshot for {$runDate->toDateString()}");
        $this->table(['Location', 'Provider', 'Outcome', 'Hourly', 'Daily', 'Message'], $rows);

        // Non-zero exit code so cron/monitoring notices partial failures.
        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
