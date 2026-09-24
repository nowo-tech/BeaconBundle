# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/beacon-bundle` (`symfony-bundle`) |
| Audited revision | `v1.8.2` (worker scenario B complete) |
| Audit date | 2026-09-24 |
| Method | Manual review of every file under `src/` (client, transports, listeners, instrumentation, Monolog handler, DI extension, `Resources/config/services.yaml`) |
| **Verdict** | ✅ **Compatible with FrankenPHP worker + kernel not reset (scenario B)** — request-scoped buffers, trace id, auto HTTP transaction Request, and leftover async POSTs are cleared/drained without relying on `services_resetter` |
| Remediation | W-01/W-02: `BeaconRequestScopeResetListener` (+ pending flush). W-03: LRU 64 in `EnvelopeBuilder`. W-04: fatal handler via `NowoBeaconBundle::boot()`. W-05 accepted. W-06: transaction listener resets on every main request. W-07: flush terminate priority `-2048` (after transaction `-1024`). |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `BreadcrumbBuffer`, `SpanBuffer`, `Scope`, `TraceIdProvider` hold per-request data by design and are reset per main request by `BeaconRequestScopeResetListener` (W-01, W-02 resolved) |
| Static properties / `static` locals | ✅ | None; only pure static helpers (`SqlNormalizer`, `HttpRequestSnapshot`, `SensitiveValueRedactor`, `IgnoredRequestPath`, `ClientUserAgent`, …) |
| `ResetInterface` / `kernel.reset` coverage | ✅ | The four buffers and `BeaconRequestTransactionListener` are tagged and their `reset()` clears every mutable property; scenario B no longer depends on it |
| Request / user / locale captured in services | ✅ | `RequestStack` and `TokenStorage` are read when an envelope is built; `BeaconRequestTransactionListener` keeps the Request only until `kernel.terminate` and **resets on every main `kernel.request`** (W-06) |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used; DSN comes from container parameters / `%env()%` |
| Doctrine / EntityManager | ✅ | Optional DBAL middleware only records spans / breadcrumbs; no entities kept |
| Output, headers, `exit`, shutdown functions | ✅ | `register_shutdown_function()` in `BeaconFatalErrorHandler`, registered once per process from `NowoBeaconBundle::boot()` (W-04 resolved) |
| Resources (files, sockets, cURL) held open | ✅ | Only Symfony HttpClient; async responses are drained on `kernel.terminate` **after** auto HTTP transactions (flush priority `-2048`, W-07), with a safety flush at the next main `kernel.request` |
| Memory growth across requests | ✅ | Buffers are capped (50 breadcrumbs, 100 spans, 32 tags) and reset per request; `EnvelopeBuilder` source-line cache is an LRU of 64 files (W-03 resolved) |
| Blocking I/O and timeouts | ⚠️ Low (accepted) | Default `sync` transport POSTs during the request; `timeout` / `max_duration` = `nowo_beacon.timeout` (5 s default). HttpClient instrumentation waits for headers (W-05) |
| Third-party static state | ✅ | Monolog handler extends `AbstractProcessingHandler` and does not buffer |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist`; one `@phpstan-ignore frankenphp.worker.noRegisterShutdownFunction` at `src/EventListener/BeaconFatalErrorHandler.php:45` |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Breadcrumb\BreadcrumbBuffer` | yes (`kernel.reset`) | `$items` (max 50) | ✅ | ✅ (reset per main request) |
| `Instrumentation\SpanBuffer` | yes (`kernel.reset`) | `$spans` (max 100) | ✅ | ✅ (reset per main request) |
| `Scope\Scope` | yes (`kernel.reset`) | `$tags` (max 32) | ✅ | ✅ (reset per main request) |
| `Trace\TraceIdProvider` | yes (`kernel.reset`) | `$traceId` | ✅ | ✅ (reset per main request) |
| `nowo.beacon.client` (`BeaconClient`, built by `BeaconClientFactory`) | yes | none itself; owns an `EnvelopeBuilder` (bounded source-line cache) and a transport | ✅ | ✅ |
| `AsyncEnvelopeTransport` (inside the client, `transport.mode: async`) | yes (not a container service) | `$pending` responses, drained on terminate (`-2048`) and again at next main request start | ✅ | ✅ |
| `EventListener\BeaconRequestScopeResetListener` | yes | none; resets four buffers + flushes pending on main `kernel.request` (priority 4096) | ✅ | ✅ |
| `EventListener\BeaconRequestTransactionListener` (opt-in) | yes (`kernel.reset`) | `$startedAt`, `$request`; cleared on every main request start, terminate, and `reset()` | ✅ | ✅ |
| `EventListener\FlushPendingTransportsListener` | yes | none; terminate priority `-2048` (after transaction) | ✅ | ✅ |
| `EventListener\BeaconFatalErrorHandler` | yes (public, instantiated in `NowoBeaconBundle::boot()`) | `$registered` flag (once per process) | ✅ | ✅ |
| `Instrumentation\DoctrineSqlMiddleware` (opt-in) + internal driver / connection wrappers | yes | none; writes into the buffers | ✅ | ✅ |
| `Instrumentation\TraceableBeaconHttpClient` (opt-in, decorates `http_client`) | yes | none; writes into the buffers | ✅ | ✅ |
| `Messenger\BeaconTraceMiddleware` | yes | none; writes into `TraceIdProvider` | ✅ | ✅ |
| `Monolog\BeaconMonologHandler` (opt-in) | yes | Monolog processor stack only | ✅ | ✅ |
| `Context\SecurityUserContextProvider` | yes | none; reads the token per call | ✅ | ✅ |
| `Dsn\BeaconDsnParser`, `Connection\BeaconConnectionTester`, `Command\TestConnectionCommand` | yes | none (`readonly`); tester / command are CLI oriented | ✅ | ✅ |

