<?php

namespace App\Weather;

use App\Weather\Contracts\WeatherProvider;
use InvalidArgumentException;

/** Builds provider adapters from config('weather.providers'). */
class ProviderRegistry
{
    /** @var array<string, WeatherProvider> */
    private array $providers = [];

    /**
     * @param  array<string, array<string, mixed>>  $providers
     * @param  array<string, int>  $http
     */
    public function __construct(array $providers, array $http)
    {
        foreach ($providers as $slug => $config) {
            if (! ($config['enabled'] ?? true)) {
                continue;
            }

            $provider = new $config['class']($slug, $config, $http);
            if (! $provider instanceof WeatherProvider) {
                throw new InvalidArgumentException("{$config['class']} must implement ".WeatherProvider::class);
            }

            $this->providers[$slug] = $provider;
        }
    }

    /** @return array<string, WeatherProvider> */
    public function enabled(): array
    {
        return $this->providers;
    }

    public function get(string $slug): WeatherProvider
    {
        return $this->providers[$slug]
            ?? throw new InvalidArgumentException("Unknown or disabled provider [{$slug}]. Enabled: ".implode(', ', array_keys($this->providers)));
    }
}
