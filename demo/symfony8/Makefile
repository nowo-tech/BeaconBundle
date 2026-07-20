.PHONY: help up down build install shell logs test test-coverage cache-clear update-bundle ensure-up restart verify assets sync-beacon

# Sibling checkout: repositories/other/symfony-beacon (override with BEACON_REPO=...)
# demo/symfony8 → demo → BeaconBundle → bundles → repositories → other/symfony-beacon
BEACON_REPO ?= $(abspath ../../../../other/symfony-beacon)
CLIENT_ENV := $(BEACON_REPO)/.demo-client.env

help:
	@echo "Symfony 8 Demo - Beacon Bundle"
	@echo "  make up             Start containers + install + build assets + sync DSN"
	@echo "  make sync-beacon    Copy BEACON_DSN from symfony-beacon .demo-client.env"
	@echo "  make down           Stop containers"
	@echo "  make restart        Restart containers"
	@echo "  make build          Build image (no cache)"
	@echo "  make install        Install Composer + pnpm dependencies"
	@echo "  make assets         pnpm install + Vite build (public/build/)"
	@echo "  make shell          Open shell in php container"
	@echo "  make logs           Stream container logs"
	@echo "  make test           Run tests"
	@echo "  make test-coverage  Run tests with coverage"
	@echo "  make update-bundle  Update nowo-tech/beacon-bundle and clear cache"
	@echo ""
	@echo "  BEACON_REPO=$(BEACON_REPO)"

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@if [ -f "$(CLIENT_ENV)" ]; then $(MAKE) sync-beacon; \
	else echo "Note: missing $(CLIENT_ENV) — in symfony-beacon run: make bootstrap, then here: make sync-beacon && make restart"; fi
	docker-compose up -d
	@echo "Waiting for container to be ready..."
	@sleep 5
	@echo "Installing dependencies..."
	@docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction
	@docker-compose exec -T php php bin/console assets:install public --symlink --no-interaction 2>/dev/null || true
	@$(MAKE) assets
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

sync-beacon:
	@if [ ! -f "$(CLIENT_ENV)" ]; then \
		echo "Missing $(CLIENT_ENV)"; \
		echo "In symfony-beacon: make up && make bootstrap"; \
		exit 1; \
	fi
	@if [ ! -f .env ]; then cp .env.example .env; fi
	@DSN=$$(grep -E '^BEACON_DSN=' "$(CLIENT_ENV)" | head -1 | cut -d= -f2- | tr -d '\r'); \
	if [ -z "$$DSN" ]; then echo "BEACON_DSN empty in $(CLIENT_ENV)"; exit 1; fi; \
	if grep -qE '^BEACON_DSN=' .env; then \
		sed -i.bak -E "s|^BEACON_DSN=.*|BEACON_DSN=$$DSN|" .env && rm -f .env.bak; \
	else \
		printf '\nBEACON_DSN=%s\n' "$$DSN" >> .env; \
	fi; \
	echo "Synced BEACON_DSN from $(CLIENT_ENV)"; \
	echo "BEACON_DSN=$$DSN"; \
	if docker-compose exec -T php true 2>/dev/null; then \
		echo "Reloading php container env..."; \
		docker-compose up -d --force-recreate php >/dev/null; \
	fi

ensure-up:
	@if ! docker-compose exec -T php true 2>/dev/null; then \
		echo "Starting demo container..."; \
		docker-compose up -d; \
		sleep 3; \
	fi

down:
	docker-compose down

restart: ensure-up
	docker-compose restart

build:
	docker-compose build --no-cache

install: ensure-up
	docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction
	docker-compose exec -T php php bin/console assets:install public --symlink --no-interaction 2>/dev/null || true
	docker-compose exec -T php sh -c 'test -f package.json && pnpm install || true'

assets: ensure-up
	docker-compose exec -T php sh -c 'pnpm install && pnpm run build'

shell: ensure-up
	docker-compose exec php sh

logs:
	docker-compose logs -f

test: ensure-up
	docker-compose exec -T -e APP_ENV=test php php bin/phpunit

test-coverage: ensure-up
	docker-compose exec -T -e APP_ENV=test php php bin/phpunit --coverage-text

cache-clear: ensure-up
	docker-compose exec -T php php bin/console cache:clear

update-bundle: ensure-up
	docker-compose exec -T php composer update nowo-tech/beacon-bundle --no-interaction
	docker-compose exec -T php php bin/console cache:clear --env=dev

verify: ensure-up
	@code=$$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$${PORT:-8011} 2>/dev/null || echo "000"); \
	if [ "$$code" != "200" ]; then echo "Healthcheck failed: $$code"; exit 1; fi; \
	echo "Healthcheck OK ($$code)"


# REQ-MAKE-008: update-deps (REQ-MAKE-008)
SERVICE_PHP := php
COMPOSE := docker-compose
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../..)
include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-update-deps.mk
