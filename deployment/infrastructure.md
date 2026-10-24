# Community Hub - Production Infrastructure Architecture Blueprint

This document specifies the enterprise infrastructure standards, runtime configurations, and operational safeguards for deploying **Community Hub** to production environments.

---

## Architecture Overview

```
                                  ┌───────────────────┐
                                  │   Cloudflare /    │
                                  │   Route53 (DNS)   │
                                  └─────────┬─────────┘
                                            │ HTTPS (TLS 1.3 / HSTS)
                                            ▼
                                  ┌───────────────────┐
                                  │ Nginx / AWS ALB   │ ◄── WAF & Rate Limiting
                                  └─────────┬─────────┘
                                            │ HTTP / FastCGI
                        ┌───────────────────┼───────────────────┐
                        ▼                                       ▼
             ┌─────────────────────┐                 ┌─────────────────────┐
             │  Web Pod / Container │                 │  Web Pod / Container │
             │      (PHP-FPM)      │                 │      (PHP-FPM)      │
             └──────────┬──────────┘                 └──────────┬──────────┘
                        │                                       │
                        └───────────────────┬───────────────────┘
                                            │
               ┌────────────────────────────┼────────────────────────────┐
               ▼                            ▼                            ▼
    ┌─────────────────────┐      ┌─────────────────────┐      ┌─────────────────────┐
    │    Queue Worker     │      │   Cron Scheduler    │      │    Redis Cluster    │
    │  (Supervisord/Daemon)│      │  (* * * * * artisan)│      │  (Queues & Sessions)│
    └──────────┬──────────┘      └──────────┬──────────┘      └─────────────────────┘
               │                            │
               └──────────────┬─────────────┘
                              ▼
                 ┌──────────────────────────┐
                 │ Primary Relational DB    │
                 │   (PostgreSQL / MySQL)   │
                 └────────────┬─────────────┘
                              │
                              ▼
                 ┌──────────────────────────┐
                 │ Encrypted Offsite Backup │
                 │      (AWS S3 / R2)       │
                 └──────────────────────────┘
```

---

## 1. Production `.env` Definition

