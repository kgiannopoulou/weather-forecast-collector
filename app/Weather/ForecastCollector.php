<?php

namespace App\Weather;

use App\Models\ForecastRun;
use App\Models\Location;
use App\Models\Provider;
use App\Weather\Contracts\WeatherProvider;
use App\Weather\Data\DailyPoint;
use App\Weather\Data\HourlyPoint;
use App\Weather\Exceptions\ProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches one provider's forecast for one location and stores it as that
 * day's snapshot (forecast_runs row + hourly/daily rows).
 *
 * Guarantees:
 *  - idempotent: a successful snapshot for (location, provider, day) is never
 *    refetched unless forced, so re-running costs no API quota;
 *  - isolated: a failing provider is recorded as a failed run and never
 *    prevents other providers/locations from being collected;
 *  - atomic: rows of a snapshot are replaced in a single transaction, so a
 *    snapshot is either complete or absent;
 *  - non-destructive: a failed forced refetch keeps the existing good data.
 */
class ForecastCollector
{
    public function __construct(
        private readonly int $forecastDays,
        private readonly bool $storeRawPayload,
    ) {}

    public function collect(
        Location $location,
        WeatherProvider $provider,
        CarbonImmutable $runDate,
        bool $force = false,
    ): CollectionResult {
        $providerModel = Provider::firstOrCreate(['slug' => $provider->slug()], ['name' => $provider->name()]);

        $run = ForecastRun::firstOrNew([
            'location_id' => $location->id,
            'provider_id' => $providerModel->id,
            'run_date' => $runDate->toDateString(),
        ]);

        $alreadySucceeded = $run->exists && $run->status === ForecastRun::STATUS_SUCCESS;
        if ($alreadySucceeded && ! $force) {
            return CollectionResult::skipped($run);
        }

        $run->attempts = ($run->attempts ?? 0) + 1;
        $days = max(1, min($this->forecastDays, $provider->maxForecastDays()));

        try {
            $forecast = $provider->fetch($location, $days);

            if ($forecast->hourly === [] && $forecast->daily === []) {
                throw ProviderException::invalidPayload($provider->name(), 'no forecast data returned');
            }
        } catch (Throwable $e) {
            if (! $e instanceof ProviderException) {
                report($e); // unexpected bug, surface it to the error tracker
            }

            Log::warning('Forecast collection failed', [
                'provider' => $provider->slug(),
                'location_id' => $location->id,
                'run_date' => $runDate->toDateString(),
                'error' => $e->getMessage(),
            ]);

            if (! $alreadySucceeded) {
                $run->fill(['status' => ForecastRun::STATUS_FAILED, 'error' => $e->getMessage()])->save();
            }

            return CollectionResult::failed($run, $e->getMessage());
        }

        $hourly = $this->hourlyRows($forecast->hourly);
        $daily = $this->dailyRows($forecast->daily);

        DB::transaction(function () use ($run, $forecast, $hourly, $daily) {
            $run->fill([
                'status' => ForecastRun::STATUS_SUCCESS,
                'fetched_at' => CarbonImmutable::now('UTC'),
                'error' => null,
                'raw_payload' => $this->storeRawPayload ? $forecast->rawPayload : null,
            ])->save();

            $run->hourly()->delete();
            $run->daily()->delete();

            foreach (array_chunk($hourly, 500) as $chunk) {
                $run->hourly()->insert(array_map(fn ($row) => $row + ['forecast_run_id' => $run->id], $chunk));
            }
            foreach (array_chunk($daily, 500) as $chunk) {
                $run->daily()->insert(array_map(fn ($row) => $row + ['forecast_run_id' => $run->id], $chunk));
            }
        });

        return CollectionResult::collected($run, count($hourly), count($daily));
    }

    /**
     * @param  list<HourlyPoint>  $points
     * @return list<array<string, mixed>>
     */
    private function hourlyRows(array $points): array
    {
        $rows = [];
        foreach ($points as $point) {
            $key = $point->validAt->utc()->format('Y-m-d H:i:s');
            $rows[$key] = [ // keyed to drop duplicate timestamps defensively
                'valid_at' => $key,
                'temperature_c' => $point->temperatureC,
                'precipitation_mm' => $point->precipitationMm,
            ];
        }

        return array_values($rows);
    }

    /**
     * @param  list<DailyPoint>  $points
     * @return list<array<string, mixed>>
     */
    private function dailyRows(array $points): array
    {
        $rows = [];
        foreach ($points as $point) {
            $rows[$point->date] = [
                'date' => $point->date,
                'temperature_min_c' => $point->temperatureMinC,
                'temperature_max_c' => $point->temperatureMaxC,
                'precipitation_mm' => $point->precipitationMm,
            ];
        }

        return array_values($rows);
    }
}
