# Upgrading

## First install -> 1.0.x

`1.0.x` is the first public BeaconBundle line. There is no earlier BeaconBundle version to migrate from.

Requirements:

- PHP `>=8.2 <8.6`
- Symfony `^7.0 || ^8.0`

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

## Upgrading from 1.0.0 to 1.0.1

- Require PHP **8.2+** (PHP 8.1 is no longer supported).
- Require Symfony **7.0+** or **8.x** (Symfony 6.x is no longer supported).
- No public API changes.

## Upgrading from 1.0.1 to the next release

No upgrade notes yet.
