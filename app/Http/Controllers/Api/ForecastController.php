<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyForecast;
use App\Models\ForecastRun;
use App\Models\HourlyForecast;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read API over collected snapshots.
 *
 * A "snapshot" (forecast run) is what one provider predicted on one day. By
 * default the daily/hourly endpoints serve the latest snapshot per provider;
 * `run_date` selects an older one.
 */
class ForecastController extends Controller
{
    /** GET /api/locations/{location}/forecasts/daily */
    public function daily(Request $request, Location $location): JsonResponse
    {
        $filters = $request->validate([
            'provider' => ['sometimes', 'string'],
            'run_date' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $runs = $this->snapshots($location, $filters);
        $runs->load(['daily' => fn ($q) => $q
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('date', '<=', $d))
            ->orderBy('date')]);

        return response()->json([
            'location' => $location->toApiArray(),
            'data' => $runs->map(fn (ForecastRun $run) => $run->toApiSummary() + [
                'days' => $run->daily->map(fn (DailyForecast $d) => $this->dailyArray($d))->values(),
            ])->values(),
        ]);
    }

    /** GET /api/locations/{location}/forecasts/hourly */
    public function hourly(Request $request, Location $location): JsonResponse
    {
        $filters = $request->validate([
            'provider' => ['sometimes', 'string'],
            'run_date' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $toUtc = fn (string $value) => CarbonImmutable::parse($value, $location->timezone)->utc()->format('Y-m-d H:i:s');

        $runs = $this->snapshots($location, $filters);
        $runs->load(['hourly' => fn ($q) => $q
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('valid_at', '>=', $toUtc($v)))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('valid_at', '<=', $toUtc($v)))
            ->orderBy('valid_at')]);

        return response()->json([
            'location' => $location->toApiArray(),
            'data' => $runs->map(fn (ForecastRun $run) => $run->toApiSummary() + [
                'hours' => $run->hourly->map(fn (HourlyForecast $h) => [
                    'valid_at' => $h->valid_at->utc()->toIso8601ZuluString(),
                    'local_time' => $h->valid_at->setTimezone($location->timezone)->toIso8601String(),
                    'temperature_c' => $h->temperature_c,
                    'precipitation_mm' => $h->precipitation_mm,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * GET /api/locations/{location}/forecasts/compare?date=Y-m-d
     *
     * Side-by-side: each provider's most recent forecast for that day, plus the
     * spread between providers (a simple measure of forecast uncertainty).
     */
    public function compare(Request $request, Location $location): JsonResponse
    {
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];

        $rows = $this->dailyRowsFor($location, $date)
            ->orderByDesc('forecast_runs.run_date')
            ->get()
            ->unique('provider_slug')
            ->values();

        $spread = fn (string $field) => $rows->pluck($field)->filter(fn ($v) => $v !== null)->whenNotEmpty(
            fn ($values) => round($values->max() - $values->min(), 2),
            fn () => null,
        );

        return response()->json([
            'location' => $location->toApiArray(),
            'date' => $date,
            'providers' => $rows->map(fn ($row) => $this->comparisonRow($row, $date)),
            'spread' => $rows->count() < 2 ? null : [
                'temperature_min_c' => $spread('temperature_min_c'),
                'temperature_max_c' => $spread('temperature_max_c'),
                'precipitation_mm' => $spread('precipitation_mm'),
            ],
        ]);
    }

    /**
     * GET /api/locations/{location}/forecasts/history?date=Y-m-d[&provider=]
     *
     * How the forecast for one day evolved across daily snapshots: shows how
     * much each provider "changed its mind" as the day approached.
     */
    public function history(Request $request, Location $location): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'provider' => ['sometimes', 'string'],
        ]);

        $rows = $this->dailyRowsFor($location, $filters['date'])
            ->when($filters['provider'] ?? null, fn ($q, $slug) => $q->where('providers.slug', $slug))
            ->orderBy('forecast_runs.run_date')
            ->get();

        return response()->json([
            'location' => $location->toApiArray(),
            'date' => $filters['date'],
            'providers' => $rows->groupBy('provider_slug')->map(
                fn ($group) => $group->map(fn ($row) => $this->comparisonRow($row, $filters['date']))->values()
            ),
        ]);
    }

    /** Snapshots for a location: a specific day, or the latest one per provider. */
    private function snapshots(Location $location, array $filters): Collection
    {
        return ForecastRun::query()
            ->select(['id', 'location_id', 'provider_id', 'run_date', 'status', 'fetched_at'])
            ->where('location_id', $location->id)
            ->when(
                $filters['run_date'] ?? null,
                fn (Builder $q, string $runDate) => $q->successful()->where('run_date', $runDate),
                fn (Builder $q) => $q->latestPerProvider(),
            )
            ->when($filters['provider'] ?? null, fn (Builder $q, string $slug) => $q->whereRelation('provider', 'slug', $slug))
            ->with('provider:id,slug,name')
            ->orderBy('provider_id')
            ->get();
    }

    /** Daily rows for a target date from every successful snapshot taken on or before it. */
    private function dailyRowsFor(Location $location, string $date): Builder
    {
        return DailyForecast::query()
            ->join('forecast_runs', 'forecast_runs.id', '=', 'daily_forecasts.forecast_run_id')
            ->join('providers', 'providers.id', '=', 'forecast_runs.provider_id')
            ->where('forecast_runs.location_id', $location->id)
            ->where('forecast_runs.status', ForecastRun::STATUS_SUCCESS)
            ->where('forecast_runs.run_date', '<=', $date)
            ->where('daily_forecasts.date', $date)
            ->select([
                'daily_forecasts.*',
                'providers.slug as provider_slug',
                'forecast_runs.run_date',
                'forecast_runs.fetched_at',
            ]);
    }

    private function dailyArray(DailyForecast $d): array
    {
        return [
            'date' => $d->date,
            'temperature_min_c' => $d->temperature_min_c,
            'temperature_max_c' => $d->temperature_max_c,
            'precipitation_mm' => $d->precipitation_mm,
        ];
    }

    private function comparisonRow(DailyForecast $row, string $date): array
    {
        return [
            'provider' => $row->provider_slug,
            'run_date' => $row->run_date,
            'lead_days' => (int) CarbonImmutable::parse($row->run_date)->diffInDays(CarbonImmutable::parse($date)),
            'temperature_min_c' => $row->temperature_min_c,
            'temperature_max_c' => $row->temperature_max_c,
            'precipitation_mm' => $row->precipitation_mm,
        ];
    }
}
