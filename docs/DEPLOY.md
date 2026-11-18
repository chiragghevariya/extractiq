# Deploying ExtractIQ

The web app is static-friendly Next.js and runs on **Vercel**. The API, the queue workers
(Horizon), the scheduler and Reverb (WebSockets) run on **one Linux server**, either:

- **AWS EC2 through Laravel Forge** (`deploy/forge/`), or
- **a Hostinger VPS (or any VPS) with Docker** (`deploy/docker/`).

Nothing here is deployed automatically: you need your own accounts (Vercel, AWS or Hostinger,
an S3 bucket, a domain), and the commands below create billable resources.

```
app.example.com  ──►  Vercel (Next.js)
api.example.com  ──►  Laravel API  ─┬─ PostgreSQL 16
ws.example.com   ──►  Reverb        ├─ Redis 7 (queues, cache, locks)
                      Horizon ──────┤
                      Scheduler ────┘  S3 bucket (documents, page images)
```

## 1. Domains and cookies (Sanctum)

The SPA signs in with Sanctum session cookies, so the web app and the API must share a parent
domain: `app.example.com` and `api.example.com`. Then in the API environment:

```
APP_URL=https://api.example.com
FRONTEND_URL=https://app.example.com
SESSION_DOMAIN=.example.com
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=app.example.com
CORS_ALLOWED_ORIGINS=https://app.example.com
```

A `*.vercel.app` preview URL can't share cookies with your API domain; use a custom domain on Vercel.

## 2. Storage (S3)

```
DOCUMENTS_DISK=s3
AWS_ACCESS_KEY_ID=…  AWS_SECRET_ACCESS_KEY=…  AWS_DEFAULT_REGION=eu-central-1  AWS_BUCKET=extractiq-docs
```

The bucket stays **private**. The browser gets presigned URLs that expire after `SIGNED_URL_TTL`
seconds (default 300). Allow the web origin to fetch them with this bucket CORS rule:

```json
[{ "AllowedOrigins": ["https://app.example.com"], "AllowedMethods": ["GET"], "AllowedHeaders": ["*"], "MaxAgeSeconds": 3000 }]
```

## 3a. API on a VPS with Docker (Hostinger)

On Ubuntu 24.04 with Docker installed:

```bash
git clone <your repo> extractiq && cd extractiq
cp api/.env.example deploy/docker/api.env    # fill it in: APP_KEY, DB_*, the domains above, S3, LLM keys
DOMAIN=example.com DB_PASSWORD=… docker compose -f deploy/docker/docker-compose.prod.yml up -d --build
docker compose -f deploy/docker/docker-compose.prod.yml exec api php artisan migrate --force
```

Point DNS A records for `api.` and `ws.` at the server. Caddy obtains the TLS certificates.
Postgres and Redis are only reachable inside the compose network.

To update:

```bash
git pull
docker compose -f deploy/docker/docker-compose.prod.yml up -d --build
docker compose … exec api php artisan migrate --force
```

## 3b. API on AWS EC2 with Forge

Follow `deploy/forge/README.md`. In short: provision PHP 8.3, PostgreSQL and Redis, then add the
site, paste the deploy script, and add two daemons (Horizon and Reverb) and the scheduler.

## 4. Web on Vercel

- **Import the repository.** Root directory: `web`. Framework: Next.js.
- **Environment variables:**
  - `NEXT_PUBLIC_API_URL=https://api.example.com/api/v1`
  - `NEXT_PUBLIC_REVERB_APP_KEY=…`
  - `NEXT_PUBLIC_REVERB_HOST=ws.example.com`
  - `NEXT_PUBLIC_REVERB_PORT=443`
  - `NEXT_PUBLIC_REVERB_SCHEME=https`
  - `NEXT_PUBLIC_DEMO_MODE=true` (public demo only)
- **Custom domain:** `app.example.com`.

## 5. Public demo

To run a public, read-only demo:

1. **API:**
   - Set `DEMO_MODE=true`.
   - Seed once: `php artisan migrate --seed` (it runs `/samples` through the real pipeline).
   - The `/samples` folder must be on the server (it is in the repo).
2. **Web:** set `NEXT_PUBLIC_DEMO_MODE=true`.

The landing page's "Explore the demo" then signs visitors in as a read-only demo user (every write
returns 403), and a banner says "Demo data — fictional companies". The named demo accounts get
random passwords in demo mode. `extractiq:demo-reset` runs nightly at `DEMO_RESET_AT`: it drops and
reseeds the demo tenant only.

## 6. Health and uptime

- **Health endpoint:** `GET /api/health` (public, throttled). It returns `ok`, `degraded` or `down`,
  with checks for the database, Redis, storage, Horizon workers and the scheduler heartbeat. It
  answers 503 when the database or Redis is down. Use it for the Docker `HEALTHCHECK`, the load
  balancer and uptime monitoring.
- **Uptime workflow:** `.github/workflows/uptime.yml` polls it every 15 minutes. Set the repository
  variables `HEALTH_URL` and `WEB_URL`. A failed run is the alert.

## 7. CI

`.github/workflows/ci.yml` runs on every push and pull request:

| Job | What it runs |
|---|---|
| `api` | Pest (parallel) and Larastan |
| `web` | TypeScript, ESLint and a production build |
| `e2e` | The whole stack (PostgreSQL, Redis and Mailpit as services; the seeded pipeline; Horizon) and the Playwright suite |

## Checklist before going live

- `APP_DEBUG=false`, a fresh `APP_KEY`, `LOG_LEVEL=warning`.
- Strong `DB_PASSWORD` and `REVERB_APP_SECRET`; set `INBOUND_EMAIL_SECRET` if you use the inbound webhook.
- `DEMO_MODE=false` on any real tenant.
- Check that the S3 bucket blocks public access.
- Back up PostgreSQL daily (e.g. `pg_dump` to S3 from cron, or RDS snapshots).
