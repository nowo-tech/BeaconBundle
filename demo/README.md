# Beacon Bundle Demo

Runnable sample apps:

- `symfony7` — Symfony **7.4**, PHP **8.2+** (http://localhost:8010)
- `symfony8` — Symfony **8.1**, PHP **8.4+** (http://localhost:8011)

## Quick start

```bash
make up-symfony7
# or
make up-symfony8
```

Each demo includes:

- FrankenPHP with Caddy (HTTP on `:80` inside container). Default **`APP_ENV=dev`** uses **Caddyfile.dev** (no PHP worker); see [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md) for production (worker) vs development.
- Web Profiler enabled in `dev`
- Nowo Twig Inspector enabled in `dev`
- Dedicated `Makefile` under each demo folder
- Beacon routes for success, listener, fingerprint, ignored-exception, and disabled-mode scenarios

Configure `BEACON_DSN` in `.env` (copy from `.env.example`). Leave it empty to exercise disabled mode.
