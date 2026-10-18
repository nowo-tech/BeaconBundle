# Feature Specification: Retry or discard a failed Messenger message from the Beacon UI (client)

**Feature Branch**: `008-messenger-remote-retry`

**Created**: 2026-10-18

**Status**: Implemented (released in **1.16.0**, 2026-10-18)

**Input**: Client half of Symfony Beacon spec `154-messenger-retry-from-ui` (roadmap Phase 7.8, approved 2026-10-17 with review answers: HMAC-SHA256 signing with an HKDF subkey of the worker key — Ed25519 out of scope; *at most once* delivery; one message at a time), FR-B01 – FR-B09. Before, an operator who saw a final Messenger failure in Beacon had to SSH into the host and run `messenger:failed:retry <id>` — and Beacon did not even know the id of the message in the failure transport.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Beacon knows the failure-transport id (Priority: P1)

As a project admin, the final-failure event shows *Failure transport `failed` · message #8812* so I (and Beacon) know which stored message to act on.

**Acceptance Scenarios**:

1. **Given** a Doctrine or Redis failure transport, **When** a message fails for good and is stored there, **Then** `extra.messenger` carries `failure_transport` and `failure_message_id` (string ≤ 255).
2. **Given** an AMQP / SQS / in-memory failure transport (cannot find by id), **Then** neither key is sent.
3. **Given** a failure that will be retried, **Then** nothing is stored nor sent (unchanged).

### User Story 2 - Retry / discard from Beacon (Priority: P1)

As a project admin, I click Retry (or Discard) in Beacon; within ~30 s a worker re-queues the message to its original transport (or removes it) and Beacon shows the result.

**Acceptance Scenarios**:

1. **Given** `remote_retry.enabled` and a valid worker key, **When** a worker is idle or between messages, **Then** it claims at most one command, verifies it, executes it and posts the result.
2. **Given** a tampered signature, another project, an expired command, a disallowed action / transport or a non-listable transport, **Then** nothing is executed and the result is `failed` with the matching reason code.
3. **Given** the same command id again (replay), **Then** it is not executed again; the stored result is re-posted.
4. **Given** the message was already retried elsewhere, **Then** `failed / not_found`.
5. **Given** the re-send fails, **Then** `failed / requeue_failed` and nothing is removed; **given** the removal fails after the re-send, **Then** `failed / remove_failed_after_requeue`.

### User Story 3 - Safe polling (Priority: P1)

As an operator, polling never slows or breaks my workers and stops by itself when misconfigured.

**Acceptance Scenarios**:

1. **Given** Beacon down, **Then** polls back off 30 → 60 → … 600 s with one warning; **given** `429` / `503`, **Then** `Retry-After` is honoured; **given** `401` / `403` / `404`, **Then** polling pauses for one hour with one warning.
2. **Given** an empty / invalid worker key or DSN, **Then** nothing is polled and one warning is logged per worker.
3. **Given** no permanent worker, **Then** `nowo:beacon:messenger-commands --once` from cron does the same.

## Functional Requirements

