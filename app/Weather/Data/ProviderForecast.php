<?php

namespace App\Weather\Data;

final readonly class ProviderForecast
{
    /**
     * @param  list<HourlyPoint>  $hourly
     * @param  list<DailyPoint>  $daily
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public array $hourly,
        public array $daily,
        public array $rawPayload = [],
    ) {}
}
