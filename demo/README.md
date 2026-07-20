# Beacon Bundle Demo

Runnable sample app:

- `symfony8` — Symfony **8.1**, PHP **8.4+** (http://localhost:8011)

## Quick start

```bash
make up-symfony8
```

The demo includes:

- FrankenPHP with Caddy (HTTP on `:80` inside container). Default **`APP_ENV=dev`** uses **Caddyfile.dev** (no PHP worker); see [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md) for production (worker) vs development.
- Web Profiler enabled in `dev`
- Nowo Twig Inspector enabled in `dev`
- Dedicated `Makefile` (`demo/symfony8/Makefile`)
- Beacon routes for success, listener, fingerprint, ignored-exception, and disabled-mode scenarios

The repository currently ships the Symfony 8 demo application. If a Symfony 7 demo is added again, keep this file and `docs/DEMO-FRANKENPHP.md` in sync.

## Release checks

```bash
make release-check
```

This runs PHPUnit in the demo (smoke), updates the path bundle, and verifies startup + HTTP healthcheck.
