<?php

namespace App\Weather\Providers;

use App\Weather\Contracts\WeatherProvider;
use App\Weather\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared HTTP plumbing: timeouts, bounded retries with exponential backoff on
 * transient failures (network errors, 429, 5xx) and uniform error reporting.
 * Client errors such as 400/401/403 are not retried - that would only burn quota.
 */
abstract class HttpWeatherProvider implements WeatherProvider
{
    /**
     * @param  array<string, mixed>  $config  entry from config('weather.providers')
     * @param  array<string, int>  $http  config('weather.http')
     */
    public function __construct(
        protected readonly string $slug,
        protected readonly array $config,
        protected readonly array $http,
    ) {}

    public function slug(): string
    {
        return $this->slug;
    }

    public function maxForecastDays(): int
    {
        return (int) ($this->config['max_forecast_days'] ?? 1);
    }

    protected function request(): PendingRequest
    {
        $retries = max(1, (int) ($this->http['retries'] ?? 3));
        $delay = (int) ($this->http['retry_delay_ms'] ?? 1000);
        $backoff = array_map(fn (int $i) => $delay * (2 ** $i), range(0, $retries - 1));

        return Http::baseUrl($this->config['base_url'])
            ->acceptJson()
            ->timeout((int) ($this->http['timeout'] ?? 15))
            ->retry($backoff, 0, fn (Throwable $e) => $this->isTransient($e), throw: false);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function getJson(string $path, array $query): array
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException $e) {
            throw ProviderException::unreachable($this->name(), $e->getMessage());
        }

        if ($response->failed()) {
            throw ProviderException::httpError($this->name(), $response->status(), $this->errorDetail($response));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw ProviderException::invalidPayload($this->name(), 'response body is not JSON');
        }

        return $json;
    }

    /** Extract a human readable error message from a failed response. */
    abstract protected function errorDetail(Response $response): ?string;

    protected function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && ($e->response->status() === 429 || $e->response->serverError());
    }

    protected static function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
