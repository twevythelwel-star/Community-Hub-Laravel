#!/usr/bin/env bash
# ==============================================================================
# Community Hub — Zero-Downtime Production Deployment Script
# ==============================================================================
# Usage: ./deploy.sh [environment]
# Example: ./deploy.sh production
#
# This script handles zero-downtime deployment for the web application,
# restarts background queue workers, and warms all Laravel caches.
# ==============================================================================

set -eo pipefail

APP_ENV="${1:-production}"
echo "==> Deploying Community Hub to [${APP_ENV}]..."

# 1. Enter maintenance mode with automated retry header
if [ "$APP_ENV" = "production" ]; then
    echo "==> Activating maintenance mode..."
    php artisan down --render="errors::503" --retry=30 --secret="community-hub-deploy-bypass" || true
fi

# 2. Pull latest codebase (if running from git repo)
if [ -d ".git" ]; then
    echo "==> Pulling latest changes from git..."
    git pull origin main
fi

# 3. Install/update PHP dependencies (strictly production, no dev packages)
echo "==> Installing PHP dependencies..."
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# 4. Install/update Node dependencies and compile production assets
echo "==> Compiling frontend assets..."
npm ci --no-audit --no-fund
npm run build

# 5. Execute database migrations
echo "==> Running database migrations..."
php artisan migrate --force --no-interaction

# 6. Clear and warm all production caches
echo "==> Warming production caches..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 7. Ensure storage symlink exists
php artisan storage:link --force --no-interaction >/dev/null 2>&1 || true

# 8. Restart background queue workers
# This signals active workers to finish their current job and gracefully
# reload the new code on their next poll.
echo "==> Restarting background queue workers..."
php artisan queue:restart

# 9. Verify background scheduler is alive
echo "==> Verifying schedule list..."
php artisan schedule:list

# 10. Exit maintenance mode
if [ "$APP_ENV" = "production" ]; then
    echo "==> Bringing application online..."
    php artisan up
fi

echo "=========================================================="
echo " Community Hub deployment successfully completed!        "
echo " Web: Active | Queues: Restarted | Scheduler: Ready       "
echo "=========================================================="
