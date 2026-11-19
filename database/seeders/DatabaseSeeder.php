<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Creates the default location from config/weather.php (safe to run repeatedly). */
    public function run(): void
    {
        $default = config('weather.default_location');

        Location::firstOrCreate(
            ['latitude' => $default['latitude'], 'longitude' => $default['longitude']],
            ['name' => $default['name'], 'timezone' => $default['timezone'], 'is_active' => true],
        );
    }
}
