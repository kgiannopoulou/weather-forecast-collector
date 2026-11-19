<?php

namespace App\Weather\Data;

final readonly class DailyPoint
{
    public function __construct(
        public string $date, // Y-m-d, local to the location
        public ?float $temperatureMinC,
        public ?float $temperatureMaxC,
        public ?float $precipitationMm,
    ) {}
}
