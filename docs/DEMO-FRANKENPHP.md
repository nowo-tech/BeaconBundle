# Demo / FrankenPHP

BeaconBundle demos use **FrankenPHP + Caddy**. Runtime mode is selected with **`FRANKENPHP_MODE`** (`worker` by default; set `classic` for hot-reload-friendly `Caddyfile.dev`). See [Switching classic vs worker](#switching-classic-vs-worker-frankenphp_mode).

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
