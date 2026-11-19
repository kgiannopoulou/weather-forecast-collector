<?php

namespace App\Weather\Data;

use Carbon\CarbonImmutable;

final readonly class HourlyPoint
{
    public function __construct(
        public CarbonImmutable $validAt, // UTC
        public ?float $temperatureC,
        public ?float $precipitationMm,
    ) {}
}
