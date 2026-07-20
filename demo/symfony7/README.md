# Beacon Bundle Demo (Symfony 8)

FrankenPHP demo application for exercising BeaconBundle routes against a local or remote Symfony Beacon server.

## Usage

```bash
cp .env.example .env
make up
```

Default URL: `http://localhost:8010`

Configure `BEACON_DSN` in `.env` before testing real delivery. Leave it empty to test disabled mode.

## Included dev stack

- Symfony Web Profiler
- Symfony debug mode (`APP_DEBUG=1`)
- `nowo-tech/twig-inspector-bundle`
- `nowo-tech/password-toggle-bundle`

## Key routes

- `/` home page with all demo links
- `/report` capture message with level `info`
- `/report-error` capture message with level `error`
- `/exception` manual `captureException()`
- `/boom` uncaught listener-driven exception
- `/boom-ignored` uncaught ignored `InvalidArgumentException`
- `/fingerprint` custom fingerprint example
- `/status` JSON runtime status
