<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ForecastController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api')->group(function () {
    Route::get('locations', [CatalogController::class, 'locations']);
    Route::get('providers', [CatalogController::class, 'providers']);
    Route::get('runs', [CatalogController::class, 'runs']);

    Route::prefix('locations/{location}/forecasts')->controller(ForecastController::class)->group(function () {
        Route::get('daily', 'daily');
        Route::get('hourly', 'hourly');
        Route::get('compare', 'compare');
        Route::get('history', 'history');
    });
});
