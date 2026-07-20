# Configuration

## Environment variable

| Variable | Description |
|----------|-------------|
| `BEACON_DSN` | Full Beacon DSN. Empty string disables outbound reporting. |
| `BEACON_RELEASE` | Optional app/code version string (wire via `release`). |

## DSN format

```text
{scheme}://{public_key}[:{secret}]@{host}[:{port}]/{project_id}
```

Examples:

```env
# Local Symfony Beacon over HTTPS
BEACON_DSN=https://PUBLIC@localhost:9444/1

# Production subdomain
BEACON_DSN=https://PUBLIC@errors.example.com/7

# Internal HTTP with optional secret
BEACON_DSN=http://PUBLIC:SECRET@beacon.internal:9081/2
```

## YAML reference

```yaml
nowo_beacon:
    enabled: true
    dsn: '%env(string:default::BEACON_DSN)%'
    environment: '%kernel.environment%'
    release: null
    server_name: null
    verify_peer: true
    timeout: 5.0
    register_error_listener: true
    register_console_listener: true
    ignore_exceptions: []
    monolog_handler:
        enabled: false
        level: error
    send:
        environment: true
        release: true
        server_name: true
        stacktrace: true
        request: true
        user: false
        runtime: true
        framework: true
        os: true
```

| Key | Default | Meaning |
|-----|---------|---------|
| `enabled` | `true` | Master switch. Effective sending still requires a non-empty DSN. |
| `dsn` | `''` | Full Beacon DSN. Prefer `%env(default::BEACON_DSN)%`. |
| `environment` | `%kernel.environment%` | Environment tag sent with events. |
| `release` | `null` | Optional release string such as app version or git SHA. |
| `server_name` | `null` | Optional server/host tag. When null, the bundle falls back to the host name. |
| `verify_peer` | `true` | TLS verification for HTTPS ingest. |
| `timeout` | `5.0` | HTTP timeout in seconds. |
| `register_error_listener` | `true` | Registers the automatic `kernel.exception` listener. |
| `register_console_listener` | `true` | Reports uncaught console command errors. |
| `ignore_exceptions` | `[]` | List of exception FQCNs skipped by HTTP/console automatic listeners. |
| `monolog_handler.enabled` | `false` | Register `BeaconMonologHandler` (requires `monolog/monolog` + wire in `monolog.handlers`). |
| `monolog_handler.level` | `error` | Minimum Monolog level forwarded to Beacon. |

### `send.*` context switches

Each flag controls whether that category is attached to outbound events:

| Key | Default | Sent when enabled |
|-----|---------|-------------------|
| `send.environment` | `true` | `environment` |
| `send.release` | `true` | `release` (if configured) |
| `send.server_name` | `true` | `server_name` |
| `send.stacktrace` | `true` | Stack frames + `culprit` |
| `send.request` | `true` | `extra.request_uri` / `request_method` from the automatic listener |
| `send.user` | `false` | Authenticated user summary (`id` / `username` / `email` when available). **May include PII** — keep off unless your privacy policy allows it. |
| `send.runtime` | `true` | `contexts.runtime` (PHP version) |
| `send.framework` | `true` | `contexts.framework` (Symfony version when available) |
| `send.os` | `true` | `contexts.os` |

Timestamps (`timestamp` fractional Unix + `datetime` ISO-8601 UTC with microseconds) are always sent.

Example — diagnostics without request URLs or user identity:

```yaml
nowo_beacon:
    send:
        request: false
        user: false
```

## Important behavior

- Empty `dsn` means the bundle wires `NullBeaconClient`.
- `ignore_exceptions` only affects the automatic listener. Manual `captureException()` calls still send unless your own code avoids them.
- `timeout` applies to the synchronous HTTP request path.
- `verify_peer: false` also disables host verification in the underlying HTTP client and should stay limited to local dev.
- Enabling `send.user` transmits account identifiers to your Beacon host; align with GDPR / privacy policy and legal pages on the Beacon UI.

## Development with self-signed certificates

```yaml
when@dev:
    nowo_beacon:
        verify_peer: false
```

Do **not** disable peer verification in production.
