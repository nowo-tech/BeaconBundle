# Demo / FrankenPHP

BeaconBundle demos use **FrankenPHP + Caddy**. The production Caddyfile enables worker mode, while the default development setup uses `Caddyfile.dev` without workers so file changes are visible on refresh.

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

That is the production-style worker setup. In local dev, the demo uses `Caddyfile.dev` instead, which keeps plain `php_server` and disables cache headers.

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
