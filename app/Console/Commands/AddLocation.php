<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class AddLocation extends Command
{
    protected $signature = 'location:add
        {name : Display name, e.g. "Larissa"}
        {latitude : Decimal degrees}
        {longitude : Decimal degrees}
        {timezone : IANA timezone, e.g. Europe/Athens}';

    protected $description = 'Register a location to collect forecasts for';

    public function handle(): int
    {
        $data = [
            'name' => $this->argument('name'),
            'latitude' => $this->argument('latitude'),
            'longitude' => $this->argument('longitude'),
            'timezone' => $this->argument('timezone'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        $location = Location::updateOrCreate(
            ['latitude' => round((float) $data['latitude'], 5), 'longitude' => round((float) $data['longitude'], 5)],
            ['name' => $data['name'], 'timezone' => $data['timezone'], 'is_active' => true],
        );

        $this->info("Location #{$location->id} \"{$location->name}\" saved.");

        return self::SUCCESS;
    }
}