Value objects (`BeaconDsn`, `SendOptions`, `TransportResult`, `ConnectionTestResult`, `SendBeaconEnvelopeMessage`, `BeaconTraceStamp`) are immutable and created per call or at build time.

## Findings

### W-01 — Breadcrumbs, spans and scope tags leak into later requests without reset (Medium)

- **Where:** `src/Breadcrumb/BreadcrumbBuffer.php:20`, `src/Instrumentation/SpanBuffer.php:32`, `src/Scope/Scope.php:28`; reset tags in `src/Resources/config/services.yaml:9-23`. Breadcrumbs are only cleared when an event or transaction is built (`src/Envelope/EnvelopeBuilder.php:412`), spans only when a transaction is captured (`src/Client/BeaconClient.php:138`), tags only via `clearTags()` / `reset()`.
- **Worker impact:** under **A** the services resetter clears the three services before each request, which is correct. Under **B** nothing clears them when a request ends without an error: breadcrumbs (normalized SQL from `DoctrineSqlMiddleware`, outbound hosts from `TraceableBeaconHttpClient`, and any `addBreadcrumb()` data from the application) and tags set with `setTag()` (for example a user id or tenant) are attached to the **next** event sent by that worker, which usually belongs to another user. The data goes to the Beacon server, not to the other user, so it is a privacy and attribution problem for operators rather than a data leak to end users. Memory stays bounded by the caps (50 / 100 / 32).
- **Recommendation:** keep `services_resetter` active (scenario A). Under B, clear the buffers and scope on `kernel.terminate` (for example with a small subscriber that calls `reset()` on the four services). Never put secrets in breadcrumb data or tags.
- **Status:** Resolved — new `BeaconRequestScopeResetListener` (registered in `src/Resources/config/services.yaml`) calls `reset()` on `BreadcrumbBuffer`, `SpanBuffer` and `Scope` on every main `kernel.request` (priority 4096, before all bundle listeners); sub-requests are ignored. Clearing at the start of the next request (instead of on `kernel.terminate`) keeps data available to terminate-time captures such as the request transaction. The `kernel.reset` tags stay for scenario A. Test: `BeaconRequestScopeResetListenerTest::testConsecutiveMainRequestsDoNotShareBreadcrumbsSpansTagsOrTraceId`, `testSubRequestKeepsMainRequestData`.

