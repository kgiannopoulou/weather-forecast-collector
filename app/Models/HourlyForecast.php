<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HourlyForecast extends Model
{
    public $timestamps = false;

    protected $fillable = ['forecast_run_id', 'valid_at', 'temperature_c', 'precipitation_mm'];

    protected function casts(): array
    {
        return [
            'valid_at' => 'immutable_datetime',
            'temperature_c' => 'float',
            'precipitation_mm' => 'float',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }
}
