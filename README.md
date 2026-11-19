# Weather Forecast Collector

A PHP (Laravel 13) backend that collects a forecast **once per day** from
**Open-Meteo** and **WeatherAPI** for one or more locations. It stores
**temperature and precipitation** at **hourly and daily** resolution in
**PostgreSQL** and serves the data through a small read-only JSON API.

Each day's fetch is kept as a separate **snapshot** and never overwritten. That
way the data can answer the questions the product cares about:

- *What does each provider predict for tomorrow?* → `daily` / `hourly`
- *Do the providers agree?* → `compare` (side by side, plus the spread between providers)
- *How much did a provider change its mind as the day got closer?* → `history`
- *Is collection healthy?* → `runs`

```
            ┌──────────── scheduler container (schedule:work) ─────────────┐
            │  forecasts:collect ──► ForecastCollector ──► ProviderRegistry │
            │                              │                 ├ OpenMeteoProvider ──► api.open-meteo.com
            │                              │                 └ WeatherApiProvider ─► api.weatherapi.com
            └──────────────────────────────┼───────────────────────────────┘
                                           ▼
                                   PostgreSQL (db)
                                           ▲
            ┌──────── app container (HTTP API, migrations on start) ───────┐
            │  /api/locations/{id}/forecasts/{daily|hourly|compare|history}│
            │  /api/runs  /api/locations  /api/providers  /up              │
            └──────────────────────────────────────────────────────────────┘
```

---

## 1. Installation and setup

### Prerequisites

- Docker with Docker Compose v2.24 or newer. Nothing else is needed: PHP, Composer and Postgres all run in containers.
- Optional: a **free WeatherAPI key** from <https://www.weatherapi.com/signup.aspx>. Open-Meteo does not need a key.

### Run it

```bash
git clone https://github.com/kgiannopoulou/weather-forecast-collector.git
cd weather-forecast-collector
cp .env.example .env          # then set WEATHERAPI_KEY=... in .env
docker compose up -d --build
```

On start-up:

1. `db` starts Postgres 17. Data is kept in the `pgdata` volume.
2. `app` runs the migrations, seeds the default location (Larissa, Thessaly)
   and serves the API on **http://localhost:8090**.
3. `scheduler` runs the first collection straight away, then runs
   `forecasts:collect` on `WEATHER_SCHEDULE_CRON`.

Check that it worked:

```bash
docker compose logs scheduler                     # collection summary table
curl http://localhost:8090/api/runs               # one row per provider for today
curl "http://localhost:8090/api/locations/1/forecasts/daily"
```

If `WEATHERAPI_KEY` is empty, the WeatherAPI run is recorded as `failed` with a
clear error and Open-Meteo still works. After you add the key, run
`docker compose up -d` to recreate the containers with the new environment.
The next scheduler tick then retries WeatherAPI automatically. To retry
immediately, run `docker compose exec scheduler php artisan forecasts:collect`.

### Useful commands

```bash
docker compose exec app php artisan forecasts:collect                      # collect now (skips anything already collected today)
docker compose exec app php artisan forecasts:collect --provider=weather_api --force   # refetch one provider
docker compose exec app php artisan location:add "Thessaloniki" 40.6401 22.9444 Europe/Athens
docker compose exec app php artisan schedule:list
docker compose down            # stop (add -v to also delete the database volume)
```

### Running the tests

The test suite stubs all HTTP calls. `Http::preventStrayRequests()` makes any
real request fail the test, so the tests never use API quota.

```bash
composer install
php artisan test                    # SQLite in-memory, no services needed
```

CI (GitHub Actions) runs the suite against **both SQLite and PostgreSQL 17**,
checks code style with Pint, and builds the Docker image.

### Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `WEATHERAPI_KEY` | *(empty)* | WeatherAPI key. Required for that provider. |
| `WEATHER_FORECAST_DAYS` | `7` | Days ahead to request. Each provider caps this at its own limit (Open-Meteo 16, WeatherAPI free plan 3). |
| `WEATHER_SCHEDULE_CRON` | `15 * * * *` | When the collector runs (see "Scheduling" below for why it is hourly). |
| `WEATHER_STORE_RAW_PAYLOAD` | `true` | Keep each provider's raw JSON response with the snapshot. |
| `WEATHER_HTTP_TIMEOUT` | `15` | Request timeout in seconds. |
| `WEATHER_HTTP_RETRIES` | `3` | Retries after the first attempt, for transient errors only. |
| `WEATHER_HTTP_RETRY_DELAY_MS` | `1000` | Base delay for the backoff: 1 s, 2 s, 4 s. |
| `WEATHER_LOCATION_NAME/LAT/LON/TIMEZONE` | Larissa, 39.639, 22.419, Europe/Athens | Location seeded on first start. |
| `OPEN_METEO_ENABLED`, `WEATHERAPI_ENABLED` | `true` | Turn a provider on or off without code changes. |
| `APP_PORT` / `DB_FORWARD_PORT` | `8090` / `5441` | Host ports published by compose. |
| `DB_DATABASE/USERNAME/PASSWORD` | `weather` | Postgres credentials (compose sets `DB_HOST=db` itself). |

---

## 2. API

All endpoints are `GET`, return JSON and are rate limited to 120 requests per
minute per IP. Dates use the location's local calendar day. Hourly times are
returned in UTC (`valid_at`) and in the location's local time (`local_time`).

| Endpoint | Description |
|---|---|
| `/api/locations` | Registered locations. |
| `/api/providers` | Providers that have collected data. |
| `/api/runs?location_id=&provider=&status=&per_page=` | Collection history, paginated: status, attempts, row counts, error. |
| `/api/locations/{id}/forecasts/daily?provider=&run_date=&from=&to=` | Daily min/max temperature and precipitation total. Uses the latest snapshot per provider, or the snapshot taken on `run_date`. |
| `/api/locations/{id}/forecasts/hourly?provider=&run_date=&from=&to=` | Hourly temperature and precipitation. `from`/`to` are read as local time. |
| `/api/locations/{id}/forecasts/compare?date=YYYY-MM-DD` | Each provider's most recent forecast for that day, plus `spread` (max − min across providers). |
| `/api/locations/{id}/forecasts/history?date=YYYY-MM-DD&provider=` | Every snapshot's forecast for that day, with `lead_days`, so you can see how predictions changed. |
| `/up` | Health check. |

Example: `GET /api/locations/1/forecasts/compare?date=2026-11-20`

```json
{
  "location": {"id": 1, "name": "Larissa", "latitude": 39.639, "longitude": 22.419, "timezone": "Europe/Athens", "is_active": true},
  "date": "2026-11-20",
  "providers": [
    {"provider": "open_meteo",  "run_date": "2026-11-19", "lead_days": 1, "temperature_min_c": 13.1, "temperature_max_c": 24.7, "precipitation_mm": 0},
    {"provider": "weather_api", "run_date": "2026-11-19", "lead_days": 1, "temperature_min_c": 12.0, "temperature_max_c": 22.3, "precipitation_mm": 1.8}
  ],
  "spread": {"temperature_min_c": 1.1, "temperature_max_c": 2.4, "precipitation_mm": 1.8}
}
```

Invalid parameters return `422` with validation errors. An unknown location returns `404`.

---

## 3. Implementation choices

### Framework and dependencies

- **Laravel 13 / PHP 8.4.** Laravel provides everything this task needs without
  extra packages: migrations, the scheduler, an HTTP client with retries and
  test fakes, validation and Artisan commands. That keeps the solution small.
- **PostgreSQL 17.** The schema and queries are portable, and CI also runs the
  tests on SQLite, so a move to MySQL would mostly be a matter of configuration.
- **No third-party packages** beyond the Laravel skeleton. I chose not to use
  API SDKs: each provider needs only one HTTP request, and an SDK would hide
  the retry and error behaviour that matters here.
- Dev tools: PHPUnit and Pint, both included with Laravel.

### Data model

