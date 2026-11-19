<?php

namespace Tests\Feature;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastApiTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->location = $this->larissa();

        // Snapshot 1, taken on 2026-11-19.
        $this->travelTo('2026-11-19 06:15:00');
        $this->fakeProviders();
        $this->artisan('forecasts:collect')->assertSuccessful();

        // Snapshot 2, taken on 2026-11-20: WeatherAPI "changes its mind" about the 20th.
        $this->travelTo('2026-11-20 06:15:00');
        $weatherApi = $this->fixture('weather_api_forecast');
        $weatherApi['forecast']['forecastday'][1]['day']['maxtemp_c'] = 19.5;
        $weatherApi['forecast']['forecastday'][1]['day']['totalprecip_mm'] = 6.2;
        $this->fakeProviders(weatherApi: $weatherApi);
        $this->artisan('forecasts:collect')->assertSuccessful();
    }

    private function url(string $endpoint): string
    {
        return "/api/locations/{$this->location->id}/forecasts/{$endpoint}";
    }

    public function test_daily_returns_the_latest_snapshot_per_provider(): void
    {
        $response = $this->getJson($this->url('daily'))->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('location.name', 'Larissa');
        $response->assertJsonPath('data.0.provider', 'open_meteo');
        $response->assertJsonPath('data.0.run_date', '2026-11-20');
        $response->assertJsonPath('data.1.provider', 'weather_api');
        $response->assertJsonPath('data.1.days.1', [
            'date' => '2026-11-20',
            'temperature_min_c' => 12,
            'temperature_max_c' => 19.5,
            'precipitation_mm' => 6.2,
        ]);
    }

    public function test_daily_can_select_an_older_snapshot_and_filter(): void
    {
        $this->getJson($this->url('daily').'?provider=weather_api&run_date=2026-11-19&from=2026-11-20&to=2026-11-20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.days')
            ->assertJsonPath('data.0.days.0.temperature_max_c', 22.3);
    }

    public function test_hourly_returns_utc_and_local_times(): void
    {
        $this->getJson($this->url('hourly').'?provider=weather_api&from=2026-11-20T00:00&to=2026-11-20T00:59')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.hours')
            ->assertJsonPath('data.0.hours.0', [
                'valid_at' => '2026-11-19T22:00:00Z',
                'local_time' => '2026-11-20T00:00:00+02:00',
                'temperature_c' => 14,
                'precipitation_mm' => 0,
            ]);
    }

    public function test_compare_shows_each_provider_latest_forecast_and_spread(): void
    {
        $response = $this->getJson($this->url('compare').'?date=2026-11-20')->assertOk();

        $response->assertJsonCount(2, 'providers');
        $weatherApi = collect($response->json('providers'))->firstWhere('provider', 'weather_api');
        $this->assertSame('2026-11-20', $weatherApi['run_date']);
        $this->assertSame(0, $weatherApi['lead_days']);
        $this->assertEquals(19.5, $weatherApi['temperature_max_c']);

        // Open-Meteo fixture says 24.7 max for the 20th; WeatherAPI now says 19.5.
        $response->assertJsonPath('spread.temperature_max_c', 5.2);
    }

    public function test_history_shows_how_a_forecast_evolved(): void
    {
        $response = $this->getJson($this->url('history').'?date=2026-11-20&provider=weather_api')->assertOk();

        $response->assertJsonCount(2, 'providers.weather_api');
        $response->assertJsonPath('providers.weather_api.0.lead_days', 1);
        $response->assertJsonPath('providers.weather_api.0.precipitation_mm', 1.8);
        $response->assertJsonPath('providers.weather_api.1.lead_days', 0);
        $response->assertJsonPath('providers.weather_api.1.precipitation_mm', 6.2);
    }

    public function test_runs_lists_collection_history(): void
    {
        $this->getJson('/api/runs?provider=open_meteo')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.run_date', '2026-11-20')
            ->assertJsonPath('data.0.status', 'success')
            ->assertJsonPath('data.0.hourly_count', 48)
            ->assertJsonMissingPath('data.0.raw_payload');
    }

    public function test_catalog_endpoints(): void
    {
        $this->getJson('/api/locations')->assertOk()->assertJsonPath('data.0.timezone', 'Europe/Athens');
        $this->getJson('/api/providers')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_validation_and_not_found(): void
    {
        $this->getJson($this->url('compare'))->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson($this->url('daily').'?from=19-11-2026')->assertUnprocessable();
        $this->getJson('/api/locations/999/forecasts/daily')->assertNotFound();
    }
}
