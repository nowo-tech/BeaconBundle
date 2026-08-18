# Upgrading

## Table of contents

- [First install -> 1.0.x](#first-install-10x)
- [Upgrading from 1.0.0 to 1.0.1](#upgrading-from-100-to-101)
- [Upgrading within 1.0.1 → 1.0.5](#upgrading-within-101-105)
- [Upgrading to 1.0.6](#upgrading-to-106)
- [Upgrading from 1.0.6 to 1.1.0](#upgrading-from-106-to-110)
  - [New optional configuration](#new-optional-configuration)
  - [New client APIs](#new-client-apis)
  - [Behaviour](#behaviour)
  - [Compatibility](#compatibility)
- [Upgrading from 1.1.0 to 1.1.1](#upgrading-from-110-to-111)
- [Upgrading from 1.1.1 to 1.2.0](#upgrading-from-111-to-120)
  - [Richer message events](#richer-message-events)
  - [Demo / recipe](#demo-recipe)
- [Upgrading from 1.2.0 to 1.3.0](#upgrading-from-120-to-130)
  - [Stack source context](#stack-source-context)
  - [Monolog handler wiring](#monolog-handler-wiring)
  - [Compatibility](#compatibility)
- [Upgrading from 1.3.0 to 1.3.1](#upgrading-from-130-to-131)
- [Upgrading from 1.3.1 to 1.4.0](#upgrading-from-131-to-140)
  - [New optional configuration](#new-optional-configuration)
  - [Behaviour](#behaviour)
  - [Compatibility](#compatibility)
- [Upgrading from 1.4.0 to 1.4.1](#upgrading-from-140-to-141)
- [Upgrading from 1.4.1 to 1.4.2](#upgrading-from-141-to-142)
- [Upgrading from 1.4.2 to 1.4.3](#upgrading-from-142-to-143)
- [Upgrading from 1.4.3 to 1.5.0](#upgrading-from-143-to-150)
  - [Breaking: DSN secret required](#breaking-dsn-secret-required)
  - [Auth wire format](#auth-wire-format)
  - [Behaviour](#behaviour)
- [Upgrading from 1.5.0 to 1.5.1](#upgrading-from-150-to-151)
  - [Symfony constraints / path installs](#symfony-constraints-path-installs)
  - [Demo sample app (optional)](#demo-sample-app-optional)
- [Upgrading from 1.5.1 to 1.6.0](#upgrading-from-151-to-160)
  - [Added (opt-in / additive)](#added-opt-in-additive)
- [Upgrading from 1.6.0 to 1.6.1](#upgrading-from-160-to-161)
- [Upgrading from 1.6.1 to 1.6.2](#upgrading-from-161-to-162)
- [Upgrading from 1.6.2 to 1.6.3](#upgrading-from-162-to-163)
- [Upgrading from 1.6.3 to 1.6.4](#upgrading-from-163-to-164)
- [Upgrading from 1.6.4 to 1.6.5](#upgrading-from-164-to-165)
- [Upgrading from 1.6.5 to 1.6.6](#upgrading-from-165-to-166)
- [Upgrading from 1.6.6 to 1.6.7](#upgrading-from-166-to-167)
- [Upgrading from 1.6.7 to 1.6.8](#upgrading-from-167-to-168)
- [Upgrading from 1.6.8 to 1.6.9](#upgrading-from-168-to-169)
- [Upgrading from 1.6.9 to 1.6.10](#upgrading-from-169-to-1610)
- [Upgrading from 1.6.10 to 1.6.11](#upgrading-from-1610-to-1611)
- [Upgrading from 1.7.3 to 1.7.4](#upgrading-from-173-to-174)
- [Upgrading from 1.7.2 to 1.7.3](#upgrading-from-172-to-173)
- [Upgrading from 1.7.0 to 1.7.2](#upgrading-from-170-to-172)
- [Upgrading from 1.6.11 to 1.7.0](#upgrading-from-1611-to-170)

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
- `auto_http_transaction` stays **off** by default. Enable only if you want one transaction per main request (`ignore_paths` are skipped; defaults cover profiler / WDT / build / assets / health / Chrome DevTools).
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

## Upgrading from 1.5.0 to 1.5.1

No consumer API or Envelope auth changes (still **1.5.0** wire format: required DSN secret + `X-Beacon-Auth`).

### Symfony constraints / path installs

If you consume `dev-main` / `1.5.x-dev` from a path repo or Packagist: Symfony constraints are again `^7.0 || ^8.0` (not `^7.4` only). Symfony 8 apps and `make update-deps` on `demo/symfony8` should resolve normally.

### Demo sample app (optional)

The FrankenPHP demo now exercises more scenarios. After pull:

```bash
cd demo/symfony8
make sync-beacon   # ensure BEACON_DSN includes PUBLIC:SECRET
make up
```

New routes / command: `/full-context`, `/messenger-fail`, `/auto-http`, `php bin/console app:demo-console-boom`. See [USAGE.md](USAGE.md) scenario matrix and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).

Demo `nowo_beacon.yaml` enables `auto_http_transaction` and installs `symfony/messenger` for the Messenger failure sample. Production apps can keep previous defaults.

## Upgrading from 1.5.1 to 1.6.0

### Added (opt-in / additive)

- **Tags**: `BeaconClientInterface::setTag()` / `setTags()` / `getTags()` / `clearTags()` — request-scoped; appear as Envelope `tags`.
- **`before_send`**: optional service id; invokable `(array $event): ?array`. Return `null` to drop; exceptions drop the event (fail soft).
- **`instrumentation.doctrine`** / **`instrumentation.http_client`**: default `false`. Enable to record SQL / HTTP spans (and breadcrumbs). Prefer with `auto_http_transaction: true`. Requires `doctrine/dbal` for Doctrine spans.
- **`transport.mode`**: default `sync` (unchanged behaviour). Set `async` to finalize HTTP on terminate, or `messenger` to queue `SendBeaconEnvelopeMessage` (requires `symfony/messenger` + a worker). User-Agent is now versioned from Composer (`beacon-bundle/{version}`).

No breaking changes to existing defaults.

## Upgrading from 1.6.0 to 1.6.1

No consumer API or config changes. Additive only:

- Optional golden Envelope contract fixtures / `make check-envelope-goldens` for maintainers aligning with Symfony Beacon ingest.
- PHPUnit discovers `tests/Contract` via a dedicated testsuite.

## Upgrading from 1.6.1 to 1.6.2

Demo / docs only. **No consumer API or config changes.**

- FrankenPHP demo: set `FRANKENPHP_MODE=worker` (default) or `classic` in `demo/symfony8/.env`. After changing it, recreate containers (`docker compose up -d` / `make up`), not a plain restart.
- See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).

## Upgrading from 1.6.2 to 1.6.3

Docs / QA / Spec Kit / coverage only. **No consumer API or config changes.**

- README badges, Documentation section order, FrankenPHP Friendly banner, and coverage claim updated for Nowo bundle standards.
- Maintainers: `make test-coverage-100` (alias `coverage-check`) enforces 100% PHP line coverage; `make down-dev` aliases `down`.
- Optional: ensure `nowo-tech/phpstan-frankenphp` is available in your fork’s `require-dev` if you run the same PHPStan FrankenPHP rulesets locally (already wired in this repo).

## Upgrading from 1.6.3 to 1.6.4

Docs / maintainer tooling / demo image only. **No consumer API or config changes.**

- Symfony 8 FrankenPHP demo: base image is `dunglas/frankenphp:1-php8.5-alpine`. Rebuild the demo image after pull (`make -C demo/symfony8 build` or `docker compose build`).
- Maintainers: `make release-check` now runs `check-open-prs` (fail if unresolved open GitHub PRs remain).
- CI / PHPUnit fail on **direct** Symfony deprecations (`SYMFONY_DEPRECATIONS_HELPER=max[direct]=0`).
- Long docs include a Table of contents; DEMO/PERFORMANCE document ingest timeout hierarchy under FrankenPHP.

## Upgrading from 1.6.4 to 1.6.5

Maintainer / CI only. **No consumer API or config changes.**

- `composer validate --strict` and `make composer-sync` work again after the `platform.php` lock-hash fix (no application code changes).

## Upgrading from 1.6.5 to 1.6.6

Backward compatible for typical apps.

- New runtime dependency: `psr/clock` (^1.0). Run `composer update nowo-tech/beacon-bundle`.
- If you **manually** instantiate `EnvelopeBuilder` or rely on a custom `BeaconClientFactory` wiring, you may pass an optional `Psr\Clock\ClockInterface` (defaults to system clock; DI injects it when available).
- `SendBeaconEnvelopeMessageHandler` carries `#[AsMessageHandler]` (Messenger mode unchanged when configured via the Extension).

## Upgrading from 1.6.6 to 1.6.7

Maintainer / demo tooling only. **No consumer API or config changes.**

- Root and demo Makefiles prefer `docker compose` when available (still fall back to `docker-compose`).
- Demo recipes invoke compose via the shell so a local `demo/symfony8/docker/` directory does not cause `make: docker: Permission denied`.
- Monorepo `update-deps` Makefile includes are optional (`-include`) so a standalone clone / GitHub Actions checkout does not break `make`.

## Upgrading from 1.6.7 to 1.6.8

Backward compatible for typical apps; default noise filtering is slightly broader.

- New optional config: `ignore_paths` (defaults listed in `CONFIGURATION.md` / `IgnoredRequestPath::DEFAULTS`).
- The **HTTP exception listener** now skips those paths (previously only `auto_http_transaction` had a hard-coded skip list).
- `auto_http_transaction` uses the same `ignore_paths` list (adds `/assets` and the Chrome DevTools Appspecific probe vs the old hard-coded set).
- To report every path again: `ignore_paths: []`.
- Replacing the list removes the defaults (same pattern as other array nodes).

## Upgrading from 1.6.8 to 1.6.9

Spec Kit / inventory docs only. **No consumer API or config changes.**

## Upgrading from 1.6.9 to 1.6.10

Backward compatible for typical apps; console `extra` shape changed for dashboards / custom parsers.

### Console `extra` shape

Previously:

```json
{ "console": true, "command": "app:demo" }
```

Now (Messenger-aligned nested object):

```json
{
  "console": {
    "command": "app:demo",
    "exit_code": 1,
    "php_sapi": "cli"
  }
}
```

Raw argv / input arguments are **never** attached (cron wrappers often pass secrets on the CLI).

### Scheduler context (optional)

New config `include_scheduler_context` (default `true`). When `symfony/scheduler` stamps a failing Messenger envelope with `ScheduledStamp`, final failures (`willRetry() === false`) also include:

```json
{
  "scheduler": {
    "schedule_name": "default",
    "recurring_id": "…",
    "triggered_at": "2026-07-31T10:00:00+00:00",
    "trigger": "0 * * * *"
  }
}
```

Disable with `include_scheduler_context: false`. Without `symfony/scheduler` installed the flag is a no-op. Scheduled **message bodies** are never sent.

## Upgrading from 1.6.10 to 1.6.11

Maintainer / CI / demo tooling only. **No consumer API or config changes.**

- `composer.lock` again pins `require-dev` Symfony packages (including `symfony/scheduler`) to **7.4** so PHP 8.2 CI `composer install` succeeds. Run `make composer-sync` after adding Symfony `require-dev` packages.
- Demo smoke / `make up` installs Composer deps before starting the FrankenPHP worker (needed for clean GitHub Actions checkouts).

## Upgrading from 1.7.3 to 1.7.4

Demos only: Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`). **No consumer API or config changes.**

```bash
composer update nowo-tech/beacon-bundle
```

## Upgrading from 1.7.2 to 1.7.3

Additive diagnostics only. **No breaking API or config changes.**

### Connection test command

After setting `BEACON_DSN`, verify ingest reachability and credentials:

```bash
php bin/console nowo:beacon:test
php bin/console nowo:beacon:test --check-only
php bin/console nowo:beacon:test --message='Hello from CI'
```

The probe always uses **synchronous** HTTP (ignores `transport.mode`) so the exit code matches the real ingest ACK. Console output never includes the DSN secret.

Optional: `symfony/console` (already present in typical FrameworkBundle apps). Suggested in `composer.json` for the `nowo:beacon:test` command.

### Compatibility

- Existing capture APIs and config keys unchanged.
- `EnvelopeTransport::send()` behaviour unchanged; `sendDetailed()` is additive for diagnostics.
- PHP **8.2** compatibility restored for `BeaconDsnParser` (typed class constants require 8.3+).

## Upgrading from 1.7.0 to 1.7.2

Additive / richer extras. **No breaking API changes.** Defaults stay privacy-safe (`send.client` off; fatals on).

### New optional configuration

```yaml
nowo_beacon:
    register_fatal_handler: true   # shutdown capture of fatal PHP errors
    send:
        client: false              # extra.http.client ip/UA (PII) — opt-in
```

### Behaviour

- HTTP exceptions (`send.request: true`): nested `extra.http` with `route`, `controller`, `status_code`, `query_keys`; optional `client` when `send.client: true`.
- Console: also `command_class`, `verbosity`, `cwd`, `missing_arguments`.
- Messenger: also `handler_class`, `transport`, `first_failure_at`; restores `BeaconTraceStamp` into the active trace before capture.
- Correlation: `TraceIdProvider` attaches `extra.trace_id` + tag `trace_id`; HTTP seeds/propagates `X-Beacon-Trace-Id`; Messenger gets `BeaconTraceMiddleware` on `messenger.bus.default`.
- Fatals: `register_fatal_handler: true` (default) reports `E_ERROR` / `E_PARSE` / … with `extra.fatal` (`type`, `file`, `line`).

### Compatibility

- Existing capture APIs unchanged. Disable fatals with `register_fatal_handler: false` if you already handle them.


## Upgrading from 1.6.11 to 1.7.0

### DSN project UUID

Symfony Beacon now emits DSNs whose path is a **project UUID**. `BeaconDsnParser` accepts:

- legacy positive numeric ids (`/1`)
- canonical UUIDs with hyphens (`/019fea2d-507b-7890-8b33-ca488db6f696`)

Arbitrary path segments remain rejected.

### Breaking: `BeaconDsn::getProjectId()` return type

Return type is now `string` (was `int`). Numeric DSNs still work; the getter returns `"1"` instead of `1`. Update any strict `int` type hints or `assertSame(1, …)` comparisons.

The constructor accepts `string|int` for the project id argument.