```
locations ─┐
           ├─< forecast_runs >─┬─< hourly_forecasts   (valid_at UTC, temperature_c, precipitation_mm)
providers ─┘   (one per        └─< daily_forecasts    (local date, temperature_min_c, temperature_max_c, precipitation_mm)
                location ×
                provider × day)
```

- **`forecast_runs` is the central table.** Each row is one provider's forecast
  for one location, fetched on one day (`UNIQUE(location_id, provider_id, run_date)`).
  It stores `status`, `attempts`, `error`, `fetched_at` and, optionally, the raw
  payload. Keeping every daily snapshot is what allows comparison over time
  (`history`), comparison between providers (`compare`), and later checking
  forecasts against observed weather.
- **Hourly and daily rows are stored separately.** I store each provider's own
  daily aggregates instead of computing them from hourly values. Providers
  calculate daily figures from finer data than they expose, and the brief asks
  for both resolutions.
- **Normalised units and time.** All values are stored in °C and mm. Hourly
  timestamps are stored as `timestamptz` in UTC, so they are unambiguous across
  DST changes and across locations. Daily rows use the location's local
  calendar date, which is the "day" a farmer plans around.
- **Typed columns per variable** (`temperature_c`, `precipitation_mm`, …) instead
  of a generic key/value table. *Trade-off:* each new variable needs a migration
  and a mapper change. In return the data is typed, compact, easy to index and
  simple to query. A key/value design only pays off once there are dozens of
  sparse variables (see Next steps).
- **`providers` and `locations` are tables, not enums**, so new ones can be added
  as data. Provider rows are created automatically on their first collection.
- **Raw payloads** are stored as JSON (configurable). If a mapping bug is found
  later, the stored snapshots can be re-parsed without new API calls.

### Handling differences between providers

Each provider is an adapter implementing `App\Weather\Contracts\WeatherProvider`.
It returns the same normalised `ProviderForecast` DTO (a list of `HourlyPoint`
and a list of `DailyPoint`). Nothing outside the adapters knows a provider's
format. Adding a provider means writing **one class and one config entry**.

| Difference | How it is handled |
|---|---|
| Field names and response structure | Mapped inside each adapter. |
| Timezones | Open-Meteo is called with the location timezone and `timeformat=unixtime`. Daily timestamps are converted with the real IANA zone, not a fixed offset, so a DST change inside the forecast window is handled. WeatherAPI gives `time_epoch` (UTC) per hour and local dates per day. |
| Forecast length | `WEATHER_FORECAST_DAYS` is capped per provider (`max_forecast_days`): 16 for Open-Meteo, 3 for WeatherAPI's free plan. |
| Authentication | WeatherAPI's key comes from the environment. If it is missing, the run fails with a clear error before any HTTP call is made. |
| Error formats | Open-Meteo returns `{"reason"}` and WeatherAPI returns `{"error":{"message"}}`. Both are turned into a readable `ProviderException`. |
| Missing values | Stored as `NULL`, never as `0`. A missing precipitation value is different from "no rain". |

### Handling failures

- **Retries only for transient errors.** Network errors, `429` and `5xx` are
  retried up to 3 times with exponential backoff (1 s, 2 s, 4 s). `4xx` errors
  such as an invalid key are **not** retried, because retrying would only use up quota.
- **Each provider is isolated.** If one provider fails, a `failed` run is
  recorded with the error and the collector moves on to the next
  provider and location. The command exits non-zero when anything failed, so cron or
  monitoring can detect partial failures.
- **Unexpected payloads are rejected.** Missing keys raise an error, so a
  provider's API change cannot silently store wrong data.
- **Atomic snapshots.** A snapshot's rows are replaced in one transaction, so a
  snapshot is either complete or absent. If a forced refetch fails, the existing
  good data is **kept**.
- **Unexpected exceptions** (bugs) are also recorded as failed runs, and are
  `report()`ed to the error tracker as well.

### Scheduling and API limits

`forecasts:collect` is **idempotent per (location, provider, UTC day)**. Once a
snapshot has succeeded, later runs on the same day skip it without any HTTP
call. That makes it possible to schedule it **hourly** (`15 * * * *`):

