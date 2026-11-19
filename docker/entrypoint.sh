#!/bin/sh
set -e

# The app does not encrypt anything that must survive restarts (no sessions or
# cookies), so an ephemeral key is acceptable when none is configured.
if [ -z "$APP_KEY" ]; then
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    export APP_KEY
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
    php artisan db:seed --force
fi

# Collect immediately instead of waiting for the next scheduler tick.
# Idempotent, so container restarts do not spend API quota.
if [ "${COLLECT_ON_START:-false}" = "true" ]; then
    php artisan forecasts:collect || echo "Initial collection reported failures (see table above); the scheduler will retry."
fi

exec "$@"
