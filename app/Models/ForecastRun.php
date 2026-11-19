<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForecastRun extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'location_id', 'provider_id', 'run_date', 'status',
        'fetched_at', 'attempts', 'error', 'raw_payload',
    ];

    protected $hidden = ['raw_payload'];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'immutable_datetime',
            'raw_payload' => 'array',
            'attempts' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function hourly(): HasMany
    {
        return $this->hasMany(HourlyForecast::class);
    }

    public function daily(): HasMany
    {
        return $this->hasMany(DailyForecast::class);
    }

    public function scopeSuccessful(Builder $query): void
    {
        $query->where('status', self::STATUS_SUCCESS);
    }

    /** Most recent successful snapshot per (location, provider). Portable SQL, uses the unique index. */
    public function scopeLatestPerProvider(Builder $query): void
    {
        $query->successful()->where('run_date', function ($sub) {
            $sub->selectRaw('max(r2.run_date)')
                ->from('forecast_runs as r2')
                ->whereColumn('r2.location_id', 'forecast_runs.location_id')
                ->whereColumn('r2.provider_id', 'forecast_runs.provider_id')
                ->where('r2.status', self::STATUS_SUCCESS);
        });
    }

    public function toApiSummary(): array
    {
        return [
            'provider' => $this->provider->slug,
            'run_date' => $this->run_date,
            'fetched_at' => $this->fetched_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
