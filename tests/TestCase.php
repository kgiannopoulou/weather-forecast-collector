<?php

namespace Tests;

use App\Models\Location;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected const OPEN_METEO_URL = 'api.open-meteo.com/*';

    protected const WEATHER_API_URL = 'api.weatherapi.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests(); // tests must never hit the real APIs
        config(['weather.http.retry_delay_ms' => 0]);
    }

    protected function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__."/Fixtures/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Replace any previously registered fakes. Laravel merges repeated
     * Http::fake() calls and the first matching stub wins, so tests that
     * change provider responses mid-test start from a fresh factory.
     */
    protected function fakeHttp(array $stubs): Factory
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();

        return Http::fake($stubs);
    }

    protected function fakeProviders(?array $openMeteo = null, ?array $weatherApi = null): Factory
    {
        return $this->fakeHttp([
            self::OPEN_METEO_URL => Http::response($openMeteo ?? $this->fixture('open_meteo_forecast')),
            self::WEATHER_API_URL => Http::response($weatherApi ?? $this->fixture('weather_api_forecast')),
        ]);
    }

    protected function larissa(): Location
    {
        return Location::create([
            'name' => 'Larissa',
            'latitude' => 39.639,
            'longitude' => 22.419,
            'timezone' => 'Europe/Athens',
        ]);
    }
}
