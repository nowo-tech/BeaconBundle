# Baseline specification — Beacon Bundle

**Last audited:** 2026-11-01  
**Aligned with:** public API / config through **v1.19.x** (Messenger observability, deploy markers, self-test, Scheduler check-ins, Messenger remote retry, ingest retries, bounded synchronous `Retry-After`)

## Summary

Symfony client bundle that sends Envelope events to a self-hosted **Symfony Beacon** instance ([nowo-tech/symfony-beacon](https://github.com/nowo-tech/symfony-beacon)). Configuration uses a **DSN** embedding scheme, public key, **required secret**, host, optional port, and project id (`BEACON_DSN`), so deployments can point at any domain/subdomain/port without hard-coded URLs.

Integrator-facing docs (all **English**): [`README.md`](../../README.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md), [`docs/UPGRADING.md`](../../docs/UPGRADING.md), [`docs/CHANGELOG.md`](../../docs/CHANGELOG.md).

## Goals

- Parse and validate Beacon DSNs
- POST Envelope NDJSON to `/api/{projectId}/envelope/`
- Authenticate via the DSN embedded in the envelope header
- Optional `kernel.exception` and console error reporting
- Empty DSN (or `enabled: false`) disables sending at runtime without failing container compilation when using env placeholders
- Manual capture APIs for messages, exceptions, breadcrumbs, and performance transactions
- Configurable outbound context via `send.*` (stacktrace with optional source snippets, HTTP request, user, runtime/framework/os, …)
- Optional Monolog forwarding (`monolog_handler`) wired through MonologBundle `handlers`
- Path-based ignore list for HTTP exception reporting and auto HTTP transactions (`ignore_paths`)
- Safe operation under FrankenPHP worker mode when the kernel is **not** reset between requests (scenario B)
- Opt-in Messenger worker observability (transactions, retries, memory, queue snapshots) and deploy markers (`specs/004`, `specs/005`)
- Real-failure self-test command, public service and optional JSON endpoint (`specs/006`)
- Opt-in Symfony Scheduler check-ins: schedule catalog (with task descriptions) + per-run check-ins (`specs/007`)
- Opt-in remote retry / discard of failed Messenger messages from the Beacon UI (pull model) and failure-transport ids on final failures (`specs/008`)
- Retries with backoff when Beacon answers an Envelope POST with a server error (`transport.retry`, 1.18); synchronous sends never wait for a `Retry-After` above `max_delay_ms` (1.19.2)

## Non-goals

- Full APM / session-replay feature parity with commercial SaaS products
- Hosting the Beacon server itself
- Client-side JS SDK
- Offline queue / on-disk buffer of undelivered events (retries cover transient 5xx answers only)
- Retrying 4xx answers or transport errors (DNS, refused connection, timeout)
- Shipping multiple major-version demos (sample app is **Symfony 8 only**; see `specs/002-solo-symfony8-demo`)

## User stories

| ID | Story | Acceptance |
|----|-------|------------|
| US-01 | As a developer, I configure `BEACON_DSN` so events go to my Beacon host | Empty/unset DSN → disabled client; valid DSN → Envelope POSTs |
| US-02 | As a developer, I inject `BeaconClientInterface` and call `captureException` / `captureMessage` | Returns local `event_id`; POSTs envelope |
| US-03 | As a developer, uncaught HTTP exceptions are reported automatically | Listener on `kernel.exception` when enabled |
| US-04 | As a developer, I ignore noisy exception classes | `ignore_exceptions` skips by `instanceof` (HTTP + console listeners) |
| US-04b | As a developer, I ignore noisy HTTP paths by default | `ignore_paths` skips prefixes (defaults: profiler, WDT, build, assets, health, Chrome DevTools Appspecific probe); set `[]` to report all |
| US-05 | As a developer, local self-signed HTTPS works in dev | `verify_peer: false` disables TLS verify |
| US-06 | As a developer, network failures must not break the app request | Transport logs and returns false; no rethrow |
| US-07 | As a developer, I attach breadcrumbs before the next capture | `addBreadcrumb` → attached then cleared |
| US-08 | As a developer, I send performance transactions | `captureTransaction` → Envelope item `type: transaction` |
| US-09 | As a developer, I control outbound PII/context | `send.*` switches; `send.user` opt-in |
| US-10 | As a developer, Monolog errors can forward to Beacon | `monolog_handler.enabled` prepends `type: service` handler |
| US-11 | As a developer, I run FrankenPHP worker without kernel reset between requests | Breadcrumbs, spans, tags, trace id, auto HTTP transaction Request, and async pending POSTs do not leak across requests |
| US-12 | As an operator, I observe Messenger workers (slow handlers, retries, backlog, memory) | `nowo_beacon.messenger.*` opt-ins + `nowo:beacon:messenger-report` (`specs/004-messenger-observability`) |
| US-13 | As a developer, I mark deploys from CI | `nowo:beacon:deploy` sends a `deploy` item (`specs/005-deploy-markers`) |
| US-14 | As a developer, I prove the whole wiring with real failures | `nowo:beacon:self-test`, `BeaconSelfTest` service, optional `self_test.http` endpoint (`specs/006-self-test`) |
| US-15 | As an operator, Beacon tells me when a scheduled task did not run, ran too long or keeps failing | `nowo_beacon.scheduler.check_ins` + `#[BeaconCheckIn]` + `nowo:beacon:scheduler-catalog` (`specs/007-scheduler-check-ins`) |
| US-16 | As a project admin, I retry or discard one failed Messenger message from Beacon without SSH | `nowo_beacon.messenger.remote_retry` + `nowo:beacon:messenger-commands` (`specs/008-messenger-remote-retry`) |

### Acceptance scenarios

1. **Given** `BEACON_DSN` is empty or `enabled: false`, **When** the container compiles and a capture is called, **Then** `NullBeaconClient` is used and no HTTP request is made (US-01, FR-CL-004, FR-DI-002).
2. **Given** a valid DSN, **When** `captureMessage('ping', 'info')` is called, **Then** a local `event_id` is returned and one Envelope is POSTed to `{origin}/api/{projectId}/envelope/` with Content-Type `application/x-beacon-envelope` (US-02, FR-CL-001, FR-ENV-001/002).
3. **Given** `register_error_listener: true` and an exception class listed in `ignore_exceptions`, **When** a controller throws it, **Then** no event is sent; **When** it throws another class, **Then** one `error` event with the exception tree is sent (US-03, US-04, FR-LI-001).
4. **Given** a request path under an `ignore_paths` prefix (default `/_profiler`), **When** it throws, **Then** nothing is reported (US-04b, FR-LI-005).
5. **Given** Beacon is unreachable or answers 4xx, **When** an event is captured, **Then** the failure is logged once, `send()` returns false and the host request continues (US-06, FR-TR-001).
6. **Given** `transport.mode: sync` and Beacon answers 503 with `Retry-After: 300` while `transport.retry.max_delay_ms` is 5000, **When** an event is captured, **Then** no retry is made and the call returns at once with status 503; **given** `Retry-After: 1`, **Then** the send waits 1 s and retries (US-06, FR-TR-003).
7. **Given** `transport.mode: async` or `messenger` and Beacon answers 503 with `Retry-After` above `max_delay_ms`, **When** the pending POST is flushed or the Messenger handler runs, **Then** the full `Retry-After` is honoured before the retry (FR-TR-003).
8. **Given** breadcrumbs were added, **When** the next event is captured, **Then** they are attached and the buffer is cleared (US-07, FR-CL-005).
9. **Given** a FrankenPHP worker without kernel reset, **When** a second request starts, **Then** breadcrumbs, spans, tags, trace id and the auto HTTP transaction Request of the first request are gone (US-11, FR-WK-001/002).
10. **Given** `self_test.http.enabled: true` and a host whose routing is only `routing.controllers`, **When** the router loads, **Then** no `nowo_beacon_*` route exists; **given** the documented `type: nowo_beacon` import, **Then** `nowo_beacon_self_test` (GET) and `nowo_beacon_self_test_trigger` (POST) exist under `self_test.http.path` (US-14, FR-ST-006).

## Functional requirements

### DSN

| ID | Requirement |
|----|-------------|
| FR-DSN-001 | Accept `http`/`https` DSN `scheme://public[:secret]@host[:port]/projectId` |
| FR-DSN-002 | Reject empty, bad scheme, empty public key, invalid port, non-positive project id |
| FR-DSN-003 | Expose origin and envelope URL `{origin}/api/{projectId}/envelope/` |

### Client / Envelope

| ID | Requirement |
|----|-------------|
| FR-CL-001 | `captureMessage(message, level, extra?, fingerprint?)` builds and sends envelope |
| FR-CL-002 | `captureException(throwable, extra?, fingerprint?)` sends level `error` with exception tree |
| FR-CL-003 | `isEnabled()` reflects DI wiring / runtime enablement |
| FR-CL-004 | `NullBeaconClient` no-ops when disabled / empty DSN |
| FR-CL-005 | `addBreadcrumb(...)` buffers crumbs for the next event/transaction, then clears |
| FR-CL-006 | `captureTransaction(name, start, end, spans?, extra?)` sends Envelope item `type: transaction` |
| FR-CL-007 | `setTag` / `setTags` merge into Scope and attach on subsequent captures |
| FR-CL-008 | Optional `before_send` callable may scrub or drop events before transport |
| FR-SCOPE-001 | `Scope` holds tags, extras, and user overrides for the request lifetime |
| FR-ENV-001 | Envelope is 3-line NDJSON: header (`event_id`, `dsn`, `sent_at`), item header, JSON payload |
| FR-ENV-002 | Content-Type `application/x-beacon-envelope` |
| FR-ENV-003 | Events include fractional `timestamp` and ISO-8601 `datetime` (microseconds, UTC) |
| FR-ENV-004 | With `send.stacktrace`, exceptions include frames + `culprit`; message events may include a current PHP stacktrace (BeaconBundle frames filtered). Readable files may add `abs_path` and source context (`pre_context` / `context_line` / `post_context`, ≈5 lines). With `send.stack_vars`, frames may include `vars` (call arguments; opt-in) |
| FR-ENV-005 | With `send.request` and an active HTTP request: `request` + `contexts.request` (url, method, query, safe header allow-list) and `extra.request_*` |
| FR-ENV-006 | `send.*` may attach environment, release, server_name, user, and `contexts` (runtime / framework / os) |
| FR-TR-001 | Non-2xx and transport errors are logged; `send()` returns false; no exception to caller. HTTP uses configured `timeout` / `max_duration` and never follows a redirect (`max_redirects: 0`; a 3xx is a failed send), so the credentials never reach another host |
| FR-TR-002 | `transport.mode` `sync` \| `async` \| `messenger`; async/messenger finalize via flush listener or Messenger handler |
| FR-TR-003 | `transport.retry`: an Envelope POST answered with one of `status_codes` (default 500 / 502 / 503 / 504) is retried up to `max_retries` times with exponential backoff (`delay_ms` × `multiplier`ⁿ, bounded by `max_delay_ms`, `jitter`); 4xx and transport errors are not retried; a `Retry-After` header takes precedence over the backoff. **Synchronous sends** (`transport.mode: sync`) do not retry when `Retry-After` asks for more than `max_delay_ms` (0 = no bound), so they never stall the caller (REQ-RUNTIME-001). **Background sends** (`async` flush, the Messenger handler of `messenger` mode) honour a longer `Retry-After`; every wait still counts against `timeout` |
| FR-INS-001 | Opt-in `instrumentation.*` records Doctrine SQL and HttpClient spans/breadcrumbs |
| FR-ENV-007 | Database exceptions in the throwable chain add `contexts.db` (SQLSTATE / driver code, best-effort SQL) |
| FR-ENV-009 | Exception messages, console arguments / options, Monolog context and request URL / query / Referer go through `SensitiveValueRedactor`: credential-shaped values (`name=value`, `'name' => 'value'`, `"name": "value"`, env-style names, URL passwords including `/`, JWT / bearer / provider keys, `beacon_secret`, `bwk_…`, Luhn-valid card numbers) and sensitive keys / query parameters become `[Filtered]` (1.19.2, SEC-004 Appendix CH L1) |
| FR-SEC-001 | The DSN secret and the worker key are held in `SensitiveParameterValue`s and every DSN / key parameter is `#[SensitiveParameter]`: they never appear in `var_dump()`, `var_export()`, VarDumper, exception messages or trace arguments, and their holders refuse `serialize()`. Ingest and remote-command requests always pass `verify_peer` / `verify_host` explicitly and never follow redirects (1.19.2) |
| FR-ENV-008 | Envelope item types: `event`, `transaction`, `queue_snapshot`, `deploy`; `before_send` applies to events only; shapes pinned by golden fixtures in `tests/Contract/fixtures/envelope/` |

### DI / Listeners / Monolog

| ID | Requirement |
|----|-------------|
| FR-DI-001 | Config keys include: enabled, dsn, environment, release, server_name, verify_peer, timeout, transport.mode, register_error_listener, register_console_listener, register_messenger_listener, include_scheduler_context, auto_http_transaction, http_transaction_sample_rate, ignore_routes, ignore_exceptions, ignore_paths, monolog_handler.*, send.*, instrumentation.*, messenger.*, before_send |
| FR-DI-002 | Invalid **literal** DSN fails container compilation; `%env(...)%` / empty env resolves at runtime via `BeaconClientFactory` |
| FR-LI-001 | Optional HTTP exception listener |
| FR-LI-002 | Optional console error listener (`ConsoleEvents::ERROR`); nested `extra.console` with `command`, `exit_code`, `php_sapi`; MUST NOT attach argv/input arguments |
| FR-LI-003 | Optional Messenger failure listener (`WorkerMessageFailedEvent`, final failures only; requires `symfony/messenger`); when `include_scheduler_context` and envelope has `ScheduledStamp`, attach `extra.scheduler` (no message body); do not also capture `FailureEvent` (avoids duplicates / retries) |
| FR-LI-004 | Optional automatic HTTP request transactions (`auto_http_transaction`, default false); skipped paths come from `ignore_paths`, skipped route-name prefixes from `ignore_routes`; `http_transaction_sample_rate` (0.0–1.0) samples at request start (1.17) |
| FR-LI-005 | `ignore_paths` defaults MUST include `/_profiler`, `/_wdt`, `/build`, `/assets`, `/health`, and `/.well-known/appspecific/com.chrome.devtools.json` (aligned with typical Symfony Beacon host infra exclusions); trailing slashes are normalized; empty list disables path filtering; applies to HTTP exception listener and auto HTTP transactions only (not console/Messenger) |
| FR-PW-001 | Optional PHP warning interceptor reports warnings / notices as events without converting them into exceptions |
| FR-MQ-001..008 | Messenger observability — see [`specs/004-messenger-observability`](../004-messenger-observability/spec.md) (transactions, retries, `retry_state`, memory warning, `queue_snapshot`, flush after each worker message) |
| FR-DEP-001..003 | Deploy markers — see [`specs/005-deploy-markers`](../005-deploy-markers/spec.md) |
| FR-ST-001..008 | Self-test — see [`specs/006-self-test`](../006-self-test/spec.md) |
| FR-MO-001 | When `monolog_handler.enabled` and Monolog is installed, register `BeaconMonologHandler` and prepend `monolog.handlers.nowo_beacon` as `type: service` |
| FR-MO-002 | `BeaconMonologHandler` MUST NOT forward records flagged `EnvelopeTransport::LOG_CONTEXT_FLAG` (the transport's own failure / rate-limit logs): forwarding them loops while the receiver is down (1.17) |
| FR-WK-006 | `BeaconTraceMiddleware` is registered whenever `symfony/messenger` is installed (it is prepended to `messenger.bus.default` unconditionally) and passes envelopes through unchanged while the bundle is disabled or the DSN is empty |
| FR-WK-001 | On every **main** `kernel.request`, reset `BreadcrumbBuffer`, `SpanBuffer`, `Scope`, and `TraceIdProvider` (in addition to `kernel.reset` tags) so FrankenPHP workers without `services_resetter` do not leak per-request data |
| FR-WK-002 | `BeaconRequestTransactionListener` MUST clear stored Request/timing at the start of every main request (before ignore-path early returns) |
| FR-WK-003 | `FlushPendingTransportsListener` terminate priority MUST be lower than the auto HTTP transaction listener so async POSTs enqueued on terminate are drained in the same cycle; leftover pending MAY also flush at the next main request start |
| FR-WK-004 | `EnvelopeBuilder` source-line cache MUST be bounded (LRU); workers SHOULD still be recycled on deploy |
| FR-WK-005 | When `register_fatal_handler` is true, `BeaconFatalErrorHandler` MUST be registered once per process (`Bundle::boot()`); in worker mode the shutdown function runs when the worker exits |

## Success criteria

| ID | Criterion |
|----|-----------|
| SC-001 | Every production file under `src/` is mapped in [`code-inventory.md`](code-inventory.md): **100/100** (99 PHP + `services.yaml`), no placeholder IDs |
| SC-002 | PHPUnit line coverage of `src/` is **100%** (`make test-coverage-100`) on the CI matrix (PHP 8.2–8.5 × Symfony 7.4 / 8.x) |
| SC-003 | PHPStan, PHP-CS-Fixer, Rector (dry run) and Igor report no errors |
| SC-004 | Envelope shapes match the golden fixtures in `tests/Contract/fixtures/envelope/` (`make check-envelope-goldens` against Symfony Beacon) |
| SC-005 | No capture path throws into the host: transport failures and rejected ingest are logged and return false (FR-TR-001) |
| SC-006 | A synchronous send waits at most `max_delay_ms` per retry, whatever `Retry-After` Beacon sends (FR-TR-003) |
| SC-007 | With `self_test.http.enabled: true`, a `routing.controllers`-only host exposes no bundle route (FR-ST-006, REQ-SF-004) |

## Validation

```bash
make qa                 # cs-check + phpstan + PHPUnit
make test-coverage-100  # 100% line coverage gate
make igor               # worker-state audit
make release-check      # full pre-release chain
```

## Sample application

- Maintained demo: `demo/symfony8` only (`http://localhost:8011`)
- Documents local ingest via `host.docker.internal:9081` for Docker → Beacon

## Traceability

Maps to Nowo bundle REQ-* scaffold (docs, CI, demos, Spec Kit) plus product FRs above. See [docs/SPEC-DRIVEN-DEVELOPMENT.md](../../docs/SPEC-DRIVEN-DEVELOPMENT.md) and [code-inventory.md](code-inventory.md).

## Language

Public specifications and integrator documentation in this repository are written in **English**.
