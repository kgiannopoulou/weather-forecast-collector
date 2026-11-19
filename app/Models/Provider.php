<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    protected $fillable = ['slug', 'name'];

    public function forecastRuns(): HasMany
    {
        return $this->hasMany(ForecastRun::class);
    }
}
