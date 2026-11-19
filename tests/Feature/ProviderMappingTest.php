<?php

namespace Tests\Feature;

use App\Weather\Exceptions\ProviderException;
use App\Weather\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_meteo_is_normalised_to_utc_hours_and_local_days(): void
    {
        $this->fakeProviders();

        $forecast = app(ProviderRegistry::class)->get('open_meteo')->fetch($this->larissa(), 2);

        $this->assertCount(48, $forecast->hourly);
        // 00:00 in Athens (UTC+2) is stored as 22:00 UTC the previous day.
        $this->assertSame('2026-11-18T22:00:00Z', $forecast->hourly[0]->validAt->toIso8601ZuluString());
        $this->assertSame(13.5, $forecast->hourly[0]->temperatureC);
        $this->assertSame(0.0, $forecast->hourly[0]->precipitationMm);

        $this->assertSame(['2026-11-19', '2026-11-20'], array_map(fn ($d) => $d->date, $forecast->daily));
        $this->assertSame(11.0, $forecast->daily[0]->temperatureMinC);
        $this->assertSame(25.0, $forecast->daily[0]->temperatureMaxC);

        Http::assertSent(fn (Request $r) => $r['timezone'] === 'Europe/Athens'
            && $r['timeformat'] === 'unixtime'
            && (int) $r['forecast_days'] === 2);
    }

    public function test_weather_api_is_normalised(): void
    {
        $this->fakeProviders();

        $forecast = app(ProviderRegistry::class)->get('weather_api')->fetch($this->larissa(), 2);

        $this->assertCount(4, $forecast->hourly);
        $this->assertSame('2026-11-18T22:00:00Z', $forecast->hourly[0]->validAt->toIso8601ZuluString());
        $this->assertSame(13.1, $forecast->hourly[0]->temperatureC);
        $this->assertSame(0.3, $forecast->hourly[3]->precipitationMm);
        $this->assertSame('2026-11-20', $forecast->daily[1]->date);
        $this->assertSame(1.8, $forecast->daily[1]->precipitationMm);

        Http::assertSent(fn (Request $r) => $r['key'] === 'test-key' && $r['q'] === '39.63900,22.41900');
    }

    public function test_weather_api_without_key_fails_without_calling_the_api(): void
    {
        config(['weather.providers.weather_api.api_key' => null]);
        Http::fake();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('WEATHERAPI_KEY is not set');

        try {
            app(ProviderRegistry::class)->get('weather_api')->fetch($this->larissa(), 3);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_transient_errors_are_retried(): void
    {
        Http::fake([
            self::OPEN_METEO_URL => Http::sequence()
                ->push(['error' => true, 'reason' => 'busy'], 503)
                ->push(['error' => true, 'reason' => 'slow down'], 429)
                ->push($this->fixture('open_meteo_forecast')),
        ]);

        $forecast = app(ProviderRegistry::class)->get('open_meteo')->fetch($this->larissa(), 2);

        $this->assertCount(48, $forecast->hourly);
        Http::assertSentCount(3);
    }

    public function test_client_errors_are_not_retried(): void
    {
        Http::fake([
            self::WEATHER_API_URL => Http::response(['error' => ['code' => 2006, 'message' => 'API key is invalid.']], 401),
        ]);

        try {
            app(ProviderRegistry::class)->get('weather_api')->fetch($this->larissa(), 3);
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame('WeatherAPI responded with HTTP 401. API key is invalid.', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_malformed_payload_is_rejected(): void
    {
        Http::fake([self::OPEN_METEO_URL => Http::response(['latitude' => 39.6])]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('unexpected payload');

        app(ProviderRegistry::class)->get('open_meteo')->fetch($this->larissa(), 2);
    }
}
