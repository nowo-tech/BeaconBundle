# Demo / FrankenPHP

**REQ-DEMO-001:** FrankenPHP demos must install **Nowo Twig Inspector** and **Nowo Hot Reload** together (`nowo-tech/twig-inspector-bundle` + `nowo-tech/hot-reload-bundle` in `require-dev`). Caddyfile: Mercure + `hot_reload` (and `worker { file …; watch }` in worker mode). Do not enable Hot Reload in production.

BeaconBundle demos use **FrankenPHP + Caddy**. Runtime mode is selected with **`FRANKENPHP_MODE`** (`worker` by default; set `classic` for hot-reload-friendly `Caddyfile.dev`). See [Switching classic vs worker](#switching-classic-vs-worker-frankenphp_mode).

## Table of contents

- [Worker-mode snippet](#worker-mode-snippet)
- [Running the demo with Symfony Beacon (direct error ingest)](#running-the-demo-with-symfony-beacon-direct-error-ingest)
- [Running the demo](#running-the-demo)
- [Beacon server for local E2E](#beacon-server-for-local-e2e)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [PHP version policy (Symfony 8 demo)](#php-version-policy-symfony-8-demo)
- [Timeout hierarchy (REQ-RUNTIME-001)](#timeout-hierarchy-req-runtime-001)

## Worker-mode snippet

From `demo/symfony8/docker/frankenphp/Caddyfile`:

```caddyfile
:80 {
	root * /app/public
	encode zstd br gzip
	php_server {
		worker /app/public/index.php
	}
}
```

That is the default **`worker`** Caddyfile baked into the image. With `FRANKENPHP_MODE=classic`, the entrypoint switches to `Caddyfile.dev` (plain `php_server`, friendlier for file refresh).

## Running the demo with Symfony Beacon (direct error ingest)

Keep both repos as siblings under `repositories/` (or set `BEACON_REPO`):

```text
repositories/other/symfony-beacon
repositories/bundles/BeaconBundle
```

```bash
# 1) Beacon server — create Demo project + write .demo-client.env
cd ../../other/symfony-beacon   # adjust if needed
make up
make bootstrap                  # migrate + app:seed-demo → .demo-client.env

# 2) Bundle demo — syncs BEACON_DSN before starting containers
cd demo/symfony8                # from BeaconBundle root: demo/symfony8
make up
```

`make up` copies `BEACON_DSN` from `$(BEACON_REPO)/.demo-client.env` when that file exists.
Manual sync (after re-seeding Beacon):

```bash
make sync-beacon
```

**Smoke check (REQ-TEST-011):** from the bundle root, `make demo-smoke` boots `demo/symfony8` and asserts `HTTP 200` on `http://localhost:$PORT/` (default **8011**). Also `.github/workflows/demo-smoke.yml`. Fresh clones install Composer deps via an ephemeral `compose run` container first — FrankenPHP worker mode needs `vendor/` before the long-lived `php` service can stay up.

Then open `http://localhost:8011` and use `/full-context` or `/exception` (or `/boom` for the HTTP listener) to send errors into the seeded Demo project.

Docker clients must use **HTTP `:9081`** via `host.docker.internal` (not HTTPS `:9444`), with a DSN that includes the **secret** (`PUBLIC:SECRET@…`).

## Running the demo

```bash
cd demo/symfony8
cp .env.example .env
make up
```

The demo reads `PORT` from `.env` (default `8011`) and prints:

```text
Demo started at: http://localhost:<PORT>
```

## Beacon server for local E2E

The usual companion checkout is:

```text
repositories/other/symfony-beacon
```

Its default ports are:

- HTTPS UI / browser: `https://localhost:9444`
- HTTP ingest (Docker clients / this demo): `http://localhost:9081`

See [`USAGE.md`](USAGE.md) for the end-to-end scenario matrix.

## Switching classic vs worker (`FRANKENPHP_MODE`)

Demos select the FrankenPHP runtime via **`FRANKENPHP_MODE`** in `.env` / `.env.example` (not a Dockerfile `ENV`):

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Keep the worker Caddyfile (`php_server { worker ... }`) |
| **`classic`** | Entrypoint copies `Caddyfile.dev` (plain `php_server`, hot-reload friendly) |

Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

## PHP version policy (Symfony 8 demo)

The Symfony 8 demo uses the newest PHP minor published in official FrankenPHP images that satisfies the demo `require.php` (`>=8.4`). Current base image:

```dockerfile
FROM dunglas/frankenphp:1-php8.5-alpine
```

When FrankenPHP publishes a newer PHP (for example 8.6) and the demo Composer constraints allow it, bump the Dockerfile tag in a follow-up change. Older Symfony major demos (if added later) may keep an older PHP that matches that major.

## Timeout hierarchy (REQ-RUNTIME-001)

Outbound Beacon ingest uses Symfony HttpClient with an explicit **`timeout`** (and `max_duration`) from `nowo_beacon.timeout` (default **5.0** seconds). Keep this **below** the PHP / FrankenPHP request budget and any Caddy write timeout so the innermost deadline fires first:

| Layer | Typical value in this demo | Role |
| --- | --- | --- |
| Bundle HTTP ingest (`nowo_beacon.timeout`) | `5.0` s (configurable) | Aborts a hung Beacon POST; transport logs and returns `false` (no rethrow) |
| PHP / FrankenPHP request | Longer than ingest timeout | Worker must not stay blocked after ingest deadline |
| Caddy write / proxy | Longer still (platform default) | Outer HTTP edge |

Prefer `transport.mode: async` or `messenger` in production so HTML/API responses are not delayed by ingest RTT. See [PERFORMANCE.md](PERFORMANCE.md).
