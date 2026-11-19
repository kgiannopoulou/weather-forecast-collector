<?php

use Illuminate\Support\Facades\Schedule;

// Idempotent: after today's snapshot succeeds, later ticks make no API calls;
// a provider that failed earlier in the day is retried on the next tick.
Schedule::command('forecasts:collect')
    ->cron(config('weather.schedule_cron'))
    ->withoutOverlapping();
