# Feature Specification: Messenger observability

**Feature Branch**: `004-messenger-observability`

**Created**: 2026-10-11

**Status**: Implemented (released in **1.11.0**, 2026-10-11)

**Input**: Symfony Beacon roadmap Phase 7 (7.2 retry visibility, 7.3 message transactions, 7.5 queue snapshot, 7.6 worker lifecycle). Final Messenger failures are already reported (Phase 3.1); operators also need to see slow handlers, retries, queue backlog and leaking workers.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Slow handlers and N+1 in workers (Priority: P1)

As a developer, I enable `messenger.transactions` and see one performance transaction per handled message in Beacon → Performance, with SQL / HTTP spans and N+1 detection.

**Independent Test**: Dispatch `WorkerMessageReceivedEvent` then `WorkerMessageHandledEvent`; exactly one `captureTransaction()` named after the message class with `extra.messenger.op = queue.process` and `status = ok`.

**Acceptance Scenarios**:

1. **Given** transactions enabled, **When** a message is handled, **Then** a transaction with status `ok` and the handler name is sent.
2. **Given** a failure, **When** Messenger will retry / will not retry, **Then** status is `retry` / `failed`, and the failure event (with breadcrumbs) is sent **before** the transaction.
3. **Given** spans buffered while the worker was idle, **When** the next message is received, **Then** those spans are dropped.

### User Story 2 - Retries and recoveries (Priority: P1)

As an operator, I enable `messenger.report_retries` / `report_recovered` and see flapping handlers before they reach the failure transport.

**Acceptance Scenarios**:

1. **Given** `report_retries`, **When** a handler fails and Messenger will retry, **Then** a `warning` event with `retry_state = will_retry`, `attempt`, root exception type/value and fingerprint `[beacon.messenger.retry, message class, handler, root type]` is sent.
2. **Given** `report_recovered`, **When** a message with `retry_count ≥ 1` is handled, **Then** an `info` event with `retry_state = recovered` is sent; first-attempt successes send nothing.
3. **Given** `retry_sample_rate < 1`, **Then** events are sampled.
4. Final failures carry `retry_state` `exhausted` (retry_count ≥ 1) or `failed`, plus `handler_classes` when several handlers failed.

### User Story 3 - Queue backlog (Priority: P2)

As an operator, I schedule `nowo:beacon:messenger-report` and Beacon shows the message count per transport and alerts when the failure transport fills up.

**Acceptance Scenarios**:

1. **Given** Messenger transports, **When** the command runs, **Then** one `queue_snapshot` item is sent with `{name, count|null, failure}` per transport (service-id aliases skipped; non-countable or broken transports → `null`).
2. **Given** `--dry-run`, **Then** nothing is sent. **Given** `--failure-transport`, **Then** it overrides `messenger.failure_transports`.
3. **Given** an empty DSN, **Then** the command explains that nothing was sent and exits 0.

### User Story 4 - Leaking workers (Priority: P3)

As an operator, I set `messenger.worker_memory_warning_ratio: 0.8` and get one warning per worker process before an out-of-memory fatal.

## Functional Requirements

- **FR-MQ-001**: `messenger.transactions` (+ `transaction_sample_rate`) sends one transaction per handled message (`extra.messenger.op = queue.process`, status `ok` / `failed` / `retry`, handler, transport, retry count) with the Doctrine / HttpClient spans recorded while handling; listener runs at priority -1024 so failure events are sent first.
- **FR-MQ-002**: `messenger.report_retries` / `report_recovered` (+ `retry_sample_rate`) send `will_retry` warnings and `recovered` info events; final failures carry `retry_state` (`exhausted` / `failed`) and `handler_classes`; `HandlerFailedException` culprit is taken from the root cause.
- **FR-MQ-003**: Message bodies are never read or sent; only class names, handler names, transport names, counters and timings (`MessengerContext`).
- **FR-MQ-004**: `messenger.worker_memory_warning_ratio` warns once per worker process (the memory listener keeps per-process state on purpose; other listeners hold no request state — Igor: 0 KO).
- **FR-MQ-005**: `nowo:beacon:messenger-report` sends a new Envelope item `queue_snapshot` (golden fixture `queue_snapshot.ndjson`; `before_send` not applied) via `QueueSnapshotCapableInterface`, which keeps `BeaconClientInterface` unchanged (no BC break for custom clients).
- **FR-MQ-006**: `FlushPendingTransportsListener` drains pending (async) envelopes on `WorkerMessageHandledEvent`, `WorkerMessageFailedEvent` and `WorkerStoppedEvent` at priority -2048 (after the Beacon listeners), so `transport.mode: async` delivers worker events immediately instead of when the worker stops.
- **FR-MQ-007**: All new behaviour is opt-in under `nowo_beacon.messenger.*` (`failure_transports` defaults to `['failed']`); defaults keep 1.10 behaviour apart from FR-MQ-002 extras and the FR-MQ-006 fix.
- **FR-MQ-008**: 100% line coverage on new code.
