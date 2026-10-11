# Feature Specification: Deploy markers

**Feature Branch**: `005-deploy-markers`

**Created**: 2026-10-11

**Status**: Implemented (released in **1.11.0**, 2026-10-11)

**Input**: Symfony Beacon roadmap Phase 7 product candidates — deploy markers + suspect release (Beacon spec `125-triage-automation-deploys`).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Mark a deploy from CI (Priority: P1)

As a developer, I run `php bin/console nowo:beacon:deploy --commit=$SHA` at the end of my deploy pipeline so Beacon knows when each release went live.

**Acceptance Scenarios**:

1. **Given** a configured DSN and `nowo_beacon.release`, **When** I run the command without options, **Then** one `deploy` item (via `DeployCapableInterface`) with that release and the configured environment is sent and the event id is printed.
2. **Given** `--release/--environment/--commit/--url/--name`, **Then** they override the defaults; blank values are omitted.
3. **Given** no release anywhere, **Then** the command fails with a clear message. **Given** an empty DSN, **Then** it exits 0 and sends nothing.

## Functional Requirements

- **FR-DEP-001**: Envelope item `deploy` payload: `release` (required), `environment`, `deployed_at` (float seconds, defaults to now), optional `commit`, `url`, `name`; golden fixture `deploy.ndjson`.
- **FR-DEP-002**: `DeployCapableInterface` keeps `BeaconClientInterface` unchanged; `before_send` is not applied (no event data).
- **FR-DEP-003**: Command is always registered (also with `NullBeaconClient`).