### W-02 — Same trace id reused by every request of a worker without reset (Medium)

- **Where:** `src/EventListener/BeaconTraceRequestListener.php:37-48` calls `TraceIdProvider::getOrCreate()`, which keeps the existing value (`src/Trace/TraceIdProvider.php:24`, `:29-36`). `EnvelopeBuilder::seedTraceId()` also copies it into the `trace_id` scope tag (`src/Envelope/EnvelopeBuilder.php:233-243`).
- **Worker impact:** under **A** `TraceIdProvider::reset()` sets it to `null`, so each request gets a new id. Under **B** the first id generated in the worker is reused for all later requests that do not send an `X-Beacon-Trace-Id` header. It is also written into the request attribute and the inbound request headers, and propagated to Messenger messages through `BeaconTraceMiddleware`. Events from unrelated users are then correlated as one trace.
- **Recommendation:** keep `services_resetter` active. To be B-safe, the listener could call `TraceIdProvider::reset()` before seeding the id for each main request.
- **Status:** Resolved — `BeaconRequestScopeResetListener` also resets `TraceIdProvider` before `BeaconTraceRequestListener` (priority 100) seeds it, so each main request gets a new id unless `X-Beacon-Trace-Id` is sent. Test: `testConsecutiveMainRequestsDoNotShareBreadcrumbsSpansTagsOrTraceId`, `testInboundTraceHeaderIsStillHonouredAfterReset`.

### W-03 — `EnvelopeBuilder` source-line cache is never cleared (Low)

- **Where:** `src/Envelope/EnvelopeBuilder.php:43` (`$sourceLineCache`), filled by `loadSourceLines()` at `:580` when `send.stacktrace` is true (the default).
- **Worker impact:** each source file that appears in a reported stack trace is read once and its lines are kept for the life of the worker (files larger than 1 MB are skipped). The builder is created by `BeaconClientFactory` inside the shared client, so it is not a container service and is not reset in scenario A or B. Growth is bounded by the number of distinct files that appear in traces, but it can reach several MB on a worker that reports many different errors. After a deploy without a worker restart, code context could also be stale.
- **Recommendation:** set FrankenPHP `max_requests` (or `FRANKENPHP_LOOP_MAX`) so workers are recycled, and restart workers on deploy. A bounded LRU in the bundle would remove this finding.
- **Status:** Resolved — `EnvelopeBuilder::loadSourceLines()` keeps at most 64 files (least recently used evicted, `SOURCE_LINE_CACHE_MAX_FILES`). Stale code context after a deploy without worker restart remains possible for cached files: restart workers on deploy. Test: `EnvelopeBuilderTest::testSourceLineCacheIsBoundedAndKeepsRecentlyUsedFiles`.

### W-04 — Fatal-error shutdown handler is never registered (Info)

- **Where:** `src/DependencyInjection/NowoBeaconExtension.php:258-266` registers `BeaconFatalErrorHandler` as a private, untagged service with an `addMethodCall('register')`. Nothing references it, and the demo's compiled container (`demo/symfony8/var/cache/dev/App_KernelDevDebugContainerCompiler.log`) shows `RemoveUnusedDefinitionsPass: Removed service "Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler"; reason: unused.`
- **Worker impact:** none today. If the service is made reachable, note that in worker mode a function registered with `register_shutdown_function()` (`src/EventListener/BeaconFatalErrorHandler.php:46`) only runs when the worker script ends, not after each request. The `$registered` flag prevents it from being registered twice.
- **Recommendation:** out of scope for worker viability. If fatal reporting is wanted, the service must be made reachable (public, or instantiated from `Bundle::boot()`). Keep it registered once per process.
- **Status:** Resolved — `NowoBeaconExtension` now defines the service as public (without the unused `register` method call) and `NowoBeaconBundle::boot()` fetches it and calls `register()`; the `$registered` flag keeps it once per handler instance, and boot runs once per worker. In worker mode the shutdown function runs when the worker exits (e.g. after a fatal error that kills it), not after each request. Tests: `NowoBeaconBundleTest::testBootRegistersFatalErrorHandler`, `ExtensionLoadTest::testWorkerSafetyServicesAreRegistered`.

