# ExtractIQ — project rules

Portfolio-grade AI document extraction platform: invoices and purchase orders are ingested
(upload + email), read with OCR + an LLM, validated, reviewed by a human, matched (2-way and
3-way), and pushed to an ERP.

## Monorepo layout
- `/api`    Laravel 12 (PHP 8.3), Sanctum (SPA cookie auth), Horizon (Redis queues), Reverb, PostgreSQL
- `/web`    Next.js 15 App Router, React, TypeScript, Tailwind, shadcn/ui, TanStack Query
- `/infra`  docker-compose: postgres, redis, minio, mailpit, odoo 17 + its postgres
- `/samples` sample invoice / PO / GRN PDFs and a generator script

## Rules
- Multi-tenant by `company_id` on every business table (one demo tenant seeded).
- Every pipeline step is a queued job, idempotent, retried with backoff, and writes to
  `document_events` (audit trail) with status, duration_ms and error.
- Document status machine: received -> ocr_done -> extracted -> needs_review | validated ->
  approved -> matched | match_exception -> pushed | push_failed.
- Service interfaces with swappable drivers: OcrProvider (Textract, Tesseract), LlmProvider
  (OpenAI, Anthropic), ErpConnector (Odoo, QuickBooks, Mock, Webhook).
- Money stored as integer minor units + currency code. Dates ISO 8601.
- API is REST under `/api/v1`, JSON:API-style resources, OpenAPI spec in `/api/docs`.
- Secrets only in `.env`; ship `.env.example`. No real customer data, ever.
- UI: clean, premium, easy to use. Light + dark mode. Empty, loading and error states on every
  screen. Brand name ExtractIQ, deep navy + antique gold accent.
- **Design rules in `docs/DESIGN.md` are mandatory for every phase.** Keep it simple and real:
  Geist only (no serif/display fonts, no monospace uppercase "eyebrow" labels, no glows/textures/
  decorative animation, no invented metrics or slogans), sentence-case labels, dark top bar
  of areas + light section-tab bar (no sidebar), gold as the only accent, underline FilterTabs, StatStrip, StatusBadge.
  It must never look AI-generated or like SupportIQ (white sidebar, Inter, pill tabs, gradient logo).
- Write Pest tests for services and jobs; Playwright smoke test for the web app.
- After each phase, update README.md (setup, architecture, what the phase added).

## Working notes
- Local dev runs natively (no Docker on the build machine): PHP via `/opt/homebrew/opt/php@8.3/bin`,
  Postgres db `extractiq`, Redis on 6379. Ports: API 8030, Reverb 8031, web 3030.
- `make test` must stay green (Pest + Larastan + web typecheck/lint).
- Reseed with `make seed`; it runs the /samples PDFs through the real pipeline.
