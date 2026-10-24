#!/bin/sh
#
# Runs in every container (web, queue, scheduler) before its command.
# Configuration is cached here rather than at build time because the
# environment, including secrets, only exists once the container starts.
set -e

if [ -z "$APP_KEY" ] || [ -z "$GATE_ENGINE_SECRET" ]; then
    echo "APP_KEY and GATE_ENGINE_SECRET must both be set. See .env.production.example." >&2
    exit 1
fi

# Only the web container sets RUN_MIGRATIONS, so the three containers do not
# race to migrate the same database.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

# public/storage -> storage/app/public, for uploaded avatars and QR images.
php artisan storage:link --force --no-interaction >/dev/null 2>&1 || true

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
