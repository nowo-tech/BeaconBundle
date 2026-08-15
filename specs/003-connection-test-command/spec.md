# Feature Specification: Connection test command

**Feature Branch**: `003-connection-test-command`

**Created**: 2026-08-16

**Status**: Active

**Input**: User description: "BeaconBundle should define a console command that runs a connection test against Symfony Beacon."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Probe ingest with configured DSN (Priority: P1)

As a developer integrating BeaconBundle, I run a single console command after setting `BEACON_DSN` so I can confirm the app can reach Symfony Beacon ingest (network, TLS, auth, project id) without throwing a fake exception in a controller.

**Why this priority**: Replaces ad-hoc `/boom` or manual `captureMessage` during setup; matches the Getting Started verification step.

**Independent Test**: With a MockHttpClient (or a live Beacon), `php bin/console nowo:beacon:test` exits 0 on HTTP 2xx and prints origin, project id, and event id without exposing the secret.

**Acceptance Scenarios**:

1. **Given** a valid non-empty DSN and Beacon returning 2xx, **When** I run `nowo:beacon:test`, **Then** the command exits successfully, POSTs a sync Envelope to `/api/{projectId}/envelope/`, and prints a success summary including a local event id.
2. **Given** Beacon rejects auth (401/403), **When** I run the command, **Then** it exits with failure and names authentication as the likely cause (without printing the secret).
3. **Given** the host is unreachable, **When** I run the command, **Then** it exits with failure and reports a transport/network error.

---

### User Story 2 - Validate DSN without sending (Priority: P2)

As a developer, I want to check that the configured DSN parses and see the target origin/project before posting any event.

**Why this priority**: Safe dry-run for CI and misconfigured env debugging.

**Independent Test**: `nowo:beacon:test --check-only` exits 0 for a valid DSN without HTTP calls; exits non-zero for empty/invalid DSN.

**Acceptance Scenarios**:

1. **Given** a valid DSN, **When** I pass `--check-only`, **Then** the command prints sanitized connection details and does not perform an HTTP POST.
2. **Given** an empty DSN, **When** I run the command (with or without `--check-only`), **Then** it fails with a clear “DSN empty / reporting disabled” message.

---

### Edge Cases

- `nowo_beacon.enabled: false` but DSN present: command may still probe and notes that automatic reporting is off.
- `transport.mode` is `async` or `messenger`: the test always uses **sync** HTTP so the exit code reflects the real ingest ACK.
- Secret key never appears in console output (public key may be truncated).
- Command is registered even when the client is a `NullBeaconClient` (empty DSN / disabled) so operators get a useful failure message.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-CMD-001**: Bundle MUST register console command `nowo:beacon:test` when `symfony/console` is available.
- **FR-CMD-002**: Default execution MUST build a minimal info-level test event Envelope and POST it synchronously to the configured DSN envelope URL.
- **FR-CMD-003**: Command MUST support `--check-only` to parse/display DSN details without sending.
- **FR-CMD-004**: Command MUST support optional `--message=` to override the test event message body.
- **FR-CMD-005**: Exit code MUST be `0` on success and non-zero on empty DSN, invalid DSN, transport failure, or non-2xx ingest.
- **FR-CMD-006**: Output MUST NOT print the DSN secret or `X-Beacon-Auth` header value.
- **FR-CMD-007**: Connection probe MUST ignore configured `transport.mode` and always use sync HTTP for the test send.

### Key Entities

- **BeaconConnectionTester**: Runtime helper that parses DSN, optionally sends a sync test envelope, returns a structured result.
- **ConnectionTestResult**: Success flag, human message, optional HTTP status, sanitized target metadata, optional event id.
- **TestConnectionCommand**: Symfony console entrypoint wrapping the tester.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A developer can verify Beacon connectivity with one command after setting `BEACON_DSN`.
- **SC-002**: Unit tests cover success, auth failure, transport failure, empty DSN, and `--check-only` with MockHttpClient (no live server required).
- **SC-003**: Getting Started / Usage docs mention `nowo:beacon:test` as the preferred verification step.
- **SC-004**: Production `src/` inventory lists the new command/tester files.
