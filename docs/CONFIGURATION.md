# Configuration

## Environment variable

| Variable | Description |
|----------|-------------|
| `BEACON_DSN` | Full Beacon DSN. Empty string disables outbound reporting. |

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
    ignore_exceptions: []
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
| `ignore_exceptions` | `[]` | List of exception FQCNs skipped by the automatic listener. |

## Important behavior

- Empty `dsn` means the bundle wires `NullBeaconClient`.
- `ignore_exceptions` only affects the automatic listener. Manual `captureException()` calls still send unless your own code avoids them.
- `timeout` applies to the synchronous HTTP request path.
- `verify_peer: false` also disables host verification in the underlying HTTP client and should stay limited to local dev.

## Development with self-signed certificates

```yaml
when@dev:
    nowo_beacon:
        verify_peer: false
```

Do **not** disable peer verification in production.
