# Feature Specification: Build the bundle on beacon-php (framework-free SDK)

**Feature Branch**: `feat/beacon-php-core`

**Created**: 2026-11-09

**Status**: Implemented, target **1.21.0** (unreleased)

**Input**: `nowo-tech/beacon-php` 0.1.0 `docs/BUNDLE-ADAPTER.md` (adapter plan) and the owner decisions of 2026-11-09: (1) the DSN secret is no longer sent in the Envelope body, only in `X-Beacon-Auth`; (2) redirects are never followed; (3) beacon-php's client-side scrubber runs before `before_send`, the bundle's own redaction stays, and the PHP-warning fingerprint is redacted (BUNDLE-ADAPTER L5). Everything else stays byte-identical to 1.20.0; public services, configuration and BC are preserved.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Same events, one shared core (Priority: P1)

As a maintainer, the bundle and beacon-php build envelopes with one implementation, so a wire-format fix lands once.

**Acceptance Scenarios**:

1. **Given** the 13 beacon-php golden inputs and 5 inputs for the 1.19.2 redaction rules, **When** the 1.21 builder builds them, **Then** the output equals the 1.20.0 recording byte for byte except the header `dsn`.
2. **Given** environment-dependent input (real source lines, runtime / OS / framework contexts, frame vars, the current stack, a Messenger `HandlerFailedException`, an array controller, integer query keys), **Then** the output equals a frozen copy of the 1.20.0 builder except the header `dsn`.
3. **Given** invalid UTF-8, **Then** building still throws `RuntimeException`.

### User Story 2 - The secret only travels in a header (Priority: P1)

As a security reviewer, the DSN secret is never in the Envelope body (logs, Messenger transports, proxies that store bodies).

**Acceptance Scenarios**:

1. **Given** any envelope, **Then** its header line is `{"event_id":…,"sent_at":…}` and `X-Beacon-Auth` carries key and secret.
2. **Given** a 3xx answer, **Then** it is not followed and the send fails.

### User Story 3 - Scrub before the hook (Priority: P1)

As an integrator, sensitive keys, credentials and card numbers are removed before `before_send`, so the hook and Beacon never see them.

**Acceptance Scenarios**:

1. **Given** `extra`, `tags` and breadcrumb data with sensitive keys / values, **When** an event or transaction is captured, **Then** they are `[Filtered]` in the payload passed to `before_send` and in the body sent.
2. **Given** a PHP warning whose message contains `password=…`, **Then** the fingerprint holds the redacted message.

## Requirements *(mandatory)*

- **FR-SDK-001**: `composer.json` requires `nowo-tech/beacon-php: ^0.1`.
- **FR-SDK-002**: `BeaconDsn`, `InvalidBeaconDsnException`, `Scope`, `BreadcrumbBuffer`, `TraceIdProvider`, `SpanBuffer` and `UserContextProviderInterface` extend their beacon-php counterparts; the bundle keeps `ResetInterface` and the 1.19.2 secret holder (`SensitiveParameterValue`, no serialization).
- **FR-SDK-003**: `EnvelopeBuilder` delegates to beacon-php's builder (`EnvelopeDsnMode::None`) and re-applies the bundle's 1.19.2 redaction to message, exception values, request URL / query string / referer and frame vars from the raw input.
- **FR-SDK-004**: `EnvelopeTransport` sends through beacon-php's `SymfonyHttpClientTransport` (`maxRedirects: 0`, bundle User-Agent, explicit TLS options) and logs through its `IngestResponseHandler`.
- **FR-SDK-005**: `BeaconClient` scrubs event / transaction payloads with beacon-php's `PayloadScrubber` before `before_send`.
- **FR-SDK-006**: `BeaconPhpWarningHandler` redacts the message in its fingerprint.

## Out of scope / follow-ups

- Delegating `BeaconClient` to beacon-php's `Client` (0.1 hard-wires its own builder and older redaction rules; needs beacon-php with the 1.19.2 redactor or an injectable builder).
- Moving `SensitiveValueRedactor` / `FrameVarsSerializer` to beacon-php facades (same blocker).

## Success Criteria *(mandatory)*

- **SC-SDK-001**: `tests/Contract/EnvelopeGoldenBytesTest.php` and `EnvelopeLiveParityTest.php` pass; the existing suite passes with the intended changes only; line coverage stays at 100 %.
