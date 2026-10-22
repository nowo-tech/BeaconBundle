# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.17.1] - 2026-10-22](#1171---2026-10-22)
- [[1.17.0] - 2026-10-18](#1170---2026-10-18)
- [[1.16.0] - 2026-10-18](#1160---2026-10-18)
- [[1.15.0] - 2026-10-18](#1150---2026-10-18)
- [[1.14.0] - 2026-10-14](#1140---2026-10-14)
- [[1.13.1] - 2026-10-13](#1131---2026-10-13)
- [[1.13.0] - 2026-10-13](#1130---2026-10-13)
- [[1.12.0] - 2026-10-12](#1120---2026-10-12)
- [[1.11.1] - 2026-10-11](#1111---2026-10-11)
- [[1.11.0] - 2026-10-11](#1110---2026-10-11)
- [[1.10.1] - 2026-10-09](#1101---2026-10-09)
- [[1.10.0] - 2026-10-02](#1100---2026-10-02)
- [[1.9.1] - 2026-09-30](#191---2026-09-30)
- [[1.9.0] - 2026-09-30](#190---2026-09-30)
- [[1.8.3] - 2026-09-27](#183---2026-09-27)
- [[1.8.2] - 2026-09-24](#182---2026-09-24)
- [[1.8.1] - 2026-09-03](#181---2026-09-03)
- [[1.8.0] - 2026-08-26](#180---2026-08-26)
- [[1.7.8] - 2026-08-24](#178---2026-08-24)
- [[1.7.7] - 2026-08-20](#177---2026-08-20)
- [[1.7.6] - 2026-08-20](#176---2026-08-20)
- [[1.7.5] - 2026-08-19](#175---2026-08-19)
- [[1.7.4] - 2026-08-18](#174---2026-08-18)
- [[1.7.3] - 2026-08-16](#173---2026-08-16)
- [[1.7.2] - 2026-08-16](#172---2026-08-16)
- [[1.7.0] - 2026-08-12](#170---2026-08-12)
- [[1.6.11] - 2026-07-31](#1611---2026-07-31)
- [[1.6.10] - 2026-07-31](#1610---2026-07-31)
- [[1.6.9] - 2026-07-30](#169---2026-07-30)
- [[1.6.8] - 2026-07-30](#168---2026-07-30)
- [[1.6.7] - 2026-07-29](#167---2026-07-29)
- [[1.6.6] - 2026-07-28](#166---2026-07-28)
- [[1.6.5] - 2026-07-27](#165---2026-07-27)
- [[1.6.4] - 2026-07-27](#164---2026-07-27)
- [[1.6.3] - 2026-07-23](#163---2026-07-23)
- [[1.6.2] - 2026-07-22](#162---2026-07-22)
- [[1.6.1] - 2026-07-21](#161---2026-07-21)
- [[1.6.0] - 2026-07-21](#160---2026-07-21)
- [[1.5.1] - 2026-07-21](#151---2026-07-21)
- [[1.5.0] - 2026-07-21](#150---2026-07-21)
- [[1.4.3] - 2026-07-20](#143---2026-07-20)
- [[1.4.2] - 2026-07-20](#142---2026-07-20)
- [[1.4.1] - 2026-07-20](#141---2026-07-20)
- [[1.4.0] - 2026-07-20](#140---2026-07-20)
- [[1.3.1] - 2026-07-20](#131---2026-07-20)
- [[1.3.0] - 2026-07-20](#130---2026-07-20)
- [[1.2.0] - 2026-07-20](#120---2026-07-20)
- [[1.1.1] - 2026-07-20](#111---2026-07-20)
- [[1.1.0] - 2026-07-20](#110---2026-07-20)
- [[1.0.6] - 2026-07-20](#106---2026-07-20)
- [[1.0.5] - 2026-07-20](#105---2026-07-20)
- [[1.0.4] - 2026-07-20](#104---2026-07-20)
- [[1.0.3] - 2026-07-20](#103---2026-07-20)
- [[1.0.2] - 2026-07-20](#102---2026-07-20)
- [[1.0.1] - 2026-07-20](#101---2026-07-20)
- [[1.0.0] - 2026-07-20](#100---2026-07-20)

## [Unreleased]

## [1.17.1] - 2026-10-22

Wave-2 REQ compliance: pinned CI matrix with PHPStan and Igor, full release-check chain, demo login CSRF through the form type. See [UPGRADING](UPGRADING.md#to-1171).

### Changed

- CI: Symfony matrix 7.4 / 8.1 with every `symfony/*` package pinned to the cell minor (7.0 and 8.0 are end of maintenance and their releases carry security advisories; `composer.json` still allows them); the code-style job runs PHPStan and Igor on every push and pull request (REQ-SF-002, REQ-CS-005/006/008).
- `release-check` starts with `ensure-up` and runs the new `check-no-raw-html-form` gate; Rector capped at 2 parallel workers (REQ-MAKE-002).
- Demo login renders its CSRF token through `LoginFormType` (`_csrf_token` / `authenticate`) with the child-row loop instead of a raw hidden `<input>` (REQ-TWIG-005).
- Tests use `#[DataProvider]` instead of a `@dataProvider` docblock (REQ-TEST-010); the inline `@codeCoverageIgnore` markers are justified in the README (REQ-TEST-003).
- Docs: demo troubleshooting (REQ-DEMO-002); why the self-test routes come from the `nowo_beacon` loader (REQ-SF-004).
- `.scripts/check-no-cursor-coauthor.sh` accepts git worktrees (REQ-GIT-001).

## [1.17.0] - 2026-10-18

### Fixed

- `monolog_handler` no longer forwards the bundle transport's own logs ("Beacon ingest transport failed." / "Beacon ingest rate limited …" / "… authentication rejected" / "… rejected envelope"). While the receiver was down every failure log was captured, queued a new send to the same unreachable receiver, failed and logged again: one endless chain per failure (worst with `transport.mode: messenger`). `EnvelopeTransport` now sets the context flag `EnvelopeTransport::LOG_CONTEXT_FLAG` (`nowo_beacon_transport: true`) on every record it writes and `BeaconMonologHandler` skips flagged records; the other Monolog handlers still receive them. Found in Symfony Beacon, which had to drop these messages in a `before_send` hook.

### Added

- `http_transaction_sample_rate` (0.0–1.0, default `1.0`): fraction of main HTTP requests that send an `auto_http_transaction` transaction, decided at request start.
- `ignore_routes` (default `[]`): route-name prefixes whose `auto_http_transaction` transactions are never sent (e.g. ingest, polling or health routes), in addition to the path-based `ignore_paths`.

## [1.16.0] - 2026-10-18

### Added

- Retry or discard one failed Messenger message from the Beacon UI (Beacon spec 154, bundle spec `specs/008-messenger-remote-retry`), off by default: `nowo_beacon.messenger.remote_retry.{enabled,worker_key,allowed_actions,failure_transports,poll_interval,poll_in_workers}`. Workers poll Beacon over outbound HTTPS (`POST /api/{project}/messenger/commands/claim` and `…/{id}/result`, `Authorization: Bearer bwk_…`, 3 s timeout) between messages; the app exposes no endpoint. Commands are HMAC-SHA256 signed with an HKDF subkey of the worker key, expire after 15 minutes and are executed at most once (24 h executed-ids cache). Retry re-sends the envelope to its original transport without `SentToFailureTransportStamp` / `RedeliveryStamp` and then rejects it from the failure transport; discard rejects it; handlers never run in the poller. Results carry a reason code and the exception class only. Backoff 30 → 600 s, `Retry-After`, one-hour pause on `401` / `403` / `404`.
- `nowo:beacon:messenger-commands [--once] [--time-limit=…]` to poll from cron or one dedicated process (`poll_in_workers: false`).
- Final Messenger failure events carry `extra.messenger.failure_transport` and `extra.messenger.failure_message_id` (the id in the failure transport) when the failure transport can find messages by id (Doctrine, Redis): `RecordingFailureSenderPass` wraps the senders of Symfony's `SendFailedMessageToFailureTransportListener`.
- Every `schedule_catalog` entry carries `description` (string|null, plain text, one line, ≤ 500 chars): `#[BeaconCheckIn(description: …)]`, then `scheduler.check_ins.descriptions` (FQCN or command name), then the console command description (`RunCommandMessage`, lazy commands are not instantiated) or the target method's docblock summary (`ServiceCallMessage`), then the message class docblock summary. Never arguments or payload values. Golden `schedule_catalog.ndjson` updated.
- Contract fixtures `tests/Contract/fixtures/messenger_commands/` (signing vectors, claim / result bodies) shared with Symfony Beacon.
- Demo: Doctrine (MySQL) `async` / `failed` transports, a failing message and `make worker` / `make messenger-commands` for the remote retry flow.

### Changed

- `BeaconMessengerFailedListener` runs at priority **-150** (was 0): after Symfony's failure-transport listener (-100), so the failure-transport id is known; still before the Scheduler check-in (-512) and transaction (-1024) listeners.
- `instrumentation.http_client` also skips Beacon's remote Messenger command calls.

## [1.15.0] - 2026-10-18

### Added

- Symfony Scheduler check-ins (Beacon spec 153, bundle spec `specs/007-scheduler-check-ins`), off by default: `nowo_beacon.scheduler.check_ins.enabled`. A Scheduler worker (`messenger:consume scheduler_<name>`) sends a `schedule_catalog` Envelope item on start (and every `catalog_refresh_hours`) with each recurring task (recurring id, message class, command name for `RunCommandMessage`, trigger kind / expression / time zone / interval / description, next run, max runtime), and one `check_in` item per run (`in_progress`, `ok`, `error`) with a deterministic `run_id`, timestamps, duration, next run and `failure_event_id`. Each item travels in its own Envelope; message payloads and command arguments are never sent. Works with `sync`, `async` (the catalog and `in_progress` are flushed immediately) and `messenger` transport modes.
- `#[BeaconCheckIn(maxRuntime: …, enabled: …)]` attribute and `scheduler.check_ins.max_runtime` / `ignore` / `catalog_refresh_hours` options (the attribute wins over the config).
- `nowo:beacon:scheduler-catalog [--schedule=…] [--dry-run]` command.
- `CheckInCapableInterface` (`captureScheduleCatalog()`, `captureCheckIn()`) on `BeaconClient` / `NullBeaconClient`; custom clients stay compatible.
- `extra.scheduler.run_id` on Messenger failure events of scheduled messages.
- Golden Envelope fixtures `schedule_catalog.ndjson`, `check_in_ok.ndjson`, `check_in_error.ndjson`.
- Demo: `default` schedule with a fast, a slow (`maxRuntime`), a failing and a console-command task (`make scheduler`).

## [1.14.0] - 2026-10-14

### Fixed

- Messenger listeners (final failure, retry / recovered, `messenger.transactions`) no longer report on Beacon's own `SendBeaconEnvelopeMessage`. With `transport.mode: messenger` and `messenger.transactions: true`, each handled send produced a transaction that queued another send, an endless loop; a failed send under `report_retries` did the same. Found in Symfony Beacon (audit SCALE-SELF-INGEST-LOOP), which had to drop these events with a `before_send` hook.

### Added

- `messenger.ignore_message_classes`: message FQCNs (subclasses / interfaces match) the Messenger listeners never report.
- `PERFORMANCE.md`: routing recipe for `transport.mode: messenger` (own transport, `max_retries: 0`, consumed last, `sync://` in tests).

## [1.13.1] - 2026-10-13

### Security

- `send.request`: URL credential parameters are now also redacted in `request.url`, `request.query_string`, the `Referer` header and `extra.request_uri`: `hash` (Symfony login links `?user=…&expires=…&hash=…`), `_hash`, `signature` (incl. `X-Amz-Signature`), `sig` and `code` (OAuth/OIDC authorization callbacks), in addition to the `token`/`password`/`api_key`/… rules. 1.13.0 did not match these names, so login-link hashes and OAuth codes were still sent verbatim. The extra names apply to URLs only; Monolog context, console input and stack vars keep the existing key rules.

## [1.13.0] - 2026-10-13

### Security

- `send.request`: `request.url`, `request.query_string`, the `Referer` header and `extra.request_uri` now redact secret-like query parameters (`token`, `password`, `api_key`, `secret`, `auth`, … — same rules as `SensitiveValueRedactor::redactMap()`) to `[Filtered]`, plus `user:pass@` userinfo. Previously reset/magic-link tokens passed as `?token=` reached the Beacon server verbatim (Symfony login-link `hash` and OAuth `code` parameters were not covered; fixed in Unreleased).
- SQL descriptions (`instrumentation.doctrine` spans/breadcrumbs, `contexts.db`): `SqlNormalizer` now also scrubs numeric literals (`= 123456` → `= ?`, hex included) and double-quoted strings (`"…"` → `"?"`), not only single-quoted strings. Placeholders (`?`, `$1`) and digits inside identifiers are kept.

### Added

- `SensitiveValueRedactor::redactQueryString()` and `SensitiveValueRedactor::redactUrl()`.

## [1.12.0] - 2026-10-12

### Added

- **`nowo:beacon:self-test`**: produces real failures captured by the bundle's own listeners (no hand-built Envelopes) — `uncaught-exception`, `php-error` (uncaught `DivisionByZeroError`), `php-warning`, `captured-exception`, `captured-message`, `messenger-failure`, or `all` returning kinds (`--type` alias, `--run` token). Validates enabled flag / DSN / client first; flushes async transports at the end. Events are tagged `beacon_selftest` / `beacon_selftest_kind`.
- **`Nowo\BeaconBundle\SelfTest\BeaconSelfTest`** public service (`status()`, `assertReady()`, `run()`, `flush()`, kind constants) with `SelfTestResult`, `SelfTestUnavailableException` and `BeaconSelfTestException`.
- **`BeaconSelfTestFailMessage`** + handler (registered only with `symfony/messenger`): route it to an async transport so a worker exercises the Messenger failure listener; synchronous dispatches are captured inline.
- **Optional JSON self-test endpoint** (`self_test.http.enabled`, default `false`; `path` `/_beacon/self-test`; `roles` `['ROLE_ADMIN']`): `GET` status + CSRF token, `POST {path}/{kind}` with CSRF; routes `nowo_beacon_self_test` / `nowo_beacon_self_test_trigger` via the new `nowo_beacon` route loader.
- Spec `006-self-test`.

### Changed

- Dev dependency `symfony/security-csrf`; PHP warning handler tests cover every level label and the fail-soft path.

## [1.11.1] - 2026-10-11

### Fixed

- **Tests:** `EnvelopeBuilderTest::testHandlerFailedCulpritPointsAtRootCause` no longer relies on PHP 8.4+ closure frame names (`{closure:…}`), so it passes on PHP 8.2 / 8.3. No bundle code change.

## [1.11.0] - 2026-10-11

### Added

- **Messenger transactions** (`messenger.transactions`, opt-in): one performance transaction per handled message (`extra.messenger.op = queue.process`, `status` `ok` / `failed` / `retry`, handler, transport, retry count) with the Doctrine / HttpClient spans recorded while handling. Optional `messenger.transaction_sample_rate`.
- **Retry visibility** (`messenger.report_retries` / `messenger.report_recovered`, opt-in): `warning` event for failures Messenger will retry (`retry_state: will_retry`, own fingerprint) and `info` event when a message succeeds after retries (`retry_state: recovered`). Optional `messenger.retry_sample_rate`.
- **Worker memory warning** (`messenger.worker_memory_warning_ratio`, opt-in): one `warning` per worker process when memory reaches the configured fraction of `memory_limit`.
- **`nowo:beacon:messenger-report`**: sends a `queue_snapshot` Envelope item with the message count per Messenger transport and the failure transports (`messenger.failure_transports`, default `['failed']`; `--failure-transport`, `--dry-run`). New `QueueSnapshotCapableInterface` implemented by `BeaconClient` / `NullBeaconClient`.
- **`nowo:beacon:deploy`**: sends a `deploy` Envelope item (release — defaults to `nowo_beacon.release` —, environment, `--commit`, `--url`, `--name`) so Symfony Beacon can show deploy markers and flag issues first seen right after a deploy. New `DeployCapableInterface` implemented by `BeaconClient` / `NullBeaconClient`.
- Golden Envelope fixtures `transaction_messenger.ndjson`, `queue_snapshot.ndjson` and `deploy.ndjson`.
- Specs `004-messenger-observability` and `005-deploy-markers`; baseline spec / code inventory re-audited against 1.11.0.

### Changed

- **Messenger final failures** add `extra.messenger.retry_state` (`exhausted` after retries, otherwise `failed`) and `handler_classes` when more than one handler failed.
- **Culprit** for `HandlerFailedException` is taken from the root cause (application code) instead of Messenger middleware frames.

### Fixed

- **`transport.mode: async` in Messenger workers:** pending envelopes were only flushed on `kernel.terminate` / `console.terminate`, which a long-running `messenger:consume` never reaches until it stops — worker failures (and any event captured while handling a message) stayed queued until the worker restarted. `FlushPendingTransportsListener` now also flushes on `WorkerMessageHandledEvent`, `WorkerMessageFailedEvent` and `WorkerStoppedEvent` (after the Beacon listeners).

## [1.10.1] - 2026-10-09

### Fixed

- **CI:** PHPUnit config sets `zend.exception_ignore_args=0` (and the stack-vars test enforces it) so `send.stack_vars` tests pass on GitHub-hosted PHP where exception args are ignored by default.

### Dependencies

- `igor-php/igor-php` require-dev constraint bumped to `^0.10.0`; lock refreshed (Symfony 8.1.8 dev lock, PHPStan 2.3.1, phpstan-phpunit 2.1.1, phpstan-symfony 2.1.0, Rector 2.7.0, `nowo-tech/phpstan-frankenphp` 1.2.3).
- Demo `symfony8`: Symfony 8.1.8, Twig 3.30.0, `symfony/monolog-bundle` 4.1.0, PHPUnit 13.4.1, `nowo-tech/password-toggle-bundle` 2.2.3, `nowo-tech/hot-reload-bundle` 1.5.4.

## [1.10.0] - 2026-10-02

### Added

- **`send.stack_vars`**: opt-in Sentry-compatible `frame.vars` from PHP call arguments (reflection parameter names, size caps, `SensitiveValueRedactor`). Off by default (PII). Requires `send.stacktrace`.

## [1.9.1] - 2026-09-30

### Fixed

- **PHP 8.2 parse error:** drop typed class constant (`const int DEFAULT_LEVELS`) on `BeaconPhpWarningHandler` — typed constants require PHP 8.3+ and broke CI on PHP 8.2.

## [1.9.0] - 2026-09-30

### Added

- **PHP warning interceptor:** `register_php_warning_handler` (default `true`) + `php_warning_handler` options capture PHP warnings/notices as Beacon events (`level: warning` / `info`) **without** converting them to exceptions, so the request keeps running (e.g. undefined array key). Opt out with `register_php_warning_handler: false`. Deprecations are off by default; enable via `php_warning_handler.levels`.

## [1.8.3] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.8.3]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.8.3

## [1.8.2] - 2026-09-24

### Fixed

- **FrankenPHP worker mode (kernel not reset between requests)** – see [`docs/FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md):
  - New `BeaconRequestScopeResetListener` resets breadcrumbs, spans, scope tags and the trace id at the start of every main request (and flushes leftover async pending POSTs); `kernel.reset` tags are kept.
  - `BeaconRequestTransactionListener` clears stored Request/timing on every main request (including ignored paths) so timing cannot leak across users.
  - `FlushPendingTransportsListener` terminate priority is `-2048` so auto HTTP transactions enqueue before async flush in the same terminate cycle.
  - `EnvelopeBuilder` source-line cache is bounded (LRU, 64 files).
  - `BeaconFatalErrorHandler` is public and registered from `NowoBeaconBundle::boot()` (`register_fatal_handler` previously had no effect).

### Added

- Audit document `docs/FRANKENPHP-WORKER-AUDIT.md` and Spec Kit requirements FR-WK-001..005.

## [1.8.1] - 2026-09-03

### Fixed

- **CI / PHP 8.2:** regenerate `composer.lock` for PHP 8.2-compatible Symfony 7.4 resolution so Code Style Check can `composer install` on PHP 8.2.
- PHP 8.2-compatible constant usage where needed for CS/install jobs.

### Changed

- **Deps (dev):** refresh Composer lockfiles (includes newer php-cs-fixer).

[1.8.1]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.8.1
[1.8.2]: https://github.com/nowo-tech/BeaconBundle/compare/v1.8.1...v1.8.2

## [1.8.0] - 2026-08-26

### Added

- **`contexts.db`** on captured database exceptions: SQLSTATE, vendor code, driver hint, `sql_mode`, and SQL (quoted literals scrubbed, max 8 KiB). Built from `PDOException::$errorInfo`, duck-typed Doctrine `getSQLState()` / `getQuery()`, and Laravel-style `(SQL: …)` messages. Does **not** require `instrumentation.doctrine`. Symfony Beacon **107** renders this as the Query panel.

### Notes

- Additive Envelope field. Older Beacon servers ignore unknown `contexts` keys.
- No YAML changes.

[1.8.0]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.8.0

## [1.7.8] - 2026-08-24

### Changed

- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.7.8]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.7.8

## [1.7.7] - 2026-08-20

### Changed

- **Tests:** extra unit coverage for messenger, fatal errors, snapshots, and redaction (REQ-TEST-003).
- **Internal:** `BeaconFatalErrorHandler::captureFatalError()` is testable without `trigger_error(E_USER_ERROR)` (deprecated in PHP 8.4).

[1.7.7]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.7.7

## [1.7.6] - 2026-08-20

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

## [1.7.5] - 2026-08-19

Improve test coverage for trace, fatal, and console code paths (REQ-TEST-003).

### Changed

- **Tests:** additional unit coverage for Beacon trace/fatal/console handlers (~95% line coverage).

[1.7.5]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.7.5

## [1.7.4] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.7.4]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.7.4

## [1.7.3] - 2026-08-16

### Added

- Console command `nowo:beacon:test` probes Symfony Beacon with the configured DSN (sync Envelope POST). Supports `--check-only` (parse/display without sending) and `--message=`.
- `BeaconConnectionTester` + `EnvelopeTransport::sendDetailed()` for structured ingest diagnostics (HTTP status / transport errors without exposing secrets).

### Fixed

- `BeaconDsnParser` no longer uses a typed class constant (`const string`), restoring PHP **8.2** parse compatibility for CI and consumers still on 8.2.

## [1.7.2] - 2026-08-16

### Added

- Console errors include redacted `extra.console.arguments` / `options` / `interactive` (secret-like keys and credential URIs filtered; raw argv still never sent).
- Console extras also include `command_class`, `verbosity`, `cwd`, and `missing_arguments`.
- Messenger failures include `retry_count`, optional `bus` / `transport_message_id`, plus `handler_class`, `transport`, and `first_failure_at`; restores `BeaconTraceStamp` into the active trace.
- Monolog handler nests `extra.monolog.{channel,level}` and redacts `extra.context` keys/values.
- HTTP extras: nested `extra.http` with `route`, `controller`, `status_code`, `query_keys`; optional `client` IP/UA via `send.client` (default false).
- Correlation: `TraceIdProvider` + `extra.trace_id` / tag `trace_id` on every event; `X-Beacon-Trace-Id` request header; Messenger `BeaconTraceMiddleware`.
- Fatal PHP errors: `register_fatal_handler` (default true) shutdown capture with `extra.fatal`.

### Security

- Console/Monolog extras use `SensitiveValueRedactor` so passwords, tokens, DSNs, and private keys are not stored in Beacon.
- Raw argv and Messenger message bodies remain omitted.

## [1.7.0] - 2026-08-12

### Added

- DSN path may be a **canonical project UUID** (e.g. `…/019fea2d-507b-7890-8b33-ca488db6f696`) in addition to legacy positive numeric ids. Envelope URL becomes `/api/{uuid}/envelope/`.

### Changed

- `BeaconDsn::getProjectId()` now returns `string` (numeric ids are returned as digit strings such as `"1"`). Constructor accepts `string|int` for the project id.

## [1.6.11] - 2026-07-31

### Fixed

- `composer.lock`: keep `symfony/scheduler` on **7.4.x** so `composer install` works on PHP 8.2 (CI code-style / matrix). Symfony 8 apps still resolve `^8` via `composer update` on PHP ≥8.4.1.
- `make demo-smoke` / `demo/symfony8` `make up`: install Composer deps via ephemeral `compose run` **before** starting FrankenPHP worker (fresh CI clones have no `vendor/`, so the long-lived `php` service was exiting before `exec composer install`).

## [1.6.10] - 2026-07-31

### Added

- **`include_scheduler_context`** (default `true`): when a final Messenger failure envelope carries Symfony Scheduler `ScheduledStamp`, attach `extra.scheduler` (`schedule_name`, `recurring_id`, `triggered_at`, `trigger`). Message bodies are never attached. Requires `symfony/scheduler` at runtime for the stamp class; without it the flag is a no-op.

### Changed

- Console failure extras are nested under `extra.console` (`command`, `exit_code`, `php_sapi`) instead of flat `extra.console: true` + `extra.command` (see UPGRADING).
- `symfony/scheduler` added as `require-dev` for unit coverage of scheduled-task context.

### Security

- Console listener does **not** send argv / input arguments (credential leakage via cron wrappers).
- Scheduler context omits the scheduled message object payload.

## [1.6.9] - 2026-07-30

### Changed

- Spec Kit baseline (`specs/001-baseline/spec.md`, `code-inventory.md`) aligned with **v1.6.8** `ignore_paths` / FR-LI-005.

## [1.6.8] - 2026-07-30

### Added

- **`ignore_paths`** config for the HTTP exception listener and auto HTTP transactions (`IgnoredRequestPath`). Defaults reuse Beacon host infra exclusions (`/_profiler`, `/_wdt`, `/build`, `/assets`, `/health`) plus Chrome DevTools Appspecific probe `/.well-known/appspecific/com.chrome.devtools.json` (set `ignore_paths: []` to disable).

### Changed

- `auto_http_transaction` skip list is driven by `ignore_paths` (same defaults as before for profiler / WDT / health / build, plus `/assets` and the Chrome DevTools probe).
- README / recipe note `ignore_paths` alongside `ignore_exceptions`.

## [1.6.7] - 2026-07-29

### Changed

- Makefiles prefer `docker compose` (V2) with fallback to `docker-compose` (REQ-MAKE-010).
- Demo Makefile forces compose through the shell (`:;`) so a local `docker/` directory does not break Make’s direct-exec path.
- Optional `-include` of monorepo `update-deps` helpers so standalone CI checkouts do not fail (REQ-MAKE-009).

## [1.6.6] - 2026-07-28

### Added

- **`Psr\Clock\ClockInterface`** on `EnvelopeBuilder` / `BeaconClientFactory` (REQ-DI-001); runtime dep `psr/clock`.
- **`#[AsMessageHandler]`** on `SendBeaconEnvelopeMessageHandler` (REQ-MSG-001).
- **`make demo-smoke`** + `.github/workflows/demo-smoke.yml` (REQ-TEST-011).

### Changed

- PHPStan: `ignoreErrors: []` (REQ-CS-006).
- `docs/CONFIGURATION.md`: timeout hierarchy section (REQ-RUNTIME-001).
- **REQ-SEC-004**: AI security audit grade **Pass (good)** / risk **Low** recorded in `docs/SECURITY.md` and monorepo analysis (2026-07-28).
- `check-open-prs`: resolve `owner/repo` from `origin` and pass `-R` to `gh` (SSH remotes).

## [1.6.5] - 2026-07-27

### Fixed

- `composer.lock` content-hash refreshed so `composer validate --strict` succeeds after `make composer-sync`
- `make composer-sync` unsets `platform.php` before `composer update --lock` (Composer embeds platform in the lock hash while it is set)

## [1.6.4] - 2026-07-27

### Added

- `make check-open-prs` (REQ-REL-003) wired into `release-check` via `.scripts/check-open-prs.sh`
- Long-doc tables of contents across `docs/` (REQ-DOCS-005)
- Demo / docs: FrankenPHP PHP version policy and outbound HTTP timeout hierarchy (REQ-DEMO-010, REQ-RUNTIME-001)

### Changed

- Symfony 8 demo base image: `dunglas/frankenphp:1-php8.5-alpine` (newest Hub PHP allowed by demo constraints)
- PHPUnit + CI: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005)
- GitHub About: plain-text description, Packagist website, discoverability topics (REQ-DOCS-018)
- `docs/PERFORMANCE.md`, `docs/DEMO-FRANKENPHP.md`, `docs/RELEASE.md`, `docs/UPGRADING.md` aligned with the above

## [1.6.3] - 2026-07-23

### Added

- FrankenPHP Friendly Worker Mode banner + claim in root README (`docs/images/frankenphp-friendly.png`) after PHPStan FrankenPHP rules (`nowo-tech/phpstan-frankenphp`)
- Maintainer targets: `make down-dev`, `make test-coverage-100` / `make coverage-check` (fail unless PHP coverage is 100%)
- Unit/integration tests covering async/Messenger transports, flush listener, Doctrine SQL middleware, and remaining client/extension branches

### Changed

- PHP line coverage raised to **100%** (README badge + Tests and coverage section)
- Root README: standard badge line (Symfony **7.4 | 8.0 | 8.1+**), canonical `## Documentation` order + `### Additional documentation`, highlighted *Found this useful?* CTA
- Spec Kit baseline (`specs/001-baseline/`): inventory and FRs aligned with **v1.6.x** (`src/` complete)

### Fixed

- Minor internal hardening / import cleanups for coverage and static analysis (no public API or config changes)

## [1.6.2] - 2026-07-22

### Added

- Demo Symfony 8: `FRANKENPHP_MODE` (`worker` default / `classic`) via Compose + `docker/entrypoint.sh` (selects worker Caddyfile vs `Caddyfile.dev` without rebuilding the image)

### Changed

- Docs (`DEMO-FRANKENPHP.md`, demo/README, root README): document classic vs worker switching; recreate containers after changing `.env`

## [1.6.1] - 2026-07-21

### Added

- Golden Envelope **contract fixtures** + tests (`tests/Contract/`) aligned with Symfony Beacon ingest (Phase 3.6)
- `make check-envelope-goldens` to diff fixtures against sibling `symfony-beacon` checkout
- PHPUnit **Contract** testsuite in `phpunit.xml.dist`

## [1.6.0] - 2026-07-21

### Added

- Public tags API on `BeaconClientInterface` (`setTag` / `setTags` / `getTags` / `clearTags`) via request-scoped `Scope` (merged into event/transaction `tags`)
- `before_send` config: service id of an invokable that mutates or drops (`null`) the payload before transport; exceptions fail soft (event dropped)
- Opt-in `instrumentation.doctrine` (Doctrine DBAL middleware) and `instrumentation.http_client` (HttpClient decorator) for SQL / HTTP spans + breadcrumbs
- `SpanBuffer` drains into `captureTransaction()` (works with `auto_http_transaction`)
- `transport.mode`: `sync` (default), `async` (HTTP starts immediately; status finalized on `kernel.terminate` / console terminate), or `messenger` (queue via Symfony Messenger + `SendBeaconEnvelopeMessage`)
- Versioned `User-Agent` from Composer (`beacon-bundle/{pretty-version}` via `ClientUserAgent`)

### Changed

- `BeaconClient` depends on `EnvelopeTransportInterface` (sync / async / messenger implementations)
- Composer `branch-alias` / `root-version` aligned to **1.6.x**

## [1.5.1] - 2026-07-21

### Added

- Demo Symfony 8: `/full-context` (dense breadcrumbs + nested exception + fingerprint + checkout extra), `/messenger-fail`, `/auto-http`, and `app:demo-console-boom`
- Demo depends on `symfony/messenger`; sample `messenger.yaml` + `DemoFailMessage`
- Demo `nowo_beacon.yaml`: `auto_http_transaction`, explicit messenger/console listeners, `server_name`, comments for required DSN secret / `X-Beacon-Auth`

### Changed

- Demo routes send richer `extra` (client auth metadata, HTTP path, PHP runtime, nested checkout samples)
- Demo home / README / USAGE scenario matrix / DEMO-FRANKENPHP aligned with Symfony Beacon ≥ 0.9 and bundle ≥ 1.5 (secret required; `/boom` not `/listener-exception`)
- `/status` reports `has_secret_in_dsn`, `release`, and auth hint

### Fixed

- Restored Composer Symfony constraints to `^7.0 || ^8.0` (a CS Fixer CI job had accidentally committed `^7.4`-only requires, breaking the Symfony 8 demo / `make update-deps`)
- Code-style CI commits only `*.php` changes and uses `composer install` from the Symfony 7.4 lock again (avoids rewriting `composer.json`)

## [1.5.0] - 2026-07-21

### Changed

- **Breaking:** DSN secret is **required**. Format is now `https://PUBLIC:SECRET@host[:port]/projectId`. Public-key-only DSNs are rejected at parse time (Symfony Beacon returns HTTP 403 when the API key has a secret, which all generated keys do).
- Envelope transport sends `X-Beacon-Auth: Beacon beacon_key=…, beacon_secret=…` in addition to embedding the full DSN in the envelope header.
- HTTP 429 responses log `retry_after` when Beacon sends `Retry-After` (default server value: 60 seconds). Auth failures (401/403) get a dedicated warning.
- User-Agent updated to `beacon-bundle/1.5`.
- Composer `branch-alias` / `root-version` aligned to **1.5.x**.

### Security

- Aligns client auth with Symfony Beacon ingest: secret required, dual auth (HTTP header + envelope DSN), clearer handling of rate limits and rejected credentials.

## [1.4.3] - 2026-07-20

### Fixed

- CI / `composer.lock`: keep the lock on Symfony **7.4** so `composer install` works on PHP 8.2 (code-style jobs). `make composer-sync` pins `platform.php` to 8.2 while refreshing the lock; Symfony 8 remains covered by the matrix `composer update` path.

### Changed

- Git hygiene: broader Cursor co-author trailer stripping (`prepare-commit-msg` + `commit-msg`), always-on Cursor rule, and `make setup-hooks` guidance.

## [1.4.2] - 2026-07-20

### Fixed

- Message `stacktrace` attachment no longer drops the entire backtrace when the package path contains `BeaconBundle` (e.g. GitHub Actions checkout `…/BeaconBundle/BeaconBundle/…`).

## [1.4.1] - 2026-07-20

### Added

- Demo Symfony 8: `make sync-beacon` copies `BEACON_DSN` from sibling symfony-beacon `.demo-client.env` (also runs automatically from `make up` when that file exists).
- Demo route `GET /transaction-nplus1`: six similar DB spans so Beacon can show an N+1 group.

### Changed

- [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md): paired local flow with symfony-beacon `make bootstrap` + HTTP ingest on `:9081`.

## [1.4.0] - 2026-07-20

### Added

- `register_messenger_listener` (default `true`): report `WorkerMessageFailedEvent` when the message will not be retried (requires `symfony/messenger`).
- `auto_http_transaction` (default `false`): send a performance transaction for each main HTTP request (skips profiler / WDT / health / build).

## [1.3.1] - 2026-07-20

### Changed

- Baseline spec and code inventory updated for v1.3.x (breadcrumbs, transactions, `send.*`, Monolog wiring, stack source context).
- Clarified that public specs and integrator docs are maintained in English.
- Expanded English PHPDoc on bundle and demo PHP sources (public APIs and non-trivial helpers).

## [1.3.0] - 2026-07-20

### Added

- Stack frames include source context (`pre_context`, `context_line`, `post_context`, `abs_path`) when the file is readable (≈5 lines around the crash site)

### Fixed

- `monolog_handler.enabled` now prepends a `type: service` Monolog handler (the `monolog.handler` tag alone is not wired by MonologBundle).

## [1.2.0] - 2026-07-20

### Added

- `captureMessage()` attaches current PHP stacktrace when `send.stacktrace` is true (BeaconBundle frames filtered out).
- HTTP `request` / `contexts.request` (url, method, query, safe headers such as Host/User-Agent) on events and transactions when `send.request` is true and a request is available.
- Demo: full `send.*`, `release` / `BEACON_RELEASE`, `symfony/monolog-bundle` + Monolog handler enabled, richer `/fingerprint` sample.

### Changed

- Demo and Flex recipe document complete outbound context defaults (`send.*`, console listener, optional Monolog).

## [1.1.1] - 2026-07-20

### Fixed

- Demo Symfony 8: post-login redirect targeted missing route `demo_debug`; now redirects to `demo_user` (`/user`). Removed obsolete `/debug` access-control rule.

### Changed

- Applied PHP-CS-Fixer / Rector cleanups on sources and tests (no public API changes).
- Demo and README docs list 1.1.x routes (breadcrumbs, user, transaction, monolog) and accurate feature bullets.

## [1.1.0] - 2026-07-20

### Added

- Configurable `send.*` switches for outbound context (environment, release, server name, stacktrace, request, user, runtime/PHP, framework/Symfony, OS).
- Event payloads include fractional `timestamp`, ISO-8601 `datetime` (microseconds), and `contexts` (runtime / framework / os).
- Optional authenticated `user` context via Security token storage (`send.user`, default `false`).
- Breadcrumbs via `BeaconClientInterface::addBreadcrumb()` (attached to the next event/transaction, then cleared).
- Performance transactions via `captureTransaction()` (Envelope item `type: transaction`).
- Optional console error listener (`register_console_listener`, default `true`).
- Optional Monolog handler (`monolog_handler.enabled`, requires `monolog/monolog`).
- Demo routes: `/breadcrumbs`, `/user`, `/transaction`, `/monolog` (Symfony 8 sample).

### Changed

- Envelope `sent_at` / event `datetime` use microsecond precision.
- Demo `.env.example` documents HTTP ingest via `host.docker.internal:9081` for local Symfony Beacon.

### Fixed

- `BeaconConsoleErrorListener` constructor defaults so autowiring does not fail when arguments are not yet set.
- Do not autoload `BeaconMonologHandler` unless Monolog’s `AbstractProcessingHandler` is available (avoids 500 in apps without `monolog/monolog`).

## [1.0.6] - 2026-07-20

### Removed

- `demo/symfony7` — only `demo/symfony8` is maintained as the sample app (bundle runtime still supports Symfony 7 via Composer).

### Changed

- Demo aggregate Makefile and docs (`demo/README.md`, Getting started) now document a single FrankenPHP demo on http://localhost:8011.

## [1.0.5] - 2026-07-20

### Fixed

- CI: remove `composer config platform.php 8.4` for Symfony 8 jobs (Composer treats `8.4` as `8.4.0`, which fails Symfony 8.1’s `php >=8.4.1` requirement).

## [1.0.4] - 2026-07-20

### Added

- [Getting started](GETTING_STARTED.md) manual: create a Symfony Beacon project, obtain a DSN, configure BeaconBundle, and verify event collection.
- Runtime `BeaconClientFactory` so empty `%env(BEACON_DSN)%` disables reporting without failing container compilation.

### Fixed

- Regenerate `composer.lock` on Symfony **7.4** so `composer install` works on PHP 8.2 (CI code-style/coverage jobs). Symfony 8 remains supported via the CI matrix `composer update` path.
- Prefer `%env(string:default::BEACON_DSN)%` so empty env values resolve to `""` instead of `null`.

## [1.0.3] - 2026-07-20

### Fixed

- Changelog now reflects restoration of `demo/symfony7` (incorrect Unreleased note from `1.0.1` era removed).

## [1.0.2] - 2026-07-20

### Fixed

- Restored the Symfony 7 demo (`demo/symfony7`) that was accidentally removed in `1.0.1`.

## [1.0.1] - 2026-07-20

### Changed

- Raised minimum requirements to **PHP `>=8.2 <8.6`** and **Symfony `^7.0 || ^8.0`** (dropped PHP 8.1 and Symfony 6.x).
- CI matrix now runs PHP 8.2–8.5 × Symfony 7.0, 7.4, 8.0, and 8.1.

## [1.0.0] - 2026-07-20

### Added

- Initial `nowo-tech/beacon-bundle` release with DSN-based Envelope transport for Symfony Beacon.
- Automatic `kernel.exception` listener with `ignore_exceptions` support.
- Flex recipe and installation defaults centered on `BEACON_DSN`.
- Expanded documentation set for installation, configuration, usage, release, security, performance, Engram, and Spec Kit workflows.
- Demo routes covering message capture, manual exception capture, listener-triggered exceptions, ignored exceptions, fingerprints, and runtime status.

[Unreleased]: https://github.com/nowo-tech/BeaconBundle/compare/v1.17.1...HEAD
[1.13.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.13.0...v1.13.1
[1.13.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.12.0...v1.13.0
[1.12.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.11.1...v1.12.0
[1.11.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.11.0...v1.11.1
[1.11.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.10.1...v1.11.0
[1.10.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.10.0...v1.10.1
[1.10.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.9.1...v1.10.0
[1.9.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.9.0...v1.9.1
[1.9.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.8.3...v1.9.0
[1.7.2]: https://github.com/nowo-tech/BeaconBundle/compare/v1.7.0...v1.7.2
[1.6.11]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.10...v1.6.11
[1.6.10]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.9...v1.6.10
[1.6.9]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.8...v1.6.9
[1.6.8]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.7...v1.6.8
[1.6.7]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.6...v1.6.7
[1.6.6]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.5...v1.6.6
[1.6.5]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.4...v1.6.5
[1.6.4]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.3...v1.6.4
[1.6.3]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.2...v1.6.3
[1.6.2]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.1...v1.6.2
[1.6.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.5.1...v1.6.0
[1.5.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.4.3...v1.5.0
[1.4.3]: https://github.com/nowo-tech/BeaconBundle/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/nowo-tech/BeaconBundle/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.6...v1.1.0
[1.0.6]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.0.0