- **FR-MRR-001** (FR-B01): `RecordingFailureSenderPass` (before optimization) replaces the sender locator of `messenger.failure.send_failed_message_to_failure_transport_listener` with a new locator whose entries are `RecordingFailureSender` decorators (one per failure transport, service id `nowo_beacon.recording_failure_sender.<name>`); the shared original locator is never mutated. The decorator records the returned `TransportMessageIdStamp` in `FailureTransportRecorder` only when the inner transport implements `ListableReceiverInterface`; the recorder is cleared on `WorkerMessageReceivedEvent` (priority 1024), when read and by `kernel.reset`. `BeaconMessengerFailedListener` runs at priority **-150** and adds `failure_transport` / `failure_message_id` when the recorded original receiver equals the event receiver. Missing service / argument → no-op (keys not sent). Always on (ids only).
- **FR-MRR-002** (FR-B02): `nowo_beacon.messenger.remote_retry`: `enabled` (false), `worker_key` (`%env(default::BEACON_WORKER_KEY)%`; required when enabled; a literal value must match `bwk_` + ≥ 43 base64url chars at compile time, env values are checked at runtime), `allowed_actions` (`[retry, discard]`, entries validated), `failure_transports` (`[]` = `messenger.failure_transports`), `poll_interval` (30, 10 … 600), `poll_in_workers` (true). Enabling it without `symfony/messenger` fails compilation; services are registered only when reporting is on (`enabled` + non-empty DSN).
- **FR-MRR-003** (FR-B03, FR-B08): `RemoteCommandPoller` on `WorkerRunningEvent` (reset on `WorkerStartedEvent` / `WorkerStoppedEvent`, monotonic clock). `RemoteCommandClient` sends direct HttpClient requests (never the Envelope transport or a Messenger message), `timeout` = `max_duration` = 3 s, `verify_peer` honoured, bundle `User-Agent`. Next poll after success = `max(poll_interval, poll_after)` (`poll_after` clamped 0 … 3600); network / 5xx / unreadable body → `min(600, max(poll_interval, 30 · 2^n))`; `429` / `503` → `Retry-After` (seconds or HTTP date, 1 … 3600) else backoff; claim `401` / `403` / `404`, result `401` / `403` → pause one hour with one warning. Never throws into the worker.
- **FR-MRR-004** (FR-B04): `nowo:beacon:messenger-commands [--once] [--time-limit=SECONDS]` (registered whenever Messenger is installed; explains when the feature is off). `--once` exits 1 when disabled / refused, 0 otherwise.
- **FR-MRR-005** (FR-B05): verification order — signature (`hash_equals`, hex compared lowercase), project (UUID DSN: case-insensitive equality; legacy numeric DSN: skipped), `expires_at` > now − 60 s, action in `allowed_actions`, transport in `failure_transports`, receiver in `messenger.receiver_locator` implements `ListableReceiverInterface`, executed-ids cache (`cache.app`, key `nowo_beacon.remote_command.<sha256(id)>`, TTL 24 h; bounded in-process fallback when the pool is missing / failing).
- **FR-MRR-006** (FR-B06): `FailedMessageCommandExecutor` — retry: `find()` (exception → `internal_error`), null or no `SentToFailureTransportStamp` → `not_found`, `MessageDecodingFailedStamp` or unknown / non-sender original transport → `requeue_failed`; send to the original transport's sender without `SentToFailureTransportStamp`, `RedeliveryStamp`, `TransportMessageIdStamp`, `DelayStamp` and non-sendable stamps (keeps `BeaconTraceStamp`, `ErrorDetailsStamp`); send failure → `requeue_failed` (nothing removed); `reject()` failure → `remove_failed_after_requeue`; else `done / requeued`. Discard: `find()` → `reject()` → `done / removed` (`remove_failed` on error). Results: closed reason list + exception class (≤ 255) only.
- **FR-MRR-007** (FR-B07): worker safety — poller state (`nextPollAt`, backoff step, pause, one-warning flag, ≤ 20 unsent results with ≤ 10 attempts) is per process and reset on worker start / stop; deliberately not `kernel.reset` (Messenger resets services after every message). Credentials resolved once per process. Igor justifications on every write. Never runs in HTTP requests.
- **FR-MRR-008** (FR-B09): contract fixtures `tests/Contract/fixtures/messenger_commands/` (signing vectors, claim request / responses, result bodies) shared with Beacon; real Doctrine (SQLite) transport tests for failure ids, retry and discard; 100 % line coverage.

## Wire contract

