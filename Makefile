# ExtractIQ — common tasks.  `make help` lists them.
#
# Native by default. Override tools if needed, e.g.
#   make dev PHP=/opt/homebrew/opt/php@8.3/bin/php
# Set DOCKER=1 to run artisan inside the compose `api` container instead.

PHP      ?= php
COMPOSER ?= composer
NPM      ?= npm
COMPOSE  := docker compose -f infra/docker-compose.yml
CHROMIUM_PATH ?=

ifeq ($(DOCKER),1)
ARTISAN := $(COMPOSE) --profile app exec api php artisan
else
ARTISAN := cd api && $(PHP) artisan
endif

.DEFAULT_GOAL := help
.PHONY: help install samples up up-app down logs seed dev test test-api analyse test-web e2e fresh minio odoo-seed erp-sync demo-video

help: ## Show this help
	@grep -E '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

install: ## Install API, web and sample dependencies; create .env files
	cd api && $(COMPOSER) install
	@test -f api/.env || (cp api/.env.example api/.env && cd api && $(PHP) artisan key:generate)
	cd web && $(NPM) install
	@test -f web/.env.local || cp web/.env.example web/.env.local
	cd samples && $(NPM) install

samples: ## (Re)generate the fictional sample PDFs
	cd samples && $(NPM) run generate

minio: ## Run MinIO natively on :9100 (data in .data/minio) and create the bucket — for DOCUMENTS_DISK=s3 without Docker
	@mkdir -p .data/minio
	@(MINIO_ROOT_USER=extractiq MINIO_ROOT_PASSWORD=extractiq-secret nohup minio server .data/minio --address 127.0.0.1:9100 --console-address 127.0.0.1:9101 >/dev/null 2>&1 &) ; sleep 2
	mc alias set extractiq-local http://127.0.0.1:9100 extractiq extractiq-secret >/dev/null && mc mb --ignore-existing extractiq-local/extractiq

up: ## Start infrastructure: postgres, redis, minio, mailpit, odoo (docker)
	$(COMPOSE) up -d --wait

up-app: ## Start infrastructure + API, Horizon, Reverb, scheduler, web (docker)
	$(COMPOSE) --profile app up -d --build --wait

down: ## Stop all containers
	$(COMPOSE) --profile app down

logs: ## Tail container logs
	$(COMPOSE) --profile app logs -f --tail=100

seed: ## Fresh database + demo tenant, users, vendors and the sample documents run through the pipeline
	@test -f samples/manifest.json || $(MAKE) samples
	$(ARTISAN) migrate:fresh --seed --force

fresh: seed ## Alias for seed

odoo-seed: ## Install Purchase/Inventory/Invoicing in Odoo and create the demo vendors, products and 10 POs (needs `make up` + ODOO_PASSWORD)
	$(ARTISAN) extractiq:odoo-seed --install

erp-sync: ## Pull vendors, POs, receipts and bill status from each company's ERP now (also runs every 15 minutes)
	$(ARTISAN) extractiq:erp-sync --now

dev: ## Run API :8030, Horizon, Reverb :8031, scheduler and web :3030 together (native)
	npx -y concurrently@9.1.2 -k -n api,horizon,reverb,sched,web -c blue,magenta,cyan,gray,green \
		"cd api && $(PHP) artisan serve --port=8030 --no-reload" \
		"cd api && $(PHP) artisan horizon" \
		"cd api && $(PHP) artisan reverb:start --port=8031" \
		"cd api && $(PHP) artisan schedule:work" \
		"cd web && $(NPM) run dev"

test: test-api analyse test-web ## Pest + Larastan + web typecheck/lint (e2e: make e2e)

test-api: ## Pest test suite
	cd api && $(PHP) vendor/bin/pest

analyse: ## Larastan static analysis (level 5)
	cd api && $(PHP) vendor/bin/phpstan analyse --memory-limit=1G --no-progress

test-web: ## TypeScript + ESLint for the web app
	cd web && $(NPM) run typecheck && $(NPM) run lint

demo-video: ## Record docs/demo/extractiq-demo.mp4 from the running app (0.8× speed; approves one invoice, so it reseeds afterwards)
	cd web && CHROMIUM_PATH="$(CHROMIUM_PATH)" node scripts/demo-video.mjs
	$(MAKE) seed

e2e: ## Playwright smoke test against the running stack (make dev + make seed first)
	cd web && CHROMIUM_PATH="$(CHROMIUM_PATH)" npx playwright test
