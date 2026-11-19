<?php

namespace App\Weather\Providers;

use App\Models\Location;
use App\Weather\Data\DailyPoint;
use App\Weather\Data\HourlyPoint;
use App\Weather\Data\ProviderForecast;
use App\Weather\Exceptions\ProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

/**
 * https://open-meteo.com/en/docs - free, no API key, up to 16 forecast days.
 *
 * We pass the location timezone so daily aggregates follow the local day, and
 * ask for unix timestamps so hourly values are unambiguous across DST changes.
 * Note: Open-Meteo hourly `precipitation` is the sum over the preceding hour.
 */
class OpenMeteoProvider extends HttpWeatherProvider
{
    public function name(): string
    {
        return 'Open-Meteo';
    }

    public function fetch(Location $location, int $days): ProviderForecast
    {
        $payload = $this->getJson('/forecast', [
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'hourly' => 'temperature_2m,precipitation',
            'daily' => 'temperature_2m_min,temperature_2m_max,precipitation_sum',
            'timezone' => $location->timezone,
            'timeformat' => 'unixtime',
            'temperature_unit' => 'celsius',
            'precipitation_unit' => 'mm',
            'forecast_days' => $days,
        ]);

        $hourlyTimes = data_get($payload, 'hourly.time');
        $dailyTimes = data_get($payload, 'daily.time');
        if (! is_array($hourlyTimes) || ! is_array($dailyTimes)) {
            throw ProviderException::invalidPayload($this->name(), 'missing hourly.time or daily.time');
        }

        $hourly = [];
        foreach ($hourlyTimes as $i => $timestamp) {
            $hourly[] = new HourlyPoint(
                CarbonImmutable::createFromTimestampUTC((int) $timestamp),
                self::number(data_get($payload, "hourly.temperature_2m.$i")),
                self::number(data_get($payload, "hourly.precipitation.$i")),
            );
        }

        // Daily timestamps are local midnight; convert with the real timezone (not a
        // fixed UTC offset) so DST changes inside the forecast window are handled.
        $timezone = $payload['timezone'] ?? $location->timezone;
        $daily = [];
        foreach ($dailyTimes as $i => $timestamp) {
            $daily[] = new DailyPoint(
                CarbonImmutable::createFromTimestamp((int) $timestamp, $timezone)->toDateString(),
                self::number(data_get($payload, "daily.temperature_2m_min.$i")),
                self::number(data_get($payload, "daily.temperature_2m_max.$i")),
                self::number(data_get($payload, "daily.precipitation_sum.$i")),
            );
        }

        return new ProviderForecast($hourly, $daily, $payload);
    }

    protected function errorDetail(Response $response): ?string
    {
        return $response->json('reason');
    }
}
