# Engram

Short repository memory for BeaconBundle.

## What this repository is

BeaconBundle is the Symfony client for [`symfony-beacon`](https://github.com/nowo-tech/symfony-beacon). It sends Envelope HTTP requests to:

```text
/api/{project_id}/envelope/
```

The DSN is the source of truth for host, port, project id, public key, and required secret.

## Stable configuration keys

Keep these names stable unless a breaking change is intentional and documented:

- `enabled`
- `dsn`
- `environment`
- `release`
- `server_name`
- `verify_peer`
- `timeout`
- `register_error_listener`
- `ignore_exceptions`

## Operational reminders

- Empty DSN means Beacon is effectively off.
- `verify_peer: false` is for local self-signed dev only.
- The exception listener reports uncaught HTTP exceptions when enabled.
- `ignore_exceptions` applies to the listener, not to manual `captureException()` calls.
- Transport is synchronous, timeout-bounded, and has no retry loop.

## Documentation anchors

When behavior changes, check at least:

- [`README.md`](../README.md)
- [`USAGE.md`](USAGE.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`CHANGELOG.md`](CHANGELOG.md)
- [`UPGRADING.md`](UPGRADING.md)
- [`SECURITY.md`](SECURITY.md) if the change affects attack surface

## Demo context

The repository currently ships a Symfony 8 demo under `demo/symfony8`. Demo routes are used to validate success, listener, ignored exception, fingerprint, disabled DSN, TLS failure, and rejected-auth scenarios against a local Beacon server.
