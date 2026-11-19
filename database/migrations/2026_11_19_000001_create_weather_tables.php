<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('latitude', 8, 5);
            $table->decimal('longitude', 8, 5);
            // IANA timezone; daily values are aggregated over the location's local day.
            $table->string('timezone', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['latitude', 'longitude']);
        });

        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name');
            $table->timestamps();
        });

        // One row per (location, provider, day) fetch attempt: a forecast "snapshot".
        // Keeping every day's snapshot (instead of overwriting) is what lets us see how
        // a provider changes its mind and evaluate providers against each other later.
        Schema::create('forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->date('run_date');
            $table->string('status', 20); // success | failed
            $table->timestampTz('fetched_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'provider_id', 'run_date']);
            $table->index(['status', 'run_date']);
        });

        Schema::create('hourly_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_run_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('valid_at'); // stored in UTC
            $table->decimal('temperature_c', 5, 2)->nullable();
            $table->decimal('precipitation_mm', 7, 2)->nullable();

            $table->unique(['forecast_run_id', 'valid_at']);
            $table->index('valid_at');
        });

        Schema::create('daily_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_run_id')->constrained()->cascadeOnDelete();
            $table->date('date'); // local date at the location
            $table->decimal('temperature_min_c', 5, 2)->nullable();
            $table->decimal('temperature_max_c', 5, 2)->nullable();
            $table->decimal('precipitation_mm', 7, 2)->nullable();

            $table->unique(['forecast_run_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_forecasts');
        Schema::dropIfExists('hourly_forecasts');
        Schema::dropIfExists('forecast_runs');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('locations');
    }
};
