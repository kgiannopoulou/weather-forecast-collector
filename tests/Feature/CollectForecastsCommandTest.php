<?php

namespace Tests\Feature;

use App\Models\DailyForecast;
use App\Models\ForecastRun;
use App\Models\HourlyForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectForecastsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-19 06:15:00');
        $this->larissa();
    }

    public function test_it_stores_a_snapshot_per_provider(): void
    {
        $this->fakeProviders();

        $this->artisan('forecasts:collect')->assertSuccessful();

        $this->assertSame(2, ForecastRun::where('status', 'success')->where('run_date', '2026-11-19')->count());
        $this->assertSame(48 + 4, HourlyForecast::count());
        $this->assertSame(4, DailyForecast::count());

        $run = ForecastRun::whereRelation('provider', 'slug', 'weather_api')->first();
        $this->assertSame('Larisa', $run->raw_payload['location']['name']);
        $this->assertSame(1, $run->attempts);
    }

    public function test_it_clamps_forecast_days_to_the_provider_limit(): void
    {
        $this->fakeProviders();

        $this->artisan('forecasts:collect')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'open-meteo') && (int) $r['forecast_days'] === 7);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'weatherapi') && (int) $r['days'] === 3);
    }

    public function test_it_is_idempotent_within_a_day(): void
    {
        $this->fakeProviders();
        $this->artisan('forecasts:collect')->assertSuccessful();
        Http::assertSentCount(2);

        $this->artisan('forecasts:collect')->assertSuccessful();

        Http::assertSentCount(2); // no extra API calls: quota is preserved
        $this->assertSame(2, ForecastRun::count());
        $this->assertSame(52, HourlyForecast::count());
    }

    public function test_force_replaces_the_snapshot_rows(): void
    {
        $this->fakeProviders();
        $this->artisan('forecasts:collect')->assertSuccessful();

        $this->artisan('forecasts:collect', ['--force' => true, '--provider' => ['open_meteo']])->assertSuccessful();

        $this->assertSame(52, HourlyForecast::count());
        $this->assertSame(2, ForecastRun::whereRelation('provider', 'slug', 'open_meteo')->value('attempts'));
    }

    public function test_one_failing_provider_does_not_block_the_other(): void
    {
        Http::fake([
            self::OPEN_METEO_URL => Http::response($this->fixture('open_meteo_forecast')),
            self::WEATHER_API_URL => Http::response(['error' => ['code' => 9999, 'message' => 'Internal error']], 500),
        ]);

        $this->artisan('forecasts:collect')->assertFailed();

        $failed = ForecastRun::whereRelation('provider', 'slug', 'weather_api')->first();
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('HTTP 500', $failed->error);
        $this->assertSame('success', ForecastRun::whereRelation('provider', 'slug', 'open_meteo')->value('status'));
    }

    public function test_a_failed_provider_is_retried_on_the_next_run(): void
    {
        Http::fake([
            self::OPEN_METEO_URL => Http::response($this->fixture('open_meteo_forecast')),
            // 1 try + 3 retries, all failing, then success on the next scheduled run.
            self::WEATHER_API_URL => Http::sequence()
                ->push(['error' => ['message' => 'down']], 500)
                ->push(['error' => ['message' => 'down']], 500)
                ->push(['error' => ['message' => 'down']], 500)
                ->push(['error' => ['message' => 'down']], 500)
                ->push($this->fixture('weather_api_forecast')),
        ]);

        $this->artisan('forecasts:collect')->assertFailed();
        $this->artisan('forecasts:collect')->assertSuccessful();

        $run = ForecastRun::whereRelation('provider', 'slug', 'weather_api')->first();
        $this->assertSame('success', $run->status);
        $this->assertNull($run->error);
        $this->assertSame(2, $run->attempts);
        $this->assertSame(4, $run->hourly()->count());
    }

    public function test_a_failed_forced_refetch_keeps_existing_good_data(): void
    {
        $this->fakeProviders();
        $this->artisan('forecasts:collect')->assertSuccessful();

        $this->fakeHttp([self::OPEN_METEO_URL => Http::response(['reason' => 'boom'], 500)]);
        $this->artisan('forecasts:collect', ['--force' => true, '--provider' => ['open_meteo']])->assertFailed();

        $run = ForecastRun::whereRelation('provider', 'slug', 'open_meteo')->first();
        $this->assertSame('success', $run->status);
        $this->assertSame(48, $run->hourly()->count());
    }

    public function test_each_day_creates_a_new_snapshot(): void
    {
        $this->fakeProviders();
        $this->artisan('forecasts:collect')->assertSuccessful();

        $this->travelTo('2026-11-20 06:15:00');
        $this->artisan('forecasts:collect')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['2026-11-19', '2026-11-20'],
            ForecastRun::distinct()->pluck('run_date')->all(),
        );
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $this->artisan('forecasts:collect', ['--provider' => ['nope']])->assertExitCode(2);
    }
}
