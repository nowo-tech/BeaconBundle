# Beacon Bundle Demo (Symfony 8)

FrankenPHP demo application for exercising BeaconBundle routes against a local or remote Symfony Beacon server (**≥ 0.9.0** recommended; DSN secret required).

## Usage

```bash
cp .env.example .env
# Prefer syncing from a seeded Beacon:
#   cd ../../../other/symfony-beacon && make bootstrap
make sync-beacon   # copies BEACON_DSN with PUBLIC:SECRET from .demo-client.env
make up
```

Default URL: `http://localhost:8011`

Configure `BEACON_DSN` in `.env` before testing real delivery. Format:

```text
http://PUBLIC:SECRET@host.docker.internal:9081/PROJECT_ID
```

Leave `BEACON_DSN` empty to test disabled mode. Public-key-only DSNs are rejected (parse error / Beacon HTTP 403).

## Included dev stack

- Symfony Web Profiler
- Symfony debug mode (`APP_DEBUG=1`)
- `symfony/messenger` (Messenger failure demo)
- `nowo-tech/twig-inspector-bundle`
- `nowo-tech/password-toggle-bundle`

## Key routes

### Messages & exceptions

- `/` — home with the full scenario matrix
- `/report` — `captureMessage` info + send.* contexts
- `/report-error` — `captureMessage` error
- `/exception` — manual `captureException()` with previous + rich extra
- `/full-context` — densest sample (breadcrumbs, nested exception, fingerprint, checkout extra)
- `/boom` — uncaught listener-driven exception
- `/boom-ignored` — ignored `InvalidArgumentException` (not sent)
- `/fingerprint` — custom fingerprint + breadcrumbs + message stacktrace

### Context & performance

- `/breadcrumbs` — breadcrumbs then capture
- `/user` — authenticated user context (`send.user`; log in first)
- `/transaction` — performance transaction + spans
- `/transaction-nplus1` — six similar DB spans → Beacon N+1 group
- `/auto-http` — companion message; `auto_http_transaction` also fires on terminate

### Integrations

- `/monolog` — Monolog handler demo
- `/messenger-fail` — simulated final Messenger worker failure (`extra.messenger`)
- `php bin/console app:demo-console-boom` — console error listener

### Status

- `/status` — JSON runtime status (enabled, DSN secret presence, environment, release)
- `/login` — demo users (`debugger` / `debug`, `viewer` / `viewer`); after login redirects to `/user`

## Sample config highlights

See `config/packages/nowo_beacon.yaml`:

- Required DSN secret + `X-Beacon-Auth`
- `auto_http_transaction: true`
- `register_console_listener` / `register_messenger_listener`
- Full `send.*` (user enabled under `when@dev`)
