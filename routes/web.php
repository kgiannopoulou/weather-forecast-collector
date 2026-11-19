<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Weather Forecast Collector',
    'endpoints' => [
        'GET /api/locations',
        'GET /api/providers',
        'GET /api/runs?location_id=&provider=&status=&per_page=',
        'GET /api/locations/{id}/forecasts/daily?provider=&run_date=&from=&to=',
        'GET /api/locations/{id}/forecasts/hourly?provider=&run_date=&from=&to=',
        'GET /api/locations/{id}/forecasts/compare?date=YYYY-MM-DD',
        'GET /api/locations/{id}/forecasts/history?date=YYYY-MM-DD&provider=',
        'GET /up (health check)',
    ],
]));