- Auth: `Authorization: Bearer bwk_…` (the plain key) on both calls; `Content-Type: application/json`, `Accept: application/json`.
- `POST {origin}/api/{dsn project id}/messenger/commands/claim` body: `{"server_name","environment","bundle_version","failure_transports":[{"name","listable"}],"max":1}`; `200` body: `{"commands":[{"id","project","action","failure_transport","message_id","issued_at","expires_at","claim_token","signature"}],"poll_after"}`.
- `POST {origin}/api/{dsn project id}/messenger/commands/{id}/result` body: `{"claim_token","status":"done|failed","outcome":"requeued|removed|null","reason_code","exception_class","executed_at":"Y-m-d\TH:i:s\Z (UTC)","server_name"}`.
- HKDF-SHA256 (PHP `hash_hkdf`): IKM = the worker key string exactly as configured (trimmed, **including** `bwk_`), salt empty, length 32; info `beacon-worker-auth-v1` (auth) and `beacon-command-sign-v1` (sign).
- Signature = `v1=` + lowercase hex HMAC-SHA256(sign, `v1\n{id}\n{project}\n{action}\n{failure_transport}\n{message_id}\n{issued_at}\n{expires_at}`), values verbatim as received, no trailing newline.

## Decisions (where Beacon spec 154 left room or the real Symfony code differs)

| Topic | Decision | Why |
|---|---|---|
| Symfony behaviour (verified on `symfony/messenger` 7.4.21) | `SendFailedMessageToFailureTransportListener::onMessageFailed()` (priority -100) calls `$failureSender->send($envelope)` and drops the return value; FrameworkExtension builds its first argument with `ServiceLocatorTagPass::register()` keyed by the **original** transport name; Doctrine / Redis senders return a `TransportMessageIdStamp`; `ListableReceiverInterface::find()` is what `messenger:failed:*` use | Matches the spec; the pass rebuilds that locator, never the shared one (same-map locators are shared between consumers). |
| Failure send throws at -100 | The Beacon failure listener (now -150) does not run for that message | The exception stops `messenger:consume` (reported by the console listener) and the message is redelivered; reporting before the send would lose the id for every message. |
| HKDF input | Full key string incl. `bwk_`, empty salt, 32-byte output | The spec writes `HKDF-SHA256(key, info …)` with key = `bwk_…`; using the string avoids a base64url-decoding ambiguity. Vectors in `signing.json`. |
| Project check with a legacy numeric DSN | Skipped (the per-project signing key binds the command) | The client cannot know the project UUID from a numeric DSN. |
| Project mismatch reason | `signature_invalid` | The closed reason list has no project code; the command is not authentic for this client. |
| Replay (id already executed) | Not executed; the stored result is re-posted | No reason code fits; re-posting the same result is an idempotent no-op on Beacon. A command started by a process that died before storing the outcome → `internal_error`, not executed (at most once). |
| "RedeliveryStamp reset" | All `RedeliveryStamp`s removed (retry count back to 0); the failure transport's `TransportMessageIdStamp` / `DelayStamp` and non-sendable stamps removed as well | Same effect as a fresh dispatch; `ErrorDetailsStamp` history kept like `messenger:failed:retry`. |
| Undecodable message / missing original transport | `requeue_failed` | Re-sending an incomplete class or to nowhere is unsafe; nothing is removed. |
| Result call `404` / `409` / other 4xx | Result dropped with a warning (no pause) | `404` there means unknown command / claim token after a valid key; pausing all polling for one bad result would be harmful. `401` / `403` pause like the claim. |
| Unsent results | Kept in memory (≤ 20, ≤ 10 attempts) and re-posted before the next claim and on worker stop | Beacon accepts a late result over *expired (no_result)*; the worker's report is the truth. |
| `ResetInterface` on the poller | Not implemented / not `kernel.reset`; reset on `WorkerStartedEvent` / `WorkerStoppedEvent` (FR-B07) | Messenger's `ResetServicesListener` resets `kernel.reset` services after every message, which would trigger a poll after every message. The recorder (per-message state) is `kernel.reset`. |
| More than one command in a claim | Only the first `MAX_COMMANDS_PER_CLAIM` (1) is executed | v1 is one message at a time; extra commands expire as *outcome unknown* on Beacon. |
| Remote retry without reporting | Not registered (command explains) | The DSN gives the Beacon origin and project. |

## Out of scope

Ed25519 signatures with a pinned Beacon public key, bulk commands, payload edits, other commands, non-listable failure transports, the Beacon side (spec 154 FR-001 – FR-009).
