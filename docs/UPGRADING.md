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
- Local Beacon from the FrankenPHP demo: prefer `BEACON_DSN=http://KEY:SECRET@host.docker.internal:9081/1` (see Symfony Beacon `docs/DSN.md`).

### Compatibility

- No breaking changes to existing `captureException` / `captureMessage` call sites.
- Apps without Monolog are unaffected when `monolog_handler.enabled` stays `false`.

## Upgrading from 1.1.0 to 1.1.1

- Sample-app fix only (`demo/symfony8` login redirect). No consumer API or config changes.

## Upgrading from 1.1.1 to 1.2.0

### Richer message events

- With default `send.stacktrace: true`, `captureMessage()` now includes a current `stacktrace` (and `culprit`). Disable with `send.stacktrace: false` if you only want exception frames.
- With default `send.request: true`, events/transactions include `request` + `contexts.request` when an HTTP request is active (CLI remains unchanged).
- No breaking API changes; payload shape is richer under the existing defaults.

### Demo / recipe

- Sample app adds `symfony/monolog-bundle`, enables Monolog forwarding (`monolog_handler.enabled: true`), and documents `BEACON_RELEASE`.
- Flex recipe ships explicit `send.*` defaults and `release: '%env(string:default::BEACON_RELEASE)%'`.

## Upgrading from 1.2.0 to 1.3.0

### Stack source context

- With `send.stacktrace: true`, frames may include `abs_path`, `pre_context`, `context_line`, and `post_context` when the PHP file is readable (≈5 lines of context). No config flag; disable stacktraces entirely with `send.stacktrace: false` if you do not want file contents in payloads.
- Pair with **symfony-beacon `v0.5.0+`** to render source snippets in the Issues UI.

### Monolog handler wiring

- If you enabled `monolog_handler` but never saw Monolog records in Beacon, upgrade: the handler is now registered via `monolog.handlers` automatically. Remove any manual `type: service` duplicate if you added one by hand.

### Compatibility

- No breaking API changes to `captureException` / `captureMessage` / `captureTransaction`.

## Upgrading from 1.3.0 to 1.3.1

- Documentation / PHPDoc / spec inventory sync only. **No consumer API or config changes.**

## Upgrading from 1.3.1 to 1.4.0

### New optional configuration

```yaml
nowo_beacon:
    register_messenger_listener: true   # WorkerMessageFailedEvent (needs symfony/messenger)
    auto_http_transaction: false        # opt-in HTTP performance transactions
```

### Behaviour

- With default `register_messenger_listener: true`, final Messenger failures (no retry) are reported when `symfony/messenger` is installed. Disable if you do not want worker failures in Beacon.
- `auto_http_transaction` stays **off** by default. Enable only if you want one transaction per main request (routes under `/_profiler`, `/_wdt`, `/health/`, `/build` are skipped).
- `ignore_exceptions` also applies to the Messenger failure listener.

### Compatibility

- No breaking changes to existing capture APIs.
- Apps without Messenger are unaffected (listener is not registered when the Messenger event class is missing).

## Upgrading from 1.4.0 to 1.4.1

Demo / docs only. **No consumer API or config changes.**

- Local pairing with [symfony-beacon](https://github.com/nowo-tech/symfony-beacon) `v0.7.0+`: run `make bootstrap` on the server, then in `demo/symfony8` use `make sync-beacon` (or `make up`).
- Optional: hit `/transaction-nplus1` to exercise Beacon N+1 performance UI.
- Override the Beacon checkout path with `BEACON_REPO=/path/to/symfony-beacon` if repos are not siblings under `repositories/`.

## Upgrading from 1.4.1 to 1.4.2

Bugfix only. **No consumer API or config changes.**

- Message events with `send.stacktrace: true` again include `stacktrace` / `culprit` when the package is checked out under a path that contains `BeaconBundle` (typical GitHub Actions layout).

## Upgrading from 1.4.2 to 1.4.3

Dev / CI tooling only. **No consumer API or config changes.**

- `composer.lock` targets Symfony 7.4 again so installs on PHP 8.2 succeed; Symfony 8 apps are unchanged (constraints remain `^7.0 || ^8.0`).
- Contributors: run `make setup-hooks` so Cursor co-author trailers are stripped from commit messages.

## Upgrading from 1.4.3 to 1.5.0

### Breaking: DSN secret required

Symfony Beacon stores a secret on every generated API key and rejects public-key-only ingest with **HTTP 403**. BeaconBundle now requires the secret in `BEACON_DSN`:

```env
# Before (1.4.x — no longer accepted)
BEACON_DSN=https://PUBLIC@localhost:9444/1

# After (1.5.0+)
BEACON_DSN=https://PUBLIC:SECRET@localhost:9444/1
```

Copy the full DSN from Beacon project settings (or `.demo-client.env` after `make seed` / `make sync-beacon`).

### Auth wire format

Outbound requests now include:

- `X-Beacon-Auth: Beacon beacon_key=PUBLIC, beacon_secret=SECRET`
- Envelope header `"dsn": "https://PUBLIC:SECRET@host/projectId"` (unchanged shape, secret always present)

### Behaviour

- HTTP **429** is logged with `retry_after` when present. The transport still does **not** auto-retry (avoid stacking load on a rate-limited project).
- `BeaconDsn::getSecretKey()` returns `string` (no longer nullable).
- Empty `BEACON_DSN` still disables reporting via `NullBeaconClient`.

## Upgrading from 1.5.0 to the next release

- If you consume `dev-main` / `1.5.x-dev` from a path repo: Symfony constraints are again `^7.0 || ^8.0` (not `^7.4` only). Symfony 8 apps and `make update-deps` on `demo/symfony8` should resolve normally.
