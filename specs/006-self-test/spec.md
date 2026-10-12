# Feature Specification: Self-test (real failures)

**Feature Branch**: `006-self-test`

**Created**: 2026-10-12

**Status**: Implemented (released in **1.12.0**, 2026-10-12)

**Input**: Symfony Beacon dogfoods an "Administration → Beacon self-test" page (`App\Ops\Service\BeaconSelfTest`, controller, fail message + handler). Every BeaconBundle host needs the same end-to-end proof that its listeners, transport and DSN work, so the logic moves into the bundle: console command, public service, optional JSON endpoint.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Prove the wiring from the CLI (Priority: P1)

As a developer, I run `php bin/console nowo:beacon:self-test <kind>` after installing or upgrading the bundle and see real issues in Beacon, tagged with the run token.

**Independent Test**: Console `Application` with an `EventDispatcher` carrying `BeaconConsoleErrorListener`; running `uncaught-exception` exits 1 and the listener captures `BeaconSelfTestException` with `extra.console.command = nowo:beacon:self-test`.

**Acceptance Scenarios**:

1. **Given** no argument, **Then** the kinds are listed and the command exits `2` (invalid). **Given** an unknown kind, **Then** the same list plus an error.
2. **Given** reporting disabled / empty DSN / invalid DSN / disabled client, **Then** the command fails before doing anything with a message naming the problem.
3. **Given** `uncaught-exception` or `php-error`, **Then** the command throws (exit 1); `ConsoleEvents::ERROR` reaches the bundle console listener and `console.terminate` flushes async transports. A warning is printed when `register_console_listener` is false.
4. **Given** `php-warning`, `captured-exception`, `captured-message`, **Then** the events are sent and the command prints kind / event id / delivery, then flushes the client.
5. **Given** `all`, **Then** every returning kind runs; `messenger-failure` is skipped without a bus; a kind whose prerequisite is missing is reported as unavailable and the command fails.

### User Story 2 - Messenger failure path (Priority: P2)

As an operator, I route `BeaconSelfTestFailMessage` to an async transport and see the worker failure reported by the Messenger failure listener.

**Acceptance Scenarios**:

1. **Given** the message is routed to a transport, **Then** the result is `delivery: queued` and the command tells me to run a worker.
2. **Given** the message is handled synchronously, **Then** the `HandlerFailedException` (wrapping `UnrecoverableMessageHandlingException` → `BeaconSelfTestException`) is captured inline (`delivery: inline`).
3. **Given** no bus, **Then** the kind is unavailable with a clear message.

### User Story 3 - Admin page / HTTP endpoint (Priority: P3)

As an administrator, I trigger the same kinds from a protected JSON endpoint or from my own admin page that calls the public service.

**Acceptance Scenarios**:

1. **Given** default configuration, **Then** no controller or route exists (`self_test.http.enabled: false`); importing the `nowo_beacon` route loader is harmless.
2. **Given** the endpoint enabled, **When** a user lacking every configured role calls it, **Then** `403`; with roles configured and no SecurityBundle, every call is `403` (fail closed).
3. **Given** a POST without a valid CSRF token (`_token` or `X-CSRF-Token`, id `nowo_beacon_self_test`), **Then** `403`; without symfony/security-csrf every call is `500` with an explanation.
4. **Given** a returning kind, **Then** JSON `{kind, run, event_id, delivery, tag}`; **given** an uncaught kind, **Then** the exception propagates to `kernel.exception`.

## Functional Requirements

- **FR-ST-001**: Kinds `uncaught-exception`, `php-error`, `php-warning`, `captured-exception`, `captured-message`, `messenger-failure`; failures are produced for real (throw, engine `DivisionByZeroError`, `trigger_error`, `captureException` / `captureMessage`, Messenger dispatch) — never hand-built Envelopes.
- **FR-ST-002**: Every run sets scope tags `beacon_selftest=<run token>` and `beacon_selftest_kind=<kind>` (scope is reset per request / `kernel.reset`).
- **FR-ST-003**: `BeaconSelfTest::assertReady()` validates enabled flag, non-empty DSN (`BeaconDsnParser`), and live client; `run()` calls it and checks kind prerequisites (`register_php_warning_handler`, Messenger bus).
- **FR-ST-004**: `BeaconSelfTestException` extends `\Exception` (not RuntimeException) so common `ignore_exceptions` entries do not hide it.
- **FR-ST-005**: Command, service and route loader are always registered (also with `NullBeaconClient`); the fail handler only with `symfony/messenger`; the controller only when `self_test.http.enabled`.
- **FR-ST-006**: Config `self_test.http.{enabled=false, path=/_beacon/self-test (must start with /), roles=[ROLE_ADMIN]}`; routes `nowo_beacon_self_test` (GET) and `nowo_beacon_self_test_trigger` (POST, `kind` requirement = known kinds).
- **FR-ST-007**: JSON-only endpoint (no Twig, no translations); hosts build UI on the service.
- **FR-ST-008**: Worker-safe: services are stateless (`readonly`); igor clean.

## Success Criteria

- 100% PHP line coverage, PHPStan level 8, igor without KO.
- Symfony Beacon can delete its own self-test service, exception, message, handler and their tests, keeping only its admin UI calling `BeaconSelfTest`.
