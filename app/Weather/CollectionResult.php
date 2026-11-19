<?php

namespace App\Weather;

use App\Models\ForecastRun;

final readonly class CollectionResult
{
    public const COLLECTED = 'collected';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    private function __construct(
        public string $outcome,
        public ?ForecastRun $run,
        public string $message = '',
        public int $hourlyCount = 0,
        public int $dailyCount = 0,
    ) {}

    public static function collected(ForecastRun $run, int $hourly, int $daily): self
    {
        return new self(self::COLLECTED, $run, '', $hourly, $daily);
    }

    public static function skipped(ForecastRun $run): self
    {
        return new self(self::SKIPPED, $run, 'already collected today (use --force to refetch)');
    }

    public static function failed(?ForecastRun $run, string $message): self
    {
        return new self(self::FAILED, $run, $message);
    }

    public function isFailure(): bool
    {
        return $this->outcome === self::FAILED;
    }
}