### W-05 — Outbound calls block the worker thread (Low)

- **Where:** `src/Envelope/EnvelopeTransport.php:87-88` (`timeout` and `max_duration` from `nowo_beacon.timeout`, default 5 s). `src/Instrumentation/TraceableBeaconHttpClient.php:51` calls `getStatusCode()` right after `request()`.
- **Worker impact:** with `transport.mode: sync` (the default), every captured event holds the worker thread for up to `timeout` if Beacon is slow. With `instrumentation.http_client: true`, every outbound request of the application waits for response headers inside `request()`, so HttpClient's lazy and concurrent behaviour is lost. Both are explicit and bounded; no state leaks.
- **Recommendation:** use `transport.mode: async` (drained on `kernel.terminate`) or `messenger` in production workers, keep `timeout` low (1-2 s), and cap waiting with FrankenPHP `max_wait_time`.
- **Status:** Accepted — timeouts are explicit and configurable; transport choice is a deployment decision.

### W-06 — Auto HTTP transaction Request leaked across requests without reset (Medium)

- **Where:** `BeaconRequestTransactionListener::$request` / `$startedAt`. Previously, ignored paths returned early without clearing leftover state from the previous main request.
- **Worker impact:** under **B**, a timed request followed by `/health` (or another ignored path) could still emit a transaction on terminate for the **previous** user/route.
- **Status:** Resolved — every main `kernel.request` calls `reset()` before ignore-path / enable checks. Test: `BeaconListenersTest::testRequestTransactionListenerClearsPreviousRequestBeforeIgnoredPath`.

### W-07 — Async flush ran at the same terminate priority as auto HTTP transactions (Medium)

- **Where:** both listeners used `KernelEvents::TERMINATE` priority `-1024`. Registration order flushed pending POSTs **before** `captureTransaction()` could enqueue the request transaction.
- **Worker impact:** with `transport.mode: async` + `auto_http_transaction: true`, the transaction POST could stay in `$pending` until the **next** request's terminate (or forever if terminate never ran again).
- **Status:** Resolved — flush priority is `-2048`; `BeaconRequestScopeResetListener` also flushes leftover pending at the start of the next main request. Test: `FlushPendingTransportsListenerTest::testSubscribedEventsIncludeKernelAndConsoleTerminate`.

Good patterns observed: every request-scoped service implements `ResetInterface` and is tagged `kernel.reset`, and the buffers have hard size caps. Scenario B is covered by bundle-owned request/terminate listeners without depending on `services_resetter`. The user context is read from the token at send time and is off by default (`send.user: false`).

## Usage recommendations in worker mode

- Scenario B is supported by the bundle itself. Keeping Symfony's `services_resetter` enabled (default in FrankenPHP's Symfony runtime) is still recommended for the application's own services.
- Prefer `transport.mode: async` or `messenger` and a low `timeout` in worker deployments.
- Set `max_requests` / `FRANKENPHP_LOOP_MAX` to recycle workers periodically, and restart workers on every deploy (cached source lines, W-03).
- `before_send` services, custom `UserContextProviderInterface` implementations and any code calling `setTag()` / `addBreadcrumb()` must not keep per-request data in their own properties.
- The demo ships a worker-mode Caddyfile (`demo/symfony8/docker/frankenphp/Caddyfile`, `worker { … }` block).

## Re-audit triggers

Re-run this audit when a change adds: a new buffer or cache to the client, `EnvelopeBuilder` or a transport; a service that stores the Request, token or user; removes a `kernel.reset` tag; makes `BeaconFatalErrorHandler` reachable; or adds any use of `$_SERVER` / `$_ENV`, `set_error_handler()` or `set_exception_handler()` at runtime.
