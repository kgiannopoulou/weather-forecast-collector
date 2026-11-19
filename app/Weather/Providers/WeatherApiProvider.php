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
 * https://www.weatherapi.com/docs/ - requires an API key; the free plan returns
 * at most 3 forecast days. Days are returned in the local time of the resolved
 * location and each hour carries a UTC `time_epoch`.
 */
class WeatherApiProvider extends HttpWeatherProvider
{
    public function name(): string
    {
        return 'WeatherAPI';
    }

    public function fetch(Location $location, int $days): ProviderForecast
    {
        $key = $this->config['api_key'] ?? null;
        if (blank($key)) {
            throw ProviderException::misconfigured($this->name(), 'WEATHERAPI_KEY is not set');
        }

        $payload = $this->getJson('/forecast.json', [
            'key' => $key,
            'q' => sprintf('%.5F,%.5F', $location->latitude, $location->longitude),
            'days' => $days,
            'aqi' => 'no',
            'alerts' => 'no',
        ]);

        $forecastDays = data_get($payload, 'forecast.forecastday');
        if (! is_array($forecastDays)) {
            throw ProviderException::invalidPayload($this->name(), 'missing forecast.forecastday');
        }

        $hourly = [];
        $daily = [];
        foreach ($forecastDays as $day) {
            if (! isset($day['date'], $day['day']) || ! is_array($day['hour'] ?? null)) {
                throw ProviderException::invalidPayload($this->name(), 'malformed forecastday entry');
            }

            $daily[] = new DailyPoint(
                $day['date'],
                self::number($day['day']['mintemp_c'] ?? null),
                self::number($day['day']['maxtemp_c'] ?? null),
                self::number($day['day']['totalprecip_mm'] ?? null),
            );

            foreach ($day['hour'] as $hour) {
                $hourly[] = new HourlyPoint(
                    CarbonImmutable::createFromTimestampUTC((int) $hour['time_epoch']),
                    self::number($hour['temp_c'] ?? null),
                    self::number($hour['precip_mm'] ?? null),
                );
            }
        }

        return new ProviderForecast($hourly, $daily, $payload);
    }

    protected function errorDetail(Response $response): ?string
    {
        return $response->json('error.message');
    }
}