- On a normal day each provider is called **exactly once** per location. The
  remaining hourly ticks make no API calls.
- If a provider fails at 06:15, it is retried at 07:15, 08:15 and so on until
  it succeeds. Nobody needs to rerun anything by hand.
- If the server was down at the scheduled time, the first tick after it comes
  back collects the missed snapshot.
- Restarting the containers (`COLLECT_ON_START`) does not use extra quota.

Quota cost is **1 call per provider per location per day** (at most about 96
calls a day in the worst case, with 24 failing ticks and retries). That is far
below the free-tier limits: Open-Meteo allows about 10,000 calls a day for
non-commercial use, and WeatherAPI's free plan allows a monthly quota of calls.
`withoutOverlapping()` stops a slow run from overlapping with the next tick.

### Docker

The image is multi-stage: Composer installs the dependencies (without dev
packages), and the runtime is `php:8.4-cli-alpine` with `pdo_pgsql`, running as
a non-root user. One image is used for two roles, `app` (API) and `scheduler`
(`schedule:work`). Health checks make Compose start them in order:
db → app (migrates) → scheduler.

---

## 4. Incomplete requirements

All the stated requirements are implemented. Some limits are worth stating openly:

- **The WeatherAPI adapter has been tested against a fixture, not the live
  API.** The fixture follows WeatherAPI's documented response format. The
  Open-Meteo adapter has been run against the live API, and its test fixture is
  a real response. The WeatherAPI code paths (mapping, missing key, 401 without
  retry, 5xx with retry) are covered by tests.
- **The API serves raw data only.** It has no authentication, and no derived
  agronomic indicators (frost risk, spray windows). Those are product features
  that would be built on top of this data.
- The API runs on `php artisan serve` (PHP's built-in server). That is fine for
  this assignment, but it is not a production web server (see Next steps).

## 5. Next steps

1. **Production serving:** php-fpm + nginx, or FrankenPHP/Octane, with `config:cache` and `route:cache`.
2. **Observed weather:** collect actuals (for example Open-Meteo's historical API) to score providers by error and lead time. The snapshot model already supports this.
3. **Scaling to many locations:** dispatch one queued job per (location, provider), with per-provider rate limiting (`RateLimiter` or Redis), instead of looping inside one command.
4. **More variables:** wind, humidity, evapotranspiration and soil moisture are all useful for spraying and irrigation decisions. If the variable list becomes long and sparse, move to a long-format `forecast_values(run_id, variable, valid_at, value)` table, possibly with TimescaleDB hypertables and partitioning by `run_date`.
5. **Data retention:** prune `raw_payload` after N days, and aggregate old hourly snapshots.
6. **Alerting:** notify (Slack or email) when a provider has failed for more than X hours, using `forecast_runs` as the source.
7. **API:** authentication (Sanctum tokens), an OpenAPI spec, caching of the "latest snapshot" responses, and CSV export.
8. **Provider-specific details:** the meaning of hourly precipitation differs (Open-Meteo reports the *preceding* hour's sum). Store an explicit interval so values from different providers line up exactly.

## 6. Additional notes

- **Snapshot dates are UTC days.** `run_date` is the UTC date of the collection,
  which keeps it consistent across locations in different timezones. Forecast
  *days* always use the location's local calendar.
- **The default location is Larissa**, in the Thessaly plain, one of Greece's
  main farming regions. You can change it with `WEATHER_LOCATION_*` or add more
  with `location:add`.
- **`APP_KEY` is optional in Docker.** The app encrypts nothing that must
  survive a restart (no sessions or cookies), so the entrypoint creates a key
  for each container start when none is set.
- **Where the code lives:**
  - `app/Weather/`: provider contract, adapters, DTOs, registry, collector
  - `app/Console/Commands/`: `forecasts:collect`, `location:add`
  - `app/Http/Controllers/Api/`: read API
  - `database/migrations/`: schema
  - `tests/Feature/`: 23 tests
