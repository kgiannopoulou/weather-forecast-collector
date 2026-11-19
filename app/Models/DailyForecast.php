<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyForecast extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'forecast_run_id', 'date', 'temperature_min_c', 'temperature_max_c', 'precipitation_mm',
    ];

    protected function casts(): array
    {
        return [
            'temperature_min_c' => 'float',
            'temperature_max_c' => 'float',
            'precipitation_mm' => 'float',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }
}
