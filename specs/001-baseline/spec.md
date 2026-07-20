# Baseline specification — Beacon Bundle

**Last audited:** 2026-07-20  
**Aligned with:** public API / config through **v1.4.x**

## Summary

Symfony client bundle that sends Envelope events to a self-hosted **Symfony Beacon** instance ([nowo-tech/symfony-beacon](https://github.com/nowo-tech/symfony-beacon)). Configuration uses a **DSN** embedding scheme, public key, optional secret, host, optional port, and project id (`BEACON_DSN`), so deployments can point at any domain/subdomain/port without hard-coded URLs.

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

## Non-goals

- Full APM / session-replay feature parity with commercial SaaS products
- Hosting the Beacon server itself
- Client-side JS SDK
- Automatic retries / offline queue
- Shipping multiple major-version demos (sample app is **Symfony 8 only**; see `specs/002-solo-symfony8-demo`)

## User stories

| ID | Story | Acceptance |
|----|-------|------------|
| US-01 | As a developer, I configure `BEACON_DSN` so events go to my Beacon host | Empty/unset DSN → disabled client; valid DSN → Envelope POSTs |
| US-02 | As a developer, I inject `BeaconClientInterface` and call `captureException` / `captureMessage` | Returns local `event_id`; POSTs envelope |
| US-03 | As a developer, uncaught HTTP exceptions are reported automatically | Listener on `kernel.exception` when enabled |
| US-04 | As a developer, I ignore noisy exception classes | `ignore_exceptions` skips by `instanceof` (HTTP + console listeners) |
| US-05 | As a developer, local self-signed HTTPS works in dev | `verify_peer: false` disables TLS verify |
| US-06 | As a developer, network failures must not break the app request | Transport logs and returns false; no rethrow |
| US-07 | As a developer, I attach breadcrumbs before the next capture | `addBreadcrumb` → attached then cleared |
| US-08 | As a developer, I send performance transactions | `captureTransaction` → Envelope item `type: transaction` |
| US-09 | As a developer, I control outbound PII/context | `send.*` switches; `send.user` opt-in |
| US-10 | As a developer, Monolog errors can forward to Beacon | `monolog_handler.enabled` prepends `type: service` handler |

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
| FR-ENV-001 | Envelope is 3-line NDJSON: header (`event_id`, `dsn`, `sent_at`), item header, JSON payload |
| FR-ENV-002 | Content-Type `application/x-beacon-envelope` |
| FR-ENV-003 | Events include fractional `timestamp` and ISO-8601 `datetime` (microseconds, UTC) |
| FR-ENV-004 | With `send.stacktrace`, exceptions include frames + `culprit`; message events may include a current PHP stacktrace (BeaconBundle frames filtered). Readable files may add `abs_path` and source context (`pre_context` / `context_line` / `post_context`, ≈5 lines) |
| FR-ENV-005 | With `send.request` and an active HTTP request: `request` + `contexts.request` (url, method, query, safe header allow-list) and `extra.request_*` |
| FR-ENV-006 | `send.*` may attach environment, release, server_name, user, and `contexts` (runtime / framework / os) |
| FR-TR-001 | Non-2xx and transport errors are logged; `send()` returns false; no exception to caller |

### DI / Listeners / Monolog

| ID | Requirement |
|----|-------------|
| FR-DI-001 | Config keys include: enabled, dsn, environment, release, server_name, verify_peer, timeout, register_error_listener, register_console_listener, register_messenger_listener, auto_http_transaction, ignore_exceptions, monolog_handler.*, send.* |
| FR-DI-002 | Invalid **literal** DSN fails container compilation; `%env(...)%` / empty env resolves at runtime via `BeaconClientFactory` |
| FR-LI-001 | Optional HTTP exception listener |
| FR-LI-002 | Optional console error listener (`ConsoleEvents::ERROR`) |
| FR-LI-003 | Optional Messenger failure listener (`WorkerMessageFailedEvent`, final failures only; requires `symfony/messenger`) |
| FR-LI-004 | Optional automatic HTTP request transactions (`auto_http_transaction`, default false) |
| FR-MO-001 | When `monolog_handler.enabled` and Monolog is installed, register `BeaconMonologHandler` and prepend `monolog.handlers.nowo_beacon` as `type: service` |

## Sample application

- Maintained demo: `demo/symfony8` only (`http://localhost:8011`)
- Documents local ingest via `host.docker.internal:9081` for Docker → Beacon

## Traceability

Maps to Nowo bundle REQ-* scaffold (docs, CI, demos, Spec Kit) plus product FRs above. See [docs/SPEC-DRIVEN-DEVELOPMENT.md](../../docs/SPEC-DRIVEN-DEVELOPMENT.md) and [code-inventory.md](code-inventory.md).

## Language

Public specifications and integrator documentation in this repository are written in **English**.
