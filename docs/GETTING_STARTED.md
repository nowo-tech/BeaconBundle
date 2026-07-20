# Getting started — connect BeaconBundle to Symfony Beacon

This guide walks through creating a project on **[Symfony Beacon](https://github.com/nowo-tech/symfony-beacon)** (the self-hosted error-tracking server) and wiring **BeaconBundle** so your Symfony app starts sending and collecting events.

All steps below assume English UI labels where applicable.

## Overview

```text
Your Symfony app (BeaconBundle)
        │  POST /api/{projectId}/envelope/
        │  Auth: DSN in envelope header
        ▼
Symfony Beacon server
        │  HTTP 200 ACK
        ▼
Messenger worker → Issues / Events in the dashboard
```

You need:

1. A running Symfony Beacon instance
2. A **project** with an active **API key** (DSN)
3. `BEACON_DSN` configured in your application
4. At least one capture path (automatic listener and/or manual API)

## 1. Run Symfony Beacon

Clone and start the server (Docker):

```bash
git clone https://github.com/nowo-tech/symfony-beacon.git
cd symfony-beacon
cp .env.dist .env
make up
make console ARGS='doctrine:migrations:migrate -n'
```

Default published ports (may differ if you override `.env`):

| Service | URL / port |
|---------|------------|
| HTTPS (recommended) | `https://localhost:9444` |
| HTTP | `http://localhost:9081` |
| Dashboard after login | `https://localhost:9444/dashboard` |

Keep the Messenger worker running (Compose usually starts a `messenger` service). Without it, ingest still returns `200`, but issues appear only after messages are consumed.

## 2. Create a project and obtain a DSN

You can use either the **seed command** (fastest locally) or the **dashboard UI**.

### Option A — Seed demo project (local)

```bash
make seed
# equivalent:
# make console ARGS='app:seed-demo'
```

The command creates (or reuses) a demo user, project, and API key, then prints:

```text
DSN: https://<public_key>@localhost:9444/<project_id>
Public key: <public_key>
Login: admin@symfony-beacon.local / admin123
```

Copy the **DSN** line. That value is what BeaconBundle needs as `BEACON_DSN`.

### Option B — Dashboard UI (empty or existing install)

1. Open `https://localhost:9444/en/register` if the database has **no users yet** (first-user registration), or `https://localhost:9444/en/login` otherwise.
2. After sign-in, go to **Dashboard** (`/dashboard`).
3. Create a **project** (or open an existing one).
4. Open the project **settings / API keys** section (owner or admin).
5. Create an API key (label e.g. `Production` or `Local app`).
6. Copy the generated **DSN** shown for that key.

DSN shape:

```text
https://<public_key>@<host>:<port>/<project_id>
```

Rules:

- The `public_key` must belong to the same `project_id` that appears in the path.
- Host and port must be reachable **from the machine that runs your Symfony app** (not necessarily from your browser). Inside Docker demos, use `host.docker.internal` (or the Beacon service hostname) instead of `localhost` when the app container must reach the host-published Beacon ports.

## 3. Install BeaconBundle in your Symfony app

```bash
composer require nowo-tech/beacon-bundle
```

With Symfony Flex, the recipe registers the bundle, adds `config/packages/nowo_beacon.yaml`, and documents `BEACON_DSN`.

Minimal config:

```yaml
# config/packages/nowo_beacon.yaml
nowo_beacon:
    enabled: true
    dsn: '%env(string:default::BEACON_DSN)%'
    environment: '%kernel.environment%'
    verify_peer: true
    register_error_listener: true
```

Prefer `string:default::BEACON_DSN` so an empty env value becomes `""` (disabled client) instead of `null`.

## 4. Set `BEACON_DSN`

```env
# .env.local (do not commit real keys)
BEACON_DSN=https://PUBLIC_KEY@localhost:9444/1
```

Examples:

| Context | Example DSN |
|---------|-------------|
| App on host, Beacon HTTPS on host | `https://KEY@localhost:9444/1` |
| App in Docker, Beacon on host HTTPS | `https://KEY@host.docker.internal:9444/1` |
| App in Docker, Beacon HTTP on host | `http://KEY@host.docker.internal:9081/1` |
| Production | `https://KEY@errors.example.com/3` |

Leave `BEACON_DSN` empty to disable outbound reporting without removing the bundle.

### Local self-signed HTTPS

Beacon’s local HTTPS certificate is often self-signed. In **dev only**:

```yaml
# config/packages/nowo_beacon.yaml
when@dev:
    nowo_beacon:
        verify_peer: false
```

Keep `verify_peer: true` in production.

## 5. Start collecting data

### Automatic (recommended)

With `register_error_listener: true` and a non-empty DSN, uncaught HTTP exceptions are reported via `kernel.exception`.

Trigger a test by throwing in a controller, or open the bundle demo route `/boom` if you use `demo/symfony8`.

### Manual

```php
use Nowo\BeaconBundle\Client\BeaconClientInterface;

public function __construct(private readonly BeaconClientInterface $beacon)
{
}

public function report(): void
{
    $this->beacon->captureMessage('Hello from my app', 'info', [
        'source' => 'getting-started',
    ]);

    try {
        // ...
    } catch (\Throwable $e) {
        $this->beacon->captureException($e, ['order_id' => 42]);
        throw $e;
    }
}
```

`captureMessage` / `captureException` return a local **event id** (32 hex chars) even before the server ACK. Delivery success must be confirmed in the Beacon UI or logs.

## 6. Verify in the Beacon dashboard

1. Log in to Symfony Beacon.
2. Open the project that owns the API key.
3. Check **Issues** / event list for your message or exception.
4. If ingest returned `200` but nothing appears, ensure the **messenger** consumer is running and wait a few seconds for async processing.

### Quick HTTP checks (server side)

Successful ingest (valid key + envelope) → `200`.  
Unknown key → `403`.  
Missing auth → `401`.  
Empty/invalid body → `400`.

BeaconBundle logs transport failures (`warning` for non-2xx, `error` for network/TLS errors) without breaking the user request.

## 7. Checklist

- [ ] Symfony Beacon is up (`make up` + migrations)
- [ ] Messenger worker is consuming messages
- [ ] Project + active API key exist; DSN copied
- [ ] App has `nowo-tech/beacon-bundle` installed
- [ ] `BEACON_DSN` set and reachable from the app host/container
- [ ] `verify_peer: false` only if using local self-signed HTTPS in `dev`
- [ ] Automatic listener enabled **or** manual `capture*` called
- [ ] Event visible in the Beacon project UI

## Demo app in this repository

The FrankenPHP demo under `demo/symfony8` already includes BeaconBundle. Copy `.env.example` → `.env`, set `BEACON_DSN`, then:

```bash
make -C demo/symfony8 up
# open http://localhost:8011/report  or  /boom
```

See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md) and [USAGE.md](USAGE.md) for the full route matrix.

## Related documents

- [Installation](INSTALLATION.md)
- [Configuration](CONFIGURATION.md)
- [Usage](USAGE.md)
- Symfony Beacon DSN notes: [nowo-tech/symfony-beacon docs/dsn.md](https://github.com/nowo-tech/symfony-beacon/blob/main/docs/dsn.md)
