# Usage

For a full end-to-end setup (create a Symfony Beacon project, copy the DSN, verify issues in the UI), start with [Getting started](GETTING_STARTED.md).

## Table of contents

- [Connection test command](#connection-test-command)
- [Automatic exception reporting](#automatic-exception-reporting)
- [Manual reporting](#manual-reporting)
- [Breadcrumbs](#breadcrumbs)
- [Tags](#tags)
- [before_send scrubbing](#before_send-scrubbing)
- [Automatic Doctrine / HttpClient spans](#automatic-doctrine-httpclient-spans)
- [Performance transactions](#performance-transactions)
- [Console errors](#console-errors)
- [Messenger failures](#messenger-failures)
  - [Retries and recoveries (opt-in)](#retries-and-recoveries-opt-in)
  - [Message handling transactions (opt-in)](#message-handling-transactions-opt-in)
  - [Worker memory warning (opt-in)](#worker-memory-warning-opt-in)
  - [Queue snapshot command](#queue-snapshot-command)
- [Remote retry from Beacon](#remote-retry-from-beacon)
  - [Setup](#setup)
  - [How a command runs](#how-a-command-runs)
  - [Wire format](#wire-format)
  - [Polling, backoff and errors](#polling-backoff-and-errors)
  - [Polling without workers (cron)](#polling-without-workers-cron)
- [Scheduler check-ins](#scheduler-check-ins)
  - [What is sent](#what-is-sent)
  - [Max runtime and opt-out](#max-runtime-and-opt-out)
  - [Task descriptions](#task-descriptions)
  - [Catalog command](#catalog-command)
  - [Limitations](#limitations)
- [Deploy markers](#deploy-markers)
- [Self-test](#self-test)
  - [HTTP endpoint (optional, disabled by default)](#http-endpoint-optional-disabled-by-default)
  - [Service API (custom admin pages)](#service-api-custom-admin-pages)
- [HTTP extras](#http-extras)
- [Trace correlation](#trace-correlation)
- [Fatal PHP errors](#fatal-php-errors)
- [Automatic HTTP transactions](#automatic-http-transactions)
- [Monolog](#monolog)
- [Disabled mode](#disabled-mode)
- [Ingest endpoint](#ingest-endpoint)
- [End-to-end against `symfony-beacon`](#end-to-end-against-symfony-beacon)
- [Scenario matrix](#scenario-matrix)

## Connection test command

After setting `BEACON_DSN`, verify reachability and credentials:

```bash
php bin/console nowo:beacon:test
php bin/console nowo:beacon:test --check-only
php bin/console nowo:beacon:test --message='Hello from CI'
```

The probe always uses **sync** HTTP (ignores `transport.mode`) so the exit code matches the ingest ACK. Output includes origin, project id, truncated public key, HTTP status, and event id — never the secret.

## Automatic exception reporting

With `register_error_listener: true` and a non-empty DSN, uncaught HTTP exceptions are reported automatically through the `kernel.exception` listener.

Use `ignore_exceptions` when a class should never be reported by that automatic listener:

```yaml
nowo_beacon:
    ignore_exceptions:
        - InvalidArgumentException
```

Database exceptions (PDO, Doctrine DBAL when present, or `SQLSTATE[…]` in the message) also attach **`contexts.db`** (SQLSTATE, vendor code, scrubbed SQL). Symfony Beacon 107+ shows that as the Query panel. Opt-in `instrumentation.doctrine` breadcrumbs remain separate.

## Manual reporting

```php
use Nowo\BeaconBundle\Client\BeaconClientInterface;

final class CheckoutController
{
    public function __construct(private readonly BeaconClientInterface $beacon)
    {
    }

    public function __invoke(): void
    {
        $this->beacon->captureMessage('Checkout started', 'info', [
            'cart_id' => 12,
        ]);

        try {
            // ...
        } catch (\Throwable $exception) {
            $this->beacon->captureException($exception, ['cart_id' => 12], [
                'checkout',
                'critical',
            ]);

            throw $exception;
        }
    }
}
```

Manual APIs return the locally generated event id or `null` when the client is disabled.

With default `send.stacktrace: true`, `captureMessage()` includes a current PHP stacktrace and `culprit` (no `Throwable` required). When source files are readable, frames may also include `abs_path` and ≈5 lines of source context. With `send.stack_vars: true`, frames also include `vars` (serialized call arguments; opt-in PII). With `send.request: true`, HTTP events also attach `request` / `contexts.request` when a request is active.

## Breadcrumbs

```php
$beacon->addBreadcrumb('Opened checkout', 'navigation');
$beacon->addBreadcrumb('Applied coupon', 'cart', 'info', ['code' => 'SAVE10']);
$beacon->captureMessage('Checkout failed', 'error'); // breadcrumbs attached, then cleared
```

## Tags

Tags live on a request-scoped scope and are merged into every subsequent event/transaction as `tags`:

```php
$beacon->setTag('tenant', 'acme');
$beacon->setTags(['tier' => 'pro', 'region' => 'eu']);
$beacon->captureMessage('Checkout failed', 'error');
// payload.tags = { tenant: acme, tier: pro, region: eu }
```

Limits (invalid entries are ignored; the host app is never crashed):

| Limit | Value |
|-------|-------|
| Max tags | 32 |
| Max key length | 32 |
| Max value length | 200 |
| Allowed values | string / int / float / bool (arrays/objects rejected) |

## before_send scrubbing

Register an invokable service and point `nowo_beacon.before_send` at its id:

```yaml
nowo_beacon:
    before_send: App\Beacon\ScrubPii
```

```php
namespace App\Beacon;

final class ScrubPii
{
    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>|null
     */
    public function __invoke(array $event): ?array
    {
        unset($event['extra']['password'], $event['request']['headers']['authorization']);

        return $event; // return null to drop the send entirely
    }
}
```

If the hook throws, the event is **dropped** (fail soft).

## Automatic Doctrine / HttpClient spans

```yaml
nowo_beacon:
    auto_http_transaction: true   # recommended so spans attach to a parent transaction
    instrumentation:
        doctrine: true            # requires doctrine/dbal
        http_client: true
```

- Doctrine: `db.sql.query` spans + `db.query` breadcrumbs (SQL literals scrubbed).
- HttpClient: `http.client` spans + `http` breadcrumbs (Beacon `/envelope/` calls skipped).
- Buffered spans drain into the next `captureTransaction()` (including auto HTTP transactions).

## Performance transactions

```php
$start = microtime(true);
// ... work ...
$end = microtime(true);
$beacon->captureTransaction('checkout', $start, $end, [
    [
        'op' => 'db.query',
        'description' => 'SELECT …',
        'span_id' => bin2hex(random_bytes(8)),
        'start_timestamp' => $start,
        'timestamp' => $end,
    ],
]);
```

Transactions appear under **Performance** in Symfony Beacon.

## Console errors

With `register_console_listener: true` (default), uncaught console command errors (including typical cron wrappers that run `bin/console …`) are reported with nested `extra.console`:

| Key | Meaning |
|-----|---------|
| `command` | Command name (or `null` if unavailable) |
| `command_class` | Command FQCN when available |
| `exit_code` | Console exit code from the error event |
| `php_sapi` | PHP SAPI (usually `cli`) |
| `verbosity` | Output verbosity level when available |
| `cwd` | Process working directory when resolvable |
| `interactive` | Whether the input was interactive |
| `arguments` | Bound argument map (values redacted for secret-like names / credential URIs) |
| `options` | Bound option map (same redaction rules) |
| `missing_arguments` | Names of required arguments that were missing/empty |

Raw `argv` is **never** sent. Argument/option **values** that look like secrets are replaced with `[Filtered]`.

## Messenger failures

With `register_messenger_listener: true` (default) and `symfony/messenger` installed, final worker failures (`WorkerMessageFailedEvent` when `willRetry()` is false) are reported with `extra.messenger`:

| Key | Meaning |
|-----|---------|
| `message_class` | Message FQCN |
| `receiver_name` | Worker receiver name |
| `retry_count` | Redelivery count from the envelope |
| `bus` | Bus name when a `BusNameStamp` is present |
| `transport_message_id` | Transport id when a `TransportMessageIdStamp` is present |
| `transport` | Transport name from `ReceivedStamp` when present |
| `handler_class` | First failing handler (`Class::method`) when recoverable from `HandlerFailedException` |
| `handler_classes` | All failing handlers, only when more than one failed |
| `first_failure_at` | ISO-8601 time of the first `RedeliveryStamp` when present |
| `retry_state` | `exhausted` (failed after at least one retry) or `failed` (first attempt) |
| `failure_transport` | 1.16.0+: failure transport that stored the message — only when it can find messages by id (Doctrine, Redis; not AMQP / SQS / in-memory) |
| `failure_message_id` | 1.16.0+: the message id **in that failure transport** (string, ≤ 255), e.g. what `messenger:failed:retry <id>` expects |

`transport_message_id` is the id in the **original** transport; `failure_message_id` is the id the message got in the failure transport. Symfony's `SendFailedMessageToFailureTransportListener` (priority -100) discards the envelope returned by the failure sender, so a compiler pass wraps that listener's failure senders in a `RecordingFailureSender` that records the returned `TransportMessageIdStamp`; the Beacon failure listener runs at priority **-150** (after the failure send, before the transaction listener at -1024) and reads it. Both keys are always sent when available (ids only, no opt-in) and enable [Remote retry from Beacon](#remote-retry-from-beacon). If the failure send itself throws (failure transport down), the worker stops with that exception (reported by the console listener) and the message is redelivered later.

The event culprit is taken from the root cause behind `HandlerFailedException` (application code), not from Messenger middleware frames.

Message bodies are never attached. When the envelope carries a `BeaconTraceStamp`, the listener restores that id into `TraceIdProvider` before capture (see [Trace correlation](#trace-correlation)).

### Scheduled tasks (Symfony Scheduler)

When `include_scheduler_context: true` (default) and the failing envelope carries a `ScheduledStamp`, the same event also includes `extra.scheduler` (`schedule_name`, `recurring_id`, `triggered_at`, `trigger`, and since 1.15.0 `run_id` — the [Scheduler check-in](#scheduler-check-ins) run id). Reporting stays on the Messenger listener only (no duplicate `FailureEvent` capture; retries are still skipped via `willRetry()`). Message bodies are never attached.

### Retries and recoveries (opt-in)

```yaml
nowo_beacon:
    messenger:
        report_retries: true     # warning event per retried failure (retry_state: will_retry)
        report_recovered: true   # info event when a retried message finally succeeds
        retry_sample_rate: 0.25  # optional sampling for busy queues
```

Retry events use their own fingerprint (`beacon.messenger.retry` + message class + handler + root exception type), so they never mix with the final-failure issue. `extra.messenger.attempt` is the attempt that just failed.

### Message handling transactions (opt-in)

```yaml
nowo_beacon:
    messenger:
        transactions: true
        transaction_sample_rate: 1.0
    instrumentation:
        doctrine: true      # SQL spans + N+1 detection inside workers
        http_client: true
```

Each handled message sends a `transaction` named after the message class with `extra.messenger.op = queue.process` and `status` (`ok`, `failed`, `retry`). Spans buffered before the message was received are dropped, so idle-time queries never leak into the next message.

### Skipped messages

The failure, retry and transaction listeners never report Beacon's own queued send (`SendBeaconEnvelopeMessage`, `transport.mode: messenger`): a report about it would queue another send, an endless loop when ingest is slow or down. Add host messages that should stay silent (health pings, heartbeat jobs) with:

```yaml
nowo_beacon:
    messenger:
        ignore_message_classes:
            - App\Message\HeartbeatMessage
```

Subclasses and interfaces match. Use `ignore_exceptions` to skip by exception instead.

### Worker memory warning (opt-in)

```yaml
nowo_beacon:
    messenger:
        worker_memory_warning_ratio: 0.8
```

Once per worker process, when `memory_get_usage(true)` reaches 80% of `memory_limit`, a `warning` event with `extra.messenger_worker` (transports, usage, limit) is sent — before a fatal out-of-memory kills the worker. Transport connection errors that stop `messenger:consume` are already reported by the console error listener.

### Queue snapshot command

```bash
php bin/console nowo:beacon:messenger-report            # send counts
php bin/console nowo:beacon:messenger-report --dry-run  # print only
php bin/console nowo:beacon:messenger-report --failure-transport=failed --failure-transport=failed_high
```

Sends a `queue_snapshot` Envelope item with the message count of every Messenger transport (`null` when the transport cannot count) and which ones are failure transports — numbers only, no message data. Symfony Beacon keeps the latest count per transport (Project → Settings → Alerts → Client Messenger queues) and opens an issue when a failure transport reaches the project threshold. Run it every few minutes, for example with cron:

```cron
*/5 * * * * php /app/bin/console nowo:beacon:messenger-report --no-interaction --quiet
```

or with Symfony Scheduler (`RecurringMessage::every('5 minutes', new RunCommandMessage('nowo:beacon:messenger-report'))`).

## Remote retry from Beacon

Opt-in (1.16.0+, Symfony Beacon spec 154): a project admin opens a final Messenger failure in Beacon and clicks **Retry** or **Discard**; one of your workers picks the command up and puts the message back on its original transport (retry) or removes it from the failure transport (discard). The direction is **pull**: workers poll Beacon over outbound HTTPS; your app exposes **no endpoint** and Beacon never connects to it.

### Setup

1. In Beacon: Project → Settings → Alerts → **Remote Messenger commands** → *Create worker key*. The key (`bwk_…`) is shown once.
2. Put it in an env var on the hosts that run workers (never in Git):

   ```dotenv
   BEACON_WORKER_KEY=bwk_…
   ```

3. Enable the feature:

   ```yaml
   # config/packages/nowo_beacon.yaml
   nowo_beacon:
       messenger:
           failure_transports: ['failed']            # also the default of remote_retry.failure_transports
           remote_retry:
               enabled: true                         # default false
               worker_key: '%env(default::BEACON_WORKER_KEY)%'   # default
               allowed_actions: [retry, discard]     # e.g. [retry] forbids remote discards
               failure_transports: []                # empty = messenger.failure_transports
               poll_interval: 30                     # seconds, 10 … 600
               poll_in_workers: true                 # poll from messenger:consume between messages
   ```

Requirements: `symfony/messenger`, a non-empty `BEACON_DSN`, a failure transport that can find messages by id (**Doctrine** or **Redis**; AMQP / SQS / Beanstalkd / in-memory cannot) and a Beacon release that supports remote commands. It is a **double opt-in**: nothing happens until Beacon has a worker key *and* the app enables `remote_retry`. Workers on older Beacons get `404` on the first poll, pause polling for an hour and log one warning — harmless.

### How a command runs

Each claimed command is verified, in this order, before anything is touched; a failed check is reported back as `failed` + reason code and nothing runs:

1. **Signature** — `v1=` + HMAC-SHA256 (constant-time compare) with a key derived from the worker key.
2. **Project** — the command's project UUID must be the DSN project (with a legacy numeric DSN id the per-project signing key is the binding).
3. **Expiry** — `expires_at` must be later than now − 60 s (clock skew); Beacon issues commands for 15 minutes.
4. **Action** in `allowed_actions`, **transport** in `failure_transports` and its receiver implements `ListableReceiverInterface`.
5. **Replay guard** — command ids executed in the last 24 h (`cache.app`, shared by the workers of a host) are never executed again; the stored result is re-posted instead.

Then:

- **retry**: `find(message_id)` on the failure transport → the envelope is sent to the **original transport's sender** (`SentToFailureTransportStamp::getOriginalReceiverName()`) without `SentToFailureTransportStamp`, `RedeliveryStamp` (retry count starts again), the failure transport's `TransportMessageIdStamp` and `DelayStamp` (Beacon's trace stamp and `ErrorDetailsStamp` are kept) → the message is rejected from the failure transport → `done / requeued`. The handler is **not** run by the poller: the regular worker of the original transport handles it, and a new failure arrives in the same Beacon issue with a new failure id.
- **discard**: `find(message_id)` → `reject()` → `done / removed`.

Reason codes (closed list): `not_found`, `transport_not_allowed`, `transport_not_listable`, `action_not_allowed`, `signature_invalid` (also a project mismatch), `expired`, `requeue_failed` (nothing removed; also for messages that can no longer be decoded), `remove_failed`, `remove_failed_after_requeue` (re-queued but still in the failure transport: duplicate risk, shown as a warning in Beacon), `internal_error`. Results carry the code and, optionally, the **exception class** — never exception messages or message bodies. Delivery is **at most once**: a command whose worker dies mid-way is never handed out again (Beacon shows *outcome unknown*).

### Wire format

Both calls go to the DSN origin with `Authorization: Bearer bwk_…`, `Content-Type: application/json`, a 3-second timeout (independent of `timeout`) and the bundle `User-Agent`; they are direct HttpClient requests (never the Envelope transport nor a Messenger message), identical under every `transport.mode`.

`POST {origin}/api/{project}/messenger/commands/claim`

```json
{"server_name":"worker-2","environment":"prod","bundle_version":"1.16.0","failure_transports":[{"name":"failed","listable":true}],"max":1}
```

`200` response:

```json
{"commands":[{"id":"0192f3c4-5b6a-7c8d-9e0f-a1b2c3d4e5f6","project":"019fea2d-507b-7890-8b33-ca488db6f696","action":"retry",
  "failure_transport":"failed","message_id":"8812","issued_at":"2026-10-17T10:02:11Z","expires_at":"2026-10-17T10:17:11Z",
  "claim_token":"…","signature":"v1=00f625d9…"}],"poll_after":30}
```

`POST {origin}/api/{project}/messenger/commands/{id}/result`

```json
{"claim_token":"…","status":"done","outcome":"requeued","reason_code":null,"exception_class":null,"executed_at":"2026-10-17T10:02:41Z","server_name":"worker-2"}
```

Keys (all HKDF-SHA256, input key = the worker key string exactly as configured including `bwk_`, empty salt, 32-byte output): `auth` with info `beacon-worker-auth-v1` (Beacon stores its SHA-256; the client sends the plain key as Bearer) and `sign` with info `beacon-command-sign-v1`. Signature: `v1=` + lowercase hex `HMAC-SHA256(sign, canonical)` with

```text
v1\n{id}\n{project}\n{action}\n{failure_transport}\n{message_id}\n{issued_at}\n{expires_at}
```

(values exactly as received, no trailing newline). Test vectors and the full bodies live in `tests/Contract/fixtures/messenger_commands/`.

### Polling, backoff and errors

- In workers (`poll_in_workers: true`) the poll runs on `WorkerRunningEvent` — between messages and while idle, **never while a message is being handled** — when the next poll is due on a monotonic clock. After a successful claim the next poll is `max(poll_interval, poll_after)` (Beacon slows idle projects down).
- Network errors, timeouts and 5xx back off exponentially: 30 → 60 → 120 → 240 → 480 → 600 s (one warning per outage).
- `429` / `503` honour `Retry-After` (seconds or HTTP date, capped at one hour).
- `401` / `403` / `404` on the claim (wrong or revoked key, unknown project, Beacon without the feature) and `401` / `403` on a result: polling **pauses for one hour** in that process with one warning.
- A result that cannot be posted (network, 5xx, 429) is retried before the next claim (up to 10 attempts, 20 pending results); `404` / `409` on a result (unknown command or claim token, different final result) drop it. A worker stop makes one last attempt.
- Nothing ever throws into the worker. Logs carry command ids, actions, transports, message ids and codes — never the key, claim tokens or message bodies.

### Polling without workers (cron)

```bash
php bin/console nowo:beacon:messenger-commands --once             # one poll (cron)
php bin/console nowo:beacon:messenger-commands --time-limit=3600  # poll on the schedule for an hour
```

Use it when no `messenger:consume` process runs permanently, or set `poll_in_workers: false` and run one dedicated poller. `--once` exits non-zero when the feature is disabled, the key / DSN is missing or Beacon refuses the key. With `remote_retry.enabled: false` the command explains how to enable it.

```cron
* * * * * php /app/bin/console nowo:beacon:messenger-commands --once --no-interaction --quiet
```

## Scheduler check-ins

Opt-in (1.15.0+): Symfony Beacon learns which Symfony Scheduler tasks exist and when each run starts and ends, so it can alert on a run that **never happened** (worker down, lock stuck), a run that **takes too long**, and **repeated failures** — not only on exceptions.

```yaml
# config/packages/nowo_beacon.yaml
nowo_beacon:
    scheduler:
        check_ins:
            enabled: true                 # default false
            max_runtime:                  # optional: seconds per message FQCN or command name
                'app:reports:nightly': 1800
                App\Message\SyncCatalog: 600
            ignore: ['app:cache:warm']    # optional: never checked in nor listed
            catalog_refresh_hours: 24     # re-send the catalog from a long-running worker
```

Requires `symfony/scheduler` (enabling it without the component fails container compilation) and a Scheduler worker (`messenger:consume scheduler_<name>`). Nothing is configured per task. **Enable it only against a Symfony Beacon that supports check-ins** (Beacon spec 153): older Beacons accept and ignore the two item types but count them toward the event quota.

### What is sent

Each item travels in **its own Envelope** (never mixed with events), through the configured `transport.mode` (`sync`, `async`, `messenger`). `before_send` is not applied (metadata only) and check-ins are never sampled.

1. **`schedule_catalog`** — when a worker starts consuming `scheduler_<name>` transports (one catalog for those schedules), and again every `catalog_refresh_hours`:

   ```json
   {"event_id":"…","type":"schedule_catalog","timestamp":1792234800.12,"platform":"php","environment":"prod","server_name":"worker-1",
    "schedules":[{"name":"default","entries":[
      {"recurring_id":"a1b2c3d4","message_class":"Symfony\\Component\\Console\\Messenger\\RunCommandMessage","command":"app:reports:nightly",
       "description":"Builds the nightly sales report and e-mails it to the finance team.",
       "trigger":{"kind":"cron","expression":"0 3 * * *","timezone":"Europe/Madrid","interval_seconds":null,"description":"0 3 * * *"},
       "next_run_at":"2026-10-18T01:00:00+00:00","max_runtime_seconds":1800},
      {"recurring_id":"d4e5f6a7","message_class":"App\\Message\\SyncCatalog","description":"Syncs the product catalog from the ERP.",
       "trigger":{"kind":"periodic","expression":null,"timezone":null,"interval_seconds":900,"description":"every 900 seconds"},
       "next_run_at":"2026-10-17T10:15:00+00:00","max_runtime_seconds":600}]}]}
   ```

   - `recurring_id` is `RecurringMessage::getId()`; `trigger.kind` is `cron` (`CronExpressionTrigger`, also behind jitter / exclude-time decorators), `periodic` (`PeriodicalTrigger`, idem) or `other`; `description` is the trigger's own text.
   - `message_class` comes only from static messages (`RecurringMessage::every()` / `cron()`, `#[AsCronTask]`, `#[AsPeriodicTask]`); callback / custom message providers are never invoked (`null`). `RedispatchMessage` is unwrapped. `command` (the command **name** only) is present for `RunCommandMessage`.
   - `description` (1.16.0+, always present, string|null): what the task does — see [Task descriptions](#task-descriptions).
   - `next_run_at` is computed by the trigger itself (UTC, ATOM). `timezone` is the cron trigger's time zone, or PHP's default time zone (what the cron trigger uses) when none is set.
   - At most 20 schedules × 200 entries; a schedule whose provider throws is skipped (never sent empty).

2. **`check_in`** — per run of a message carrying a `ScheduledStamp`:

   ```json
   {"event_id":"…","type":"check_in","timestamp":1792234801.5,"platform":"php","environment":"prod","server_name":"worker-1",
    "check_in":{"run_id":"5e1f…(32 hex)","schedule":"default","recurring_id":"a1b2c3d4","status":"ok",
     "triggered_at":"2026-10-17T01:00:00+00:00","started_at":1792234500.0,"finished_at":1792234801.4,
     "duration_ms":301400,"next_run_at":"2026-10-18T01:00:00+00:00","failure_event_id":null}}
   ```

   - `status`: `in_progress` (`WorkerMessageReceivedEvent`; skipped when the run is cancelled, e.g. by `PreRunEvent`), `ok` (`WorkerMessageHandledEvent`), `error` (`WorkerMessageFailedEvent`, retried or not). `in_progress` has no `finished_at`, `duration_ms` nor `failure_event_id`.
   - `run_id` = first 32 hex chars of `sha256(schedule . "\n" . recurring_id . "\n" . triggered_at)` with `triggered_at` normalized to UTC — identical on every worker, so Beacon dedupes runs reported by several scheduler workers.
   - `next_run_at` is the scheduler's own next occurrence (`MessageContext::$nextTriggerAt`).
   - `failure_event_id` (on `error`) is the id of the exception event the Messenger failure listener sent for the same run; that event also carries `extra.scheduler.run_id`.

Message payloads, handler arguments and command arguments / options are never read or sent — only class and command names, ids, trigger descriptions, timestamps and durations.

In `async` mode the catalog and the `in_progress` check-in are flushed immediately (a long run is visible while it is still running); `ok` / `error` are flushed after each message as for every worker event. A Beacon outage never throws into the worker (logged as a warning).

### Max runtime and opt-out

```php
use Nowo\BeaconBundle\Attribute\BeaconCheckIn;

#[BeaconCheckIn(maxRuntime: 600)]          // seconds, 1 … 604 800
final class SyncCatalog {}

#[BeaconCheckIn(enabled: false)]           // never checked in nor listed
final class CacheWarmup {}
```

Resolution: the attribute on the message class wins; then `max_runtime` / `ignore` by command name (for `RunCommandMessage`), exact FQCN, then parent classes / interfaces in config order. No max runtime = no *too long* alert. A value set in the Beacon UI wins over both.

### Task descriptions

Since 1.16.0 every catalog entry carries `description`: plain text on one line (tags stripped, whitespace collapsed), at most 500 characters, or `null`. Resolution order:

1. `#[BeaconCheckIn(description: 'Syncs the product catalog from the ERP.')]` on the message class;
2. `nowo_beacon.scheduler.check_ins.descriptions` — command name (for `RunCommandMessage`), exact FQCN, then parent classes / interfaces in config order:

   ```yaml
   nowo_beacon:
       scheduler:
           check_ins:
               descriptions:
                   'app:reports:nightly': 'Builds the nightly sales report and e-mails it to the finance team.'
                   App\Message\SyncCatalog: 'Syncs the product catalog from the ERP.'
   ```

3. `RunCommandMessage`: the console command's description (`#[AsCommand(description: …)]` / `setDescription()`), read through the console command loader — a lazy `#[AsCommand]` command is **not** instantiated; `ServiceCallMessage` (`#[AsPeriodicTask]` / `#[AsCronTask]` on a method): the summary of the target method's docblock (when the service id is the class name);
4. the summary (first sentence) of the message class docblock — only when PHP keeps doc comments (`opcache.save_comments=1`, the default); otherwise silently skipped.

Only class, command and method metadata is read — never arguments or payload values. Beacon < 1.70 ignores the key (backward compatible).

### Catalog command

```bash
php bin/console nowo:beacon:scheduler-catalog                      # send the catalog of all schedules
php bin/console nowo:beacon:scheduler-catalog --schedule=default   # one or more schedules
php bin/console nowo:beacon:scheduler-catalog --dry-run            # print only (works with check-ins disabled)
```

Useful in deploy scripts or when the scheduler worker rarely restarts. It sends nothing while `check_ins.enabled` is false or the DSN is empty.

### Limitations

- A schedule whose task uses `RedispatchMessage` (`#[AsCronTask(transports: …)]`) is checked in when the **redispatch** is handled by the scheduler worker (start and end within milliseconds), unless the inner envelope keeps the `ScheduledStamp`.
- Dynamic schedules whose entries change without a worker restart are picked up by the next catalog refresh (or the catalog command).
- Configuring `max_runtime` for `Symfony\Component\Scheduler\Messenger\ServiceCallMessage` (`#[AsPeriodicTask]` / `#[AsCronTask]` on service methods) applies to every such task; prefer the Beacon UI value per task.

## Deploy markers

Run after each deploy (CI/CD step, deploy hook):

```bash
php bin/console nowo:beacon:deploy                     # release = nowo_beacon.release, environment = nowo_beacon.environment
php bin/console nowo:beacon:deploy --release=1.4.0 --environment=prod --commit="$GIT_SHA" \
    --url="$CI_PIPELINE_URL" --name="Deploy #$CI_PIPELINE_ID"
```

Sends a `deploy` Envelope item (release metadata only; `before_send` is not applied). Symfony Beacon lists it under **Releases → Recent deploys**, adds it to the Analytics table, and marks issues first seen within 24 h after a deploy of their first release as **Suspect deploy**. With an empty DSN the command exits 0 and sends nothing.

## Self-test

`nowo:beacon:test` proves the DSN and the network path with a hand-built Envelope. `nowo:beacon:self-test` goes further: it produces **real** failures inside your application so the bundle's own listeners (console error listener, PHP warning handler, Messenger failure listener, `kernel.exception` listener for the HTTP endpoint) capture and ship them — exactly as production errors would be.

```bash
php bin/console nowo:beacon:self-test                       # list the kinds
php bin/console nowo:beacon:self-test captured-exception    # or --type=captured-exception
php bin/console nowo:beacon:self-test all                   # every returning kind
php bin/console nowo:beacon:self-test uncaught-exception    # exits 1 by design
php bin/console nowo:beacon:self-test php-error --run=ci42  # custom run token
```

| Kind | What happens | Reported by |
|------|--------------|-------------|
| `uncaught-exception` | Throws `Nowo\BeaconBundle\SelfTest\BeaconSelfTestException` | Console error listener (CLI) / `kernel.exception` listener (HTTP) |
| `php-error` | Uncaught engine `\Error` (`DivisionByZeroError`) | Same as above |
| `php-warning` | `trigger_error(E_USER_WARNING)`; execution continues | PHP warning handler (`register_php_warning_handler`) |
| `captured-exception` | `captureException()` of a handled `BeaconSelfTestException` | Client (returns the event id) |
| `captured-message` | `captureMessage()` at `warning` level | Client (returns the event id) |
| `messenger-failure` | Dispatches `Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailMessage`; its handler throws `UnrecoverableMessageHandlingException` (no retry) | Messenger failure listener (async) / captured inline (sync) |
| `all` (CLI only) | Every returning kind; `messenger-failure` is skipped without a bus | — |

Every event of a run is tagged `beacon_selftest=<run token>` and `beacon_selftest_kind=<kind>`; filter Issues with `beacon_selftest:<run>`. The command first validates the configuration (enabled flag, non-empty and parseable DSN, live client) and fails with a clear message otherwise. Async transports are flushed at the end of the command.

**Messenger:** the kind needs `symfony/messenger` with a configured bus. To exercise the worker path, route the message to an async transport and run a worker:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            Nowo\BeaconBundle\SelfTest\Message\BeaconSelfTestFailMessage: async
```

```bash
php bin/console messenger:consume async --limit=1
```

Unrouted (synchronous) messages fail during `dispatch()`; no worker event fires, so the self-test captures the real `HandlerFailedException` inline (`delivery: inline`).

### HTTP endpoint (optional, disabled by default)

```yaml
nowo_beacon:
    self_test:
        http:
            enabled: true
            path: /admin/beacon/self-test   # default /_beacon/self-test
            roles: [ROLE_ADMIN]             # any of them; [] = rely on your firewall
```

```yaml
# config/routes/nowo_beacon.yaml (safe to keep when disabled: the loader returns no routes)
nowo_beacon:
    resource: .
    type: nowo_beacon
```

| Route | Method | Response |
|-------|--------|----------|
| `nowo_beacon_self_test` → `{path}` | `GET` | JSON `status` (origin, project, listeners), `kinds.uncaught` / `kinds.returning`, `csrf_token_id`, `csrf_token` |
| `nowo_beacon_self_test_trigger` → `{path}/{kind}` | `POST` | Returning kinds: JSON `{kind, run, event_id, delivery, tag}`. Uncaught kinds throw (HTTP 500 by design; reported by the `kernel.exception` listener). |

Send the CSRF token (id `nowo_beacon_self_test`) as the `_token` form field or the `X-CSRF-Token` header. Errors are JSON `{"error": …}`: `403` (role or CSRF), `404` (unknown kind), `409` (not configured / kind unavailable), `500` (CSRF protection unavailable — requires `symfony/security-csrf` and `framework.csrf_protection`). Role checks use `security.authorization_checker`; when roles are configured but SecurityBundle is missing, every call is refused. The endpoint is JSON-only (no Twig / translations); build your own admin UI on top of it or of the service below.

### Service API (custom admin pages)

Inject `Nowo\BeaconBundle\SelfTest\BeaconSelfTest` (autowirable by class):

```php
$status = $selfTest->status();              // enabled, origin, project_id, error, listeners{…}, messenger_bus
$selfTest->assertReady();                   // throws SelfTestUnavailableException with a clear message
$result = $selfTest->run($kind);            // uncaught kinds throw — let them propagate
$result->runToken; $result->eventId; $result->delivery; // sent | warning_handler | queued | inline
BeaconSelfTest::UNCAUGHT_KINDS; BeaconSelfTest::RETURNING_KINDS; BeaconSelfTest::TAG;
```

## HTTP extras

With `register_error_listener: true` and `send.request: true` (defaults), automatic HTTP exception events include nested `extra.http` when data is available:

| Key | Meaning |
|-----|---------|
| `route` | `_route` attribute |
| `controller` | `_controller` (string or `Class::method`) |
| `status_code` | Status from `HttpExceptionInterface` when applicable |
| `query_keys` | Query parameter **names** only (no values) |
| `client` | Optional `{ip, user_agent}` when `send.client: true` (default **false**; may be PII) |

`extra.request_uri` / `extra.request_method` are still attached under the same `send.request` gate. URLs (and the Referer header) have secret-like query parameters (`token`, `password`, `api_key`, …) replaced with `[Filtered]`.

## Trace correlation

Every outbound event gets a correlation id via `TraceIdProvider`:

- `extra.trace_id` and tag `trace_id` on the payload
- HTTP: `BeaconTraceRequestListener` seeds from inbound `X-Beacon-Trace-Id` (or generates one) and stores it on the request attribute `_beacon_trace_id`
- Messenger: `BeaconTraceMiddleware` is prepended on `messenger.bus.default` — stamps `BeaconTraceStamp` on dispatch and restores the id on consume

Ids must match `^[A-Za-z0-9._-]{8,128}$`; invalid inbound values are ignored and a new id is generated.

## Fatal PHP errors

With `register_fatal_handler: true` (default), a shutdown function reports fatal PHP errors (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`) that never reach `kernel.exception` / `ConsoleEvents::ERROR`. Events include `extra.fatal` (`type`, `file`, `line`).

Disable with `register_fatal_handler: false` if you already handle fatals yourself.

## Automatic HTTP transactions

Opt in to one performance transaction per main HTTP request:

```yaml
nowo_beacon:
    auto_http_transaction: true
```

Paths in `ignore_paths` are skipped (defaults: `/_profiler`, `/_wdt`, `/build`, `/assets`, `/health`, Chrome DevTools probe). Prefer this for coarse request timing; use `captureTransaction()` for finer spans.

Since 1.17 you can sample and filter by route name:

```yaml
nowo_beacon:
    auto_http_transaction: true
    http_transaction_sample_rate: 0.2      # keep 20 % of HTTP transactions
    ignore_routes:                         # route-name prefixes (the transaction is named after the route)
        - 'ingest_'                        # e.g. an ingest endpoint that would re-report itself
        - 'api_poll_'                      # high-frequency polling noise
```

The sampling decision is taken once per request, before timing starts. Errors raised on ignored or unsampled requests are still reported.

## Monolog

```yaml
nowo_beacon:
    monolog_handler:
        enabled: true
        level: error
```

Requires `monolog/monolog`. Records at/above the level are forwarded as Beacon messages (or `captureException` when `context.exception` is a `Throwable`).

The bundle's own transport logs ("Beacon ingest transport failed.", "Beacon ingest rate limited …", …) carry the context flag `nowo_beacon_transport: true` and are never forwarded (1.17+): sending them through the same transport while Beacon is unreachable would loop. They still reach your other handlers.

## Disabled mode

When `enabled: false` or the DSN is empty, the container injects `NullBeaconClient`. Calls become no-ops and return `null`.

## Ingest endpoint

The client POSTs Envelope bodies to:

```text
{scheme}://{host}[:{port}]/api/{project_id}/envelope/
```

with:

- `Content-Type: application/x-beacon-envelope`
- the full DSN (public + secret) included in the envelope header for authentication
- `X-Beacon-Auth` with `beacon_key` + `beacon_secret` (preferred by Symfony Beacon)

## End-to-end against `symfony-beacon`

See [Getting started](GETTING_STARTED.md) for the complete project + DSN + verification checklist.

Short local reminder:

```bash
cd /path/to/symfony-beacon
make up
make seed   # prints DSN + demo login
```

Default ports:

- HTTPS: `https://localhost:9444`
- HTTP: `http://localhost:9081`

For local self-signed HTTPS, configure the demo or your app with:

```yaml
when@dev:
    nowo_beacon:
        verify_peer: false
```

## Scenario matrix

| Scenario | Setup | Route / action | Expected result |
|----------|-------|----------------|-----------------|
| Success | Valid DSN (`PUBLIC:SECRET@…`) to `https://localhost:9444/...` or `http://localhost:9081/...` | `GET /report` | Demo renders an event id and the event appears in Beacon. |
| Error-level message | Valid DSN | `GET /report-error` | Event is sent with level `error`. |
| Manual exception | Valid DSN | `GET /exception` | Nested exception + rich extra; Beacon receives the event. |
| Full context | Valid DSN | `GET /full-context` | Breadcrumbs, fingerprint, nested exception, dense checkout extra + send.* contexts. |
| Listener capture | `register_error_listener: true` and valid DSN | `GET /boom` | Request fails with `500`; the uncaught exception is reported by the listener. |
| Ignored exception | `ignore_exceptions` contains `InvalidArgumentException` | `GET /boom-ignored` | Request fails with `500`; the exception is intentionally not reported by the listener. |
| Fingerprint | Valid DSN | `GET /fingerprint` | Event is sent with a custom fingerprint, breadcrumbs, current stacktrace, and request context. |
| Breadcrumbs | Valid DSN | `GET /breadcrumbs` | Event includes `breadcrumbs.values`. |
| User context | Valid DSN, logged in, `send.user: true` | `GET /user` | Event includes `user` when authenticated. |
| Transaction | Valid DSN | `GET /transaction` | Performance transaction appears in Beacon. |
| N+1 transaction | Valid DSN | `GET /transaction-nplus1` | Transaction with ≥5 similar DB spans; Beacon marks an N+1 group. |
| Auto HTTP transaction | `auto_http_transaction: true` | `GET /auto-http` (or any non-skipped page) | Message + terminate transaction named after the route. |
| Messenger failure | `register_messenger_listener: true` + Messenger | `GET /messenger-fail` | Exception event with `extra.messenger` (includes `handler_class` / `transport` when available). |
| Console failure | `register_console_listener: true` | `php bin/console app:demo-console-boom` | Exception event with nested `extra.console` (`command`, `command_class`, `exit_code`, `php_sapi`, …). |
| Monolog | `monolog_handler.enabled: true` | `GET /monolog` | Error log is forwarded to Beacon. |
| TLS failure | Self-signed HTTPS with `verify_peer: true` | `GET /report` | Demo may still render a local event id, but Beacon does not ingest it; transport logs an error. |
| Empty DSN | `BEACON_DSN=` | `GET /report` or `GET /status` | Client is disabled, event id is `null`, and status shows `enabled: false`. |
| Missing secret / 403 | Public-key-only DSN or wrong secret | `GET /report` | Client parse failure or Beacon rejects the envelope; no event in the UI. |
| Wrong key / 403 | DSN host/project valid but public key invalid | `GET /report` | Demo may still render a local event id, but Beacon rejects the envelope and no event appears in the Beacon UI. |

The key operational detail is that event ids are generated before transport. Delivery success should be verified in Beacon itself or in server/client logs, especially for TLS and 403 scenarios.
