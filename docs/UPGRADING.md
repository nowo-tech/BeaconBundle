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
    dsn: '%env(string:default::BEACON_DSN)%'
```

Prefer `%env(string:default::BEACON_DSN)%` so an empty env value resolves to `""` instead of `null`.

## Upgrading from 1.0.0 to 1.0.1

- Require PHP **8.2+** (PHP 8.1 is no longer supported).
- Require Symfony **7.0+** or **8.x** (Symfony 6.x is no longer supported).
- No public API changes.

## Upgrading within 1.0.1 → 1.0.5

- **1.0.2–1.0.3**: documentation / sample-app restore only; no consumer code changes.
- **1.0.4**: empty or unset `BEACON_DSN` disables the client at runtime without failing container compilation. Update config to `%env(string:default::BEACON_DSN)%` if you still use a bare `%env(BEACON_DSN)%` that can be empty.
- **1.0.5**: CI-only fix; no consumer changes.

## Upgrading to 1.0.6

- The repository sample app is **only** `demo/symfony8` (`http://localhost:8011`). `demo/symfony7` was removed.
- Bundle Composer constraints are unchanged: Symfony **`^7.0 || ^8.0`** remains supported for applications.
- No public API changes.

## Upgrading from 1.0.6 to 1.1.0

### New optional configuration

```yaml
nowo_beacon:
    register_console_listener: true   # ConsoleEvents::ERROR
    monolog_handler:
        enabled: false                # requires monolog/monolog
        level: error
    send:
        environment: true
        release: true
        server_name: true
        stacktrace: true
        request: true
        user: false                   # PII — opt-in
        runtime: true
        framework: true
        os: true
```

### New client APIs

- `BeaconClientInterface::addBreadcrumb(...)`
- `BeaconClientInterface::captureTransaction(...)`

### Behaviour

- Events always include precise `timestamp` / `datetime`; contexts depend on `send.*`.
- `send.user: true` may transmit personal data — align with your privacy policy.
- Local Beacon from the FrankenPHP demo: prefer `BEACON_DSN=http://KEY@host.docker.internal:9081/1` (see Symfony Beacon `docs/dsn.md`).

### Compatibility

- No breaking changes to existing `captureException` / `captureMessage` call sites.
- Apps without Monolog are unaffected when `monolog_handler.enabled` stays `false`.

## Upgrading from 1.1.0 to the next release

No upgrade notes yet.
