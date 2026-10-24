# Community Hub — CI/CD & Production Deployment Architecture

This document outlines the automated CI/CD pipeline, required Pull Request quality gates, and production deployment configuration for **Community Hub**.

---

## 1. Continuous Integration & Pull Request Gate

Every pull request and push to `main` executes an automated 7-point validation pipeline defined in [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml):

```
Pull Request
     │
     ├── 1. Composer Install & Lockfile Validation
     ├── 2. PHP Feature & Unit Tests (phpunit)
     ├── 3. Pint Code Formatting & Style Gate
     ├── 4. TypeScript Strict Compilation (tsc --noEmit)
     ├── 5. NPM Production Asset Build (vite build)
     ├── 6. Security Audit (composer audit + npm audit --omit=dev)
     └── 7. Migration Check (migrate:fresh, rollback, re-apply, seed)
             │
             ▼
      PULL REQUEST GATE: PASS / FAIL
```

### Required Status Check Rule
In GitHub **Settings → Branches → Branch protection rules** on `main`:
1. Enable **"Require status checks to pass before merging"**.
2. Select **`Pull Request Gate (PASS / FAIL)`** as the required check.
3. This guarantees that **no PR can merge** if any of the 7 checks fail.

---

## 2. Production Service Architecture

The application requires three dedicated runtime execution roles:

| Component | Role | Command | Process Manager |
| :--- | :--- | :--- | :--- |
| **Web Application** | Serves HTTP, API, Inertia, and Webhook traffic on port 8080 | `apache2-foreground` | Container / Apache |
| **Queue Worker** | Processes async notifications (Email, SMS, WhatsApp), passes & Stripe events | `php artisan queue:work --tries=3 --backoff=10 --max-time=3600` | Docker Compose / Supervisor / Systemd |
| **Task Scheduler** | Executes cron tasks: gate pass expiry (15m), visitor no-show expiry (15m), nonce pruning (1h) | `php artisan schedule:work` (or `schedule:run` via cron) | Docker Compose / Supervisor / Systemd Timer |

---

## 3. Containerized Deployment (Docker Compose)

The multi-container production configuration is defined in [`docker-compose.yml`](../docker-compose.yml):

```bash
# 1. Copy production environment file and fill secrets
cp .env.production.example .env.production

# 2. Build and launch web, queue worker, scheduler, and MySQL
docker compose --env-file .env.production up -d --build

# 3. Verify all services are healthy and running
docker compose ps
```

The container stack automatically:
- Mounts shared persistent storage (`storage/`) across all containers.
- Applies pending database migrations (`RUN_MIGRATIONS: "true"` on the web container).
- Caches configuration, routes, events, and views on startup.
- Supervises background queue workers with graceful 60-second shutdown.

---

## 4. Native Linux / VPS Deployment (Systemd & Supervisor)

For deployments on standalone Linux servers (e.g., Ubuntu 22.04/24.04 with Nginx/PHP-FPM):

### A. Queue Worker Systemd Unit
Install [`deployment/systemd/communityhub-queue.service`](systemd/communityhub-queue.service):
```bash
sudo cp deployment/systemd/communityhub-queue.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now communityhub-queue.service
sudo systemctl status communityhub-queue.service
```

### B. Task Scheduler Systemd Timer
Install [`deployment/systemd/communityhub-scheduler.timer`](systemd/communityhub-scheduler.timer) and service:
```bash
sudo cp deployment/systemd/communityhub-scheduler.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now communityhub-scheduler.timer
sudo systemctl list-timers --all | grep communityhub
```

### C. Zero-Downtime Deployment Script
Deploy updates using [`deployment/deploy.sh`](deploy.sh):
```bash
chmod +x deployment/deploy.sh
./deployment/deploy.sh production
```
The script places the application in maintenance mode, installs composer and npm dependencies, runs migrations, clears/warms caches, restarts queue workers, and brings the application back online without dropped traffic.
