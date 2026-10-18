# Feature Specification: Scheduler check-ins

**Feature Branch**: `007-scheduler-check-ins`

**Created**: 2026-10-18

**Status**: Implemented (released in **1.15.0**, 2026-10-18; `description` added in **1.16.0**)

**Input**: Client half of Symfony Beacon spec `153-scheduler-check-ins` (roadmap Phase 7.7, approved 2026-10-17, FR-B01 – FR-B08). Beacon only learned about a Symfony Scheduler task when its handler threw; a task that never runs (worker down, lock stuck) or runs for hours was invisible.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Beacon knows the schedule (Priority: P1)

As an operator, I enable `scheduler.check_ins` and restart `messenger:consume scheduler_default`; Beacon receives the list of recurring tasks with their triggers and next runs, without any per-task configuration.

**Acceptance Scenarios**:

1. **Given** check-ins enabled and a worker whose transports include `scheduler_<name>`, **When** it starts, **Then** one `schedule_catalog` Envelope with those schedules (only) is sent; it is re-sent every `catalog_refresh_hours` while the worker runs.
2. **Given** a `RunCommandMessage('app:x --token=secret')`, **Then** the entry has `command: app:x` and the arguments never appear.
3. **Given** a callback / custom message provider, **Then** it is never invoked and `message_class` is `null`.
4. **Given** a periodic trigger not anchored yet, **Then** the catalog probes a clone (the real schedule's anchor is not moved).

### User Story 2 - One check-in per run (Priority: P1)

As an operator, Beacon sees each run start and finish so it can alert on missed, too long and failed runs.

**Acceptance Scenarios**:

1. **Given** a scheduled message, **When** it is received / handled / fails, **Then** `in_progress` / `ok` / `error` check-ins are sent, each in its own Envelope, with the same deterministic `run_id` on every worker.
2. **Given** a failure, **Then** the Messenger failure event carries `extra.scheduler.run_id` and the `error` check-in carries its `failure_event_id`.
3. **Given** a run cancelled before handling (`PreRunEvent::shouldCancel()`), **Then** no check-in is sent.
4. **Given** `transport.mode: async`, **Then** the catalog and `in_progress` are flushed immediately.
5. **Given** Beacon is down, **Then** the worker keeps running (warning logged).

### User Story 3 - Thresholds from code (Priority: P2)

As a developer, I put `#[BeaconCheckIn(maxRuntime: 600)]` on a message class (or configure `max_runtime` by FQCN / command name) and Beacon receives `max_runtime_seconds`; `#[BeaconCheckIn(enabled: false)]` / `ignore` removes a task from check-ins.

## Functional Requirements

- **FR-SCI-001** (FR-B01): `nowo_beacon.scheduler.check_ins.enabled` (default `false`), `max_runtime` (map FQCN / command name → 1 … 604 800 s, keys not normalized), `ignore` (list), `catalog_refresh_hours` (1 … 168, default 24). Enabling it without `symfony/scheduler` + `symfony/messenger` fails container compilation.
- **FR-SCI-002** (FR-B02): `Nowo\BeaconBundle\Attribute\BeaconCheckIn(maxRuntime: ?int = null, enabled: bool = true)` on the message class wins over the config; out-of-range values are ignored. Resolution order: attribute, command name, exact FQCN, parent classes / interfaces (config order). `RedispatchMessage` is unwrapped.
- **FR-SCI-003** (FR-B03): `ScheduleProviderLocatorPass` (before optimization, priority -16, after Symfony's `AddScheduleMessengerPass`) collects `scheduler.schedule_provider` services into a locator keyed by schedule name for `ScheduleCatalogBuilder` (limits 20 schedules × 200 entries; a schedule whose provider throws is skipped).
- **FR-SCI-004** (FR-B04): `BeaconSchedulerCheckInListener`: catalog on `WorkerStartedEvent` (+ refresh on `WorkerRunningEvent`); check-ins on `WorkerMessageReceivedEvent` / `Handled` / `Failed` at priority -512 (after the failure listener at 0, before the transaction listener at -1024 and the async flush at -2048). Message bodies are never read.
- **FR-SCI-005** (FR-B05): `nowo:beacon:scheduler-catalog [--schedule=…] [--dry-run]`, registered whenever `symfony/scheduler` is installed (also with `NullBeaconClient`); sends nothing while check-ins are disabled.
- **FR-SCI-006** (FR-B06): `CheckInCapableInterface` (`captureScheduleCatalog()`, `captureCheckIn()`) on `BeaconClient` / `NullBeaconClient`; `before_send` not applied; never sampled.
- **FR-SCI-007** (FR-B07): Worker safety — `SchedulerRunRegistry` (`kernel.reset`, run id → started at / failure event id, max 100 each, cleared on worker start / stop); listener keeps only worker-lifetime state (schedules, last catalog time). Check-ins go through the configured `transport.mode`.
- **FR-SCI-009** (1.16.0, amendment requested 2026-10-18): every catalog entry carries `description` (string|null, plain text, one line collapsed, ≤ 500 chars, always present). Resolution: `#[BeaconCheckIn(description: …)]`; `scheduler.check_ins.descriptions` (command name, exact FQCN, parent classes / interfaces); `RunCommandMessage` → console command description through the lazy `console.command_loader` (injected by `TaskDescriptionCommandLoaderPass` in the *before removing* phase, after FrameworkBundle's `AddConsoleCommandPass`; a `LazyCommand` is not instantiated) / `ServiceCallMessage` → target method docblock summary (service id = class name); message class docblock summary (first sentence; null without doc comments). Never arguments or payload values. Beacon < 1.70 ignores the key (its `ScheduleCatalogWriter` reads known keys only).
- **FR-SCI-008** (FR-B08): Golden fixtures `schedule_catalog.ndjson`, `check_in_ok.ndjson`, `check_in_error.ndjson`; 100 % line coverage.

## Payload contract

See [Usage → Scheduler check-ins](../../docs/USAGE.md#scheduler-check-ins) and the golden fixtures in `tests/Contract/fixtures/envelope/`.

- `run_id` = `substr(sha256(schedule . "\n" . recurring_id . "\n" . triggered_at_ATOM), 0, 32)` with `triggered_at` normalized to **UTC** (so workers with different default time zones agree).
- `trigger` always carries the five keys `kind`, `expression`, `timezone`, `interval_seconds`, `description` (`null` when not applicable); `command` is present only for `RunCommandMessage`.
- `in_progress` check-ins omit `finished_at`, `duration_ms` and `failure_event_id`; `ok` carries `failure_event_id: null`.

## Decisions (where Beacon spec 153 left room)

| Topic | Decision | Why |
|---|---|---|
| Run events | Messenger worker events (`WorkerMessageReceived/Handled/FailedEvent`), not Scheduler `PreRunEvent` / `PostRunEvent` / `FailureEvent` | The Scheduler events are re-dispatched from these same worker events by `DispatchSchedulerEventListener`; worker events give the exact priority ordering with the failure / transaction / flush listeners and see cancellation through `shouldHandle()`. |
| `next_run_at` on check-ins | `MessageContext::$nextTriggerAt` | The generator's own next occurrence for that task; no trigger call from the worker. |
| Cron `timezone` when the trigger has none | PHP's default time zone | That is the zone the cron trigger evaluates in (the scheduler clock's zone); Beacon cannot know the server default otherwise. |
| Periodic `interval_seconds` and unanchored `next_run_at` | Computed on a clone of the `PeriodicalTrigger` | `PeriodicalTrigger::getNextRunDate()` anchors `from` on first call; calling it on the live trigger before the worker would shift the real schedule. |
| Cron time zone | Read-only reflection of `CronExpressionTrigger::$timezone` | No public getter in Symfony 7.x / 8.x; failures fall back to `null`. |
| Duplicates in the catalog | Not filtered client side | `Schedule::add()` already rejects duplicated recurring ids. |
| Async mode | Flush right after the catalog and `in_progress` | Otherwise the HTTP request would only complete after the run, defeating "too long while running" detection. |

## Out of scope

Generic cron / systemd check-ins, a ping URL, Beacon-side detection and UI (Beacon spec 153 FR-001 – FR-023).
