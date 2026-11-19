<?php

use App\Weather\Providers\OpenMeteoProvider;
use App\Weather\Providers\WeatherApiProvider;

return [

    /*
    | How many days ahead to request. Each provider clamps this to what its
    | (free) plan allows, see `max_forecast_days` below.
    */
    'forecast_days' => (int) env('WEATHER_FORECAST_DAYS', 7),

    /*
    | Cron expression for the collector. The command is idempotent: once a
    | provider/location has a successful snapshot for the day, later ticks make
    | no API calls. Running it hourly therefore still means "one fetch per day"
    | while automatically retrying a provider that failed earlier in the day.
    */
    'schedule_cron' => env('WEATHER_SCHEDULE_CRON', '15 * * * *'),

    /*
    | Keep the provider's raw JSON response alongside the normalised rows so
    | data can be re-parsed later (e.g. after a mapping bug) without spending
    | API quota.
    */
    'store_raw_payload' => (bool) env('WEATHER_STORE_RAW_PAYLOAD', true),

    // `retries` = extra attempts after the first one, with exponential backoff
    // (retry_delay_ms, x2, x4 ...). Only transient errors (network, 429, 5xx) are retried.
    'http' => [
        'timeout' => (int) env('WEATHER_HTTP_TIMEOUT', 15),
        'retries' => (int) env('WEATHER_HTTP_RETRIES', 3),
        'retry_delay_ms' => (int) env('WEATHER_HTTP_RETRY_DELAY_MS', 1000),
    ],

    /*
    | Registered providers. Adding a provider = one class implementing
    | App\Weather\Contracts\WeatherProvider + one entry here.
    */
    'providers' => [
        'open_meteo' => [
            'class' => OpenMeteoProvider::class,
            'enabled' => (bool) env('OPEN_METEO_ENABLED', true),
            'base_url' => env('OPEN_METEO_BASE_URL', 'https://api.open-meteo.com/v1'),
            'max_forecast_days' => 16,
        ],
        'weather_api' => [
            'class' => WeatherApiProvider::class,
            'enabled' => (bool) env('WEATHERAPI_ENABLED', true),
            'base_url' => env('WEATHERAPI_BASE_URL', 'https://api.weatherapi.com/v1'),
            'api_key' => env('WEATHERAPI_KEY'),
            // Free plan returns at most 3 forecast days.
            'max_forecast_days' => (int) env('WEATHERAPI_MAX_FORECAST_DAYS', 3),
        ],
    ],

    /*
    | Location created by the seeder on first install. More can be added with
    | `php artisan location:add`.
    */
    'default_location' => [
        'name' => env('WEATHER_LOCATION_NAME', 'Larissa'),
        'latitude' => (float) env('WEATHER_LOCATION_LAT', 39.639),
        'longitude' => (float) env('WEATHER_LOCATION_LON', 22.419),
        'timezone' => env('WEATHER_LOCATION_TIMEZONE', 'Europe/Athens'),
    ],
];
