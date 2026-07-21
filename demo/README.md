# Beacon Bundle Demo

Runnable sample app:

- `symfony8` — Symfony **8.1**, PHP **8.4+** (http://localhost:8011)

Bundle runtime still supports Symfony **7.x** via Composer (`^7.0 || ^8.0`); only the sample app is Symfony 8.

## Quick start

```bash
# Seed Beacon first (writes .demo-client.env with PUBLIC:SECRET DSN)
# cd ../../other/symfony-beacon && make bootstrap

make up-symfony8
```

The demo includes:

- FrankenPHP with Caddy (HTTP on `:80` inside container). Default **`APP_ENV=dev`** uses **Caddyfile.dev** (no PHP worker); see [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md) for production (worker) vs development.
- Web Profiler enabled in `dev`
- Nowo Twig Inspector enabled in `dev`
- `symfony/messenger` + console boom command
- Dedicated `Makefile` (`demo/symfony8/Makefile`) with `make sync-beacon`
- Routes covering messages, exceptions, full context, fingerprints, breadcrumbs, user, transactions / N+1, auto HTTP tx, Monolog, Messenger failures, and status

Configure `BEACON_DSN` in `.env` (copy from `.env.example` or `make sync-beacon`). **Secret is required.** Leave empty to exercise disabled mode.
