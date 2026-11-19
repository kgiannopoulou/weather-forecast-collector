<?php

namespace App\Weather\Contracts;

use App\Models\Location;
use App\Weather\Data\ProviderForecast;
use App\Weather\Exceptions\ProviderException;

/**
 * Adapter around one external forecast API.
 *
 * Implementations translate the provider's format into the normalised
 * ProviderForecast (UTC timestamps, degrees Celsius, millimetres, local dates),
 * so the rest of the application never needs to know where data came from.
 */
interface WeatherProvider
{
    /** Stable identifier stored in the `providers` table, e.g. "open_meteo". */
    public function slug(): string;

    public function name(): string;

    /** Upper bound on forecast days the provider (plan) supports. */
    public function maxForecastDays(): int;

    /**
     * @throws ProviderException when the request fails or the payload is unusable
     */
    public function fetch(Location $location, int $days): ProviderForecast;
}