The canonical production configuration is structured into isolated functional domains in [`.env.production.example`](file:///c:/Users/Trevaughn.Thelwell/Downloads/Project1/Community%20Hub%20-%20Copy/laravel/.env.production.example):

- **Template Path**: `laravel/.env.production.example`
- **Security Invariant**: Never store raw `.env.production` files in version control. Deploy pipelines must inject variables at runtime through container secrets or an external secret vault.

---

## 2. `APP_DEBUG=false` & Safety Guarantees

In development, Laravel outputs detailed stack traces, environment variables, database schema queries, and secret fragments upon unhandled exceptions. In production:

- **Enforcement**: `APP_DEBUG=false` is strictly required in `.env.production`.
- **User Experience**: Exceptions trigger friendly, brand-consistent Inertia error views (HTTP 403, 404, 419, 429, 500, 503) preserving the navigation sidebar so residents are never stranded on a blank page.
- **Sensitive Data Masking**: All exception handlers in `bootstrap/app.php` sanitize stack traces before returning responses to clients.

---

## 3. Relational Database: PostgreSQL & MySQL

Community Hub supports both **PostgreSQL 15+** and **MySQL 8.4+** engines via Laravel Eloquent PDO drivers.

### A. PostgreSQL Configuration (Recommended for Enterprise)
```ini
DB_CONNECTION=pgsql
DB_HOST=postgres-cluster.internal
DB_PORT=5432
DB_DATABASE=community_hub_prod
DB_USERNAME=hub_app_user
DB_PASSWORD=${SECURE_DB_PASSWORD}
DB_SSLMODE=require
```
*Benefits*: Native JSONB operators, strict ACID compliance, concurrent indexing, row-level locking for billing ledgers.

### B. MySQL Configuration
```ini
DB_CONNECTION=mysql
DB_HOST=mysql-cluster.internal
DB_PORT=3306
DB_DATABASE=community_hub_prod
DB_USERNAME=hub_app_user
DB_PASSWORD=${SECURE_DB_PASSWORD}
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```
*Tuning*: Requires InnoDB storage engine with strict SQL mode enabled (`STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION`).

---

## 4. Queue Worker Architecture & Daemon Management

Asynchronous jobs isolate time-intensive operations (Stripe webhook processing, visitor pass SMS/WhatsApp generation, PDF receipt compiling) from the HTTP request cycle:

### Process Supervisor (`deployment/supervisord.conf`)
```ini
[program:laravel-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work redis --sleep=3 --tries=3 --backoff=10,60,300 --timeout=90 --max-time=3600
autostart=true
autorestart=true
numprocs=2
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/queue-worker.log
stopwaitsecs=60
stopsignal=TERM
```

### Operational Rules
1. **Timeouts & Retries**: Jobs fail after 3 attempts (`--tries=3`) with exponential backoffs (`--backoff=10,60,300`).
2. **Graceful Reloads**: Deployment scripts execute `php artisan queue:restart` to finish currently executing jobs before reloading new application code.
3. **Dead Letter Queue**: Failed jobs persist to the `failed_jobs` table for inspection and replay via `php artisan queue:retry`.

---

## 5. Scheduler Architecture (`schedule:run`)

Periodic maintenance tasks are centralized in `routes/console.php` and invoked via system cron every minute:
```bash
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

### Scheduled Invariants
| Schedule | Task | Purpose |
| :--- | :--- | :--- |
| **Hourly** | `gatepass:prune-nonces` | Cleans expired anti-replay nonces from memory/database |
| **Every 15m** | `gatepass:expire` | Expires lapsed gate clearances |
| **Every 15m** | `visitors:expire-no-shows` | Auto-expires visitors who did not arrive within the grace period |
| **Daily 02:00**| `billing:reconcile` | Audits Stripe transactions against local ledger balances |
| **Daily 03:00**| `deployment/backup.sh` | Executes encrypted database snapshot & S3 sync |

---

## 6. HTTPS & TLS Termination

All HTTP traffic must be permanently upgraded to HTTPS:
- **TLS Configuration**: TLS 1.2 & TLS 1.3 only, modern high-security cipher suites.
- **HSTS**: `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` header active on all responses.
- **Cookie Flags**:
  - `SESSION_SECURE_COOKIE=true` (Browser only transmits session cookies over TLS).
  - `SESSION_HTTP_ONLY=true` (Prevents client-side JavaScript access to auth tokens).
  - `SESSION_SAME_SITE=lax` (Prevents Cross-Site Request Forgery).
- **Reverse Proxy**: Configuration in [`laravel/deployment/nginx.conf`](file:///c:/Users/Trevaughn.Thelwell/Downloads/Project1/Community%20Hub%20-%20Copy/laravel/deployment/nginx.conf).

---

## 7. Log Rotation & Compliance Retention

Application logs are rotated automatically via Monolog daily channels and Linux `logrotate`:

### Log Channels
1. **Application Stack (`storage/logs/laravel-*.log`)**:
   - Rotated daily; 30-day retention window.
   - Captures system warnings and unhandled exceptions.
2. **Security Audit Log (`storage/logs/security-*.log`)**:
   - 90-day forensic retention.
   - Captures gate access refusals, deactivations, privilege changes, and webhook signature failures.
3. **Container STDERR**:
   - Real-time stream for Docker Compose and Kubernetes Fluentd / Datadog agents.

---

## 8. Secrets Management & Key Rotation

Never store plaintext credentials in code or unencrypted disk files:

### Secrets Inventory
- `APP_KEY`: AES-256 encryption key for sessions, cookies, and database fields.
- `GATE_ENGINE_SECRET`: HMAC seed for generating verifiable visitor QR passes.
- `STRIPE_SECRET` & `STRIPE_WEBHOOK_SECRET`: Payment orchestration and signature verification.
- `TWILIO_SID` & `TWILIO_TOKEN`: Telephony credentials.

### Recommended Providers
- **AWS Secrets Manager / SSM Parameter Store**: Injected at container startup.
- **HashiCorp Vault**: Dynamic database secrets and key leases.
- **Doppler**: Secure centralized environment synchronization.

---

## 9. Automated Database Backups (`deployment/backup.sh`)

Automated, encrypted offsite backups prevent catastrophic data loss:
- **Execution**: Script [`laravel/deployment/backup.sh`](file:///c:/Users/Trevaughn.Thelwell/Downloads/Project1/Community%20Hub%20-%20Copy/laravel/deployment/backup.sh) runs nightly via cron or systemd timer.
- **PostgreSQL**: `pg_dump --clean --if-exists | gzip -9`
- **MySQL**: `mysqldump --single-transaction --quick --triggers --routines | gzip -9`
- **Integrity**: SHA-256 checksum generated alongside each dump file.
- **Offsite Replication**: Uploads compressed dumps to AWS S3 / Cloudflare R2 bucket with `AES256` server-side encryption.
- **Retention**: Local and remote dumps pruned after 30 days.

---

## 10. Monitoring & Healthchecks

Community Hub exposes native liveness and deep readiness probes:
- **Liveness Probe**: `GET /up` (HTTP 200 OK) indicates web server and PHP execution are responsive.
- **Container Health**: Docker Compose and Kubernetes check `/up` every 10 seconds.
- **Deep Metrics**: Database connectivity, Redis availability, and writable storage paths.

---

## 11. Error Tracking (Sentry / Bugsnag)

Configured through Monolog and native exception bridges:
```ini
SENTRY_LARAVEL_DSN=https://examplePublicKey@o0.ingest.sentry.io/0
SENTRY_TRACES_SAMPLE_RATE=0.2
SENTRY_SEND_DEFAULT_PII=false
```
- **PII Scrubbing**: Residents' phone numbers, emails, and gate pass tokens are masked before transmission to external APM platforms.
- **Release Tracking**: Tagged with Git SHA from GitHub Actions deploy pipeline.

---

## 12. Alerting & Incident Escalation

Automated routing for production emergencies:
- **Slack Alerting Channel**: Configured in `laravel/config/logging.php` using Monolog Slack driver.
- **Escalation Levels**:
  - `CRITICAL` / `EMERGENCY`: Dispatched immediately to Slack `#production-alerts` and PagerDuty/Opsgenie.
  - Examples: Database connectivity failure, unhandled payment webhook exceptions, queue backlog exceeding 100 pending jobs.
