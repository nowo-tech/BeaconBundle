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

## Running the demo

```bash
cd demo/symfony8
cp .env.example .env
# Example:
# BEACON_DSN=https://PUBLIC@host.docker.internal:9444/1
make up
```

The demo reads `PORT` from `.env` (default `8011`) and prints:

```text
Demo started at: http://localhost:<PORT>
```

## Beacon server for local E2E

The usual companion checkout is:

```text
/home/hector/nowo/developer.local.server/repositories/other/symfony-beacon
```

Its default ports are:

- HTTPS Beacon ingest: `https://localhost:9444`
- HTTP fallback: `http://localhost:9081`

See [`USAGE.md`](USAGE.md) for the end-to-end scenario matrix.
