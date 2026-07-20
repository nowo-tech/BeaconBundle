# Upgrading

## First install -> 1.0.0

`1.0.0` is the first public BeaconBundle release. There is no earlier BeaconBundle version to migrate from.

Important defaults to remember on first install:

- empty `BEACON_DSN` means outbound reporting is disabled
- `verify_peer` defaults to `true`
- `register_error_listener` defaults to `true`
- `ignore_exceptions` affects only the automatic exception listener

Minimal first-install config:

```yaml
nowo_beacon:
    enabled: true
    dsn: '%env(default::BEACON_DSN)%'
```

## Upgrading from 1.0.0 to the next release

No upgrade notes yet.
