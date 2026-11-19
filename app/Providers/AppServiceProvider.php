<?php

namespace App\Providers;

use App\Weather\ForecastCollector;
use App\Weather\ProviderRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class, fn () => new ProviderRegistry(
            config('weather.providers', []),
            config('weather.http', []),
        ));

        $this->app->bind(ForecastCollector::class, fn () => new ForecastCollector(
            (int) config('weather.forecast_days', 7),
            (bool) config('weather.store_raw_payload', true),
        ));
    }

    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
