# Baseline specification — Beacon Bundle

**Last audited:** 2026-07-20

## Summary

Symfony client bundle that sends Envelope events to a self-hosted **Symfony Beacon** instance ([nowo-tech/symfony-beacon](https://github.com/nowo-tech/symfony-beacon)). Configuration uses a **DSN** embedding scheme, public key, optional secret, host, optional port, and project id (`BEACON_DSN`), so deployments can point at any domain/subdomain/port without hard-coded URLs.

## Goals

- Parse and validate Beacon DSNs
- POST Envelope NDJSON to `/api/{projectId}/envelope/`
- Authenticate via the DSN embedded in the envelope header
- Optional `kernel.exception` reporting
- Empty DSN (or `enabled: false`) disables sending
- Manual capture APIs for messages and exceptions

## Non-goals (v1)

- Full APM / session-replay feature parity with commercial SaaS products
- Hosting the Beacon server itself
- Client-side JS SDK
- Automatic retries / offline queue

## User stories

| ID | Story | Acceptance |
|----|-------|------------|
| US-01 | As a developer, I configure `BEACON_DSN` so events go to my Beacon host | DSN parsed at compile time; empty DSN → null client |
| US-02 | As a developer, I inject `BeaconClientInterface` and call `captureException` / `captureMessage` | Returns local `event_id`; POSTs envelope |
| US-03 | As a developer, uncaught HTTP exceptions are reported automatically | Listener on `kernel.exception` when enabled |
| US-04 | As a developer, I ignore noisy exception classes | `ignore_exceptions` skips by `instanceof` |
| US-05 | As a developer, local self-signed HTTPS works in dev | `verify_peer: false` disables TLS verify |
| US-06 | As a developer, network failures must not break the app request | Transport logs and returns false; no rethrow |

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
| FR-CL-003 | `isEnabled()` reflects DI wiring |
| FR-CL-004 | `NullBeaconClient` no-ops when disabled / empty DSN |
| FR-ENV-001 | Envelope is 3-line NDJSON: header (event_id, dsn, sent_at), item (`type: event`), payload |
| FR-ENV-002 | Content-Type `application/x-beacon-envelope` |
| FR-TR-001 | Non-2xx and transport errors are logged; `send()` returns false; no exception to caller |

### DI / Listener

| ID | Requirement |
|----|-------------|
| FR-DI-001 | Config keys: enabled, dsn, environment, release, server_name, verify_peer, timeout, register_error_listener, ignore_exceptions |
| FR-DI-002 | Invalid non-empty DSN fails container compilation |
| FR-LI-001 | Optional exception listener with request URI/method extras |

## Traceability

Maps to Nowo bundle REQ-* scaffold (docs, CI, demos, Spec Kit) plus product FRs above. See [docs/SPEC-DRIVEN-DEVELOPMENT.md](../../docs/SPEC-DRIVEN-DEVELOPMENT.md).
