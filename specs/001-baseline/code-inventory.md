# Code inventory — baseline (100% of production `src/`)

**Last audited:** 2026-11-01  
**Aligned with:** **v1.19.x** (Messenger observability `specs/004`, deploy markers `specs/005`, self-test `specs/006`, Scheduler check-ins `specs/007`, Messenger remote retry `specs/008`, ingest retries `FR-TR-003`)

Every production PHP unit under `src/` is listed. Demos are out of scope.

## Inventory by category

### Bundle and DI (7)

| Path | Role | FR / notes |
|------|------|------------|
| `src/NowoBeaconBundle.php` | Bundle class (boots fatal / PHP warning handlers once per process; registers `ScheduleProviderLocatorPass`, `RecordingFailureSenderPass` and `TaskDescriptionCommandLoaderPass`) | FR-WK-005, FR-PW-001, FR-SCI-003, FR-MRR-001 |
| `src/DependencyInjection/Configuration.php` | Config tree `nowo_beacon` | FR-DI-001 |
| `src/DependencyInjection/Compiler/ScheduleProviderLocatorPass.php` | Schedule providers locator for the catalog builder | FR-SCI-003 |
| `src/DependencyInjection/Compiler/TaskDescriptionCommandLoaderPass.php` | Lazy `console.command_loader` for task descriptions (before removing, after `AddConsoleCommandPass`) | FR-SCI-009 |
| `src/DependencyInjection/Compiler/RecordingFailureSenderPass.php` | Wraps the failure senders of Symfony's failure-transport listener in `RecordingFailureSender` | FR-MRR-001 |
| `src/DependencyInjection/NowoBeaconExtension.php` | DI wiring, Monolog prepend, enable/disable, transports, instrumentation, connection test command | FR-DI-001/002, FR-CL-004, FR-MO-001, FR-LI-*, FR-TR-002, FR-INS-001, FR-CMD-001 |
| `src/Resources/config/services.yaml` | Shared service defaults | — |

### DSN (3)

| Path | Role | FR / notes |
|------|------|------------|
| `src/Dsn/BeaconDsn.php` | DSN value object extending beacon-php `Dsn` (secret wrapped, not serializable) | FR-DSN-003, FR-SEC-001, FR-SDK-002 |
| `src/Dsn/BeaconDsnParser.php` | Parse/validate DSN | FR-DSN-001/002 |
| `src/Dsn/InvalidBeaconDsnException.php` | Parse errors (extends beacon-php `InvalidDsnException`) | FR-DSN-002, FR-SDK-002 |

### Client API (11)

| Path | Role | FR / notes |
|------|------|------------|
| `src/Client/BeaconClientInterface.php` | Public API | FR-CL-001..008 |
| `src/Client/BeaconClient.php` | Default client (builder → beacon-php `PayloadScrubber` → `before_send` → transport) | FR-CL-001..008, FR-ENV-*, FR-SCOPE-001, FR-SDK-005 |
| `src/Client/BeaconClientFactory.php` | Runtime enable/disable from DSN; picks the bounded ingest client for `sync`, the full one for `async` / `messenger` | FR-DI-002, FR-CL-004, FR-TR-003 |
| `src/Client/NullBeaconClient.php` | Disabled client | FR-CL-004 |
| `src/Client/QueueSnapshotCapableInterface.php` | Optional `captureQueueSnapshot()` capability | FR-MQ-005 |
| `src/Client/DeployCapableInterface.php` | Optional `captureDeploy()` capability | FR-DEP-001 |
| `src/Client/CheckInCapableInterface.php` | Optional `captureScheduleCatalog()` / `captureCheckIn()` capability | FR-SCI-006 |
| `src/Attribute/BeaconCheckIn.php` | `#[BeaconCheckIn(maxRuntime:, enabled:)]` on scheduled message classes | FR-SCI-002 |
| `src/Client/ClientUserAgent.php` | Versioned `User-Agent` | FR-TR-001 |
| `src/Breadcrumb/BreadcrumbBuffer.php` | Request-scoped breadcrumb trail (beacon-php class + `ResetInterface`) | FR-CL-005, FR-SDK-002 |
| `src/Scope/Scope.php` | Mutable tags / context / extras (beacon-php class + `ResetInterface`) | FR-SCOPE-001, FR-CL-007, FR-SDK-002 |

### Envelope and transports (15)

| Path | Role | FR / notes |
|------|------|------------|
| `src/Envelope/TransportResult.php` | Sync POST result VO | FR-CMD-002, FR-TR-001 |
| `src/Envelope/SendOptions.php` | `send.*` value object | FR-ENV-004..006 |
| `src/Envelope/EnvelopeBuilder.php` | NDJSON builder (events, transactions, `queue_snapshot`, `deploy`, `schedule_catalog`, `check_in`) delegating to beacon-php's builder, bundle redaction re-applied, no header DSN | FR-ENV-001..007, FR-WK-004, FR-MQ-005, FR-DEP-001, FR-SCI-006, FR-SDK-003 |
| `src/Envelope/SymfonyRequestContextProvider.php` | Internal: current Symfony request → beacon-php `RequestSnapshot` | FR-ENV-003, FR-SDK-003 |
| `src/Envelope/EnvelopeTransportInterface.php` | Transport contract | FR-TR-001 |
| `src/Envelope/FlushableEnvelopeTransportInterface.php` | Async flush contract | FR-TR-002 |
| `src/Envelope/EnvelopeTransport.php` | Sync HTTP POST ingest through beacon-php `SymfonyHttpClientTransport` (no redirects) | FR-TR-001, FR-ENV-002, FR-SDK-004 |
| `src/Envelope/ExplicitTlsHttpClient.php` | Internal: always sets `verify_peer` / `verify_host` on ingest requests | FR-SEC-001, FR-SDK-004 |
| `src/Envelope/IngestHttpClientFactory.php` | `transport.retry` client (`RetryableHttpClient` + `GenericRetryStrategy`); bounded variant for sync sends | FR-TR-003 |
| `src/Envelope/BoundedRetryAfterStrategy.php` | Sync-only retry strategy: declines a retry whose `Retry-After` exceeds `max_delay_ms` | FR-TR-003 |
| `src/Envelope/AsyncEnvelopeTransport.php` | Defer send until terminate | FR-TR-002 |
| `src/Envelope/MessengerEnvelopeTransport.php` | Queue via Messenger | FR-TR-002 |
| `src/Envelope/PendingTransportRegistry.php` | Tracks flushable transports | FR-TR-002 |
| `src/Envelope/SendBeaconEnvelopeMessage.php` | Messenger message | FR-TR-002 |
| `src/Envelope/SendBeaconEnvelopeMessageHandler.php` | Messenger handler | FR-TR-002 |

### Context and redaction (9)

| Path | Role | FR / notes |
|------|------|------------|
| `src/Context/UserContextProviderInterface.php` | Optional user context (extends beacon-php's interface) | FR-ENV-006, FR-SDK-002 |
| `src/Context/SecurityUserContextProvider.php` | Security token → user summary | FR-ENV-006 (`send.user`) |
| `src/Context/DatabaseExceptionContext.php` | SQL / SQLSTATE facts → `contexts.db` (forwards to beacon-php) | FR-ENV-007 |
| `src/Support/IgnoredRequestPath.php` | Path-prefix matcher + default ignore list | FR-LI-005 |
| `src/Support/IgnoredException.php` | Shared ignore matcher (`ignore_exceptions`, 4xx `HttpExceptionInterface` rule) for the listeners and the Monolog handler | FR-LI-001 |
| `src/Support/HttpRequestSnapshot.php` | Safe HTTP request extras | FR-ENV-005 |
| `src/Support/ConsoleInputSnapshot.php` | Safe console input extras (no raw argv) | FR-LI-002 |
| `src/Support/SensitiveValueRedactor.php` | Secret-like key / value redaction (free text, maps, URLs, card numbers) | FR-ENV-004..005, FR-ENV-009 |
| `src/Support/FrameVarsSerializer.php` | Frame `vars` from call arguments | FR-ENV-004 (`send.stack_vars`) |

### Event listeners (13)

| Path | Role | FR / notes |
|------|------|------------|
| `src/EventListener/BeaconExceptionListener.php` | `kernel.exception` | FR-LI-001, FR-LI-005 |
| `src/EventListener/BeaconConsoleErrorListener.php` | `ConsoleEvents::ERROR` | FR-LI-002 |
| `src/EventListener/BeaconMessengerFailedListener.php` | Messenger final failures (+ `retry_state`, root-cause culprit, `extra.scheduler.run_id`) | FR-LI-003, FR-MQ-002, FR-SCI-004 |
| `src/EventListener/BeaconSchedulerCheckInListener.php` | Opt-in Scheduler catalog + per-run check-ins | FR-SCI-004, FR-SCI-007 |
| `src/EventListener/BeaconMessengerRetryListener.php` | Opt-in retry / recovered events | FR-MQ-002 |
| `src/EventListener/BeaconMessengerTransactionListener.php` | Opt-in per-message transactions | FR-MQ-001 |
| `src/EventListener/BeaconWorkerMemoryListener.php` | Opt-in worker memory warning | FR-MQ-004 |
| `src/EventListener/BeaconPhpWarningHandler.php` | PHP warnings / notices → events (redacted fingerprint) | FR-PW-001, FR-SDK-006 |
| `src/EventListener/BeaconRequestTransactionListener.php` | Opt-in HTTP transactions | FR-LI-004, FR-LI-005, FR-WK-002 |
| `src/EventListener/BeaconRequestScopeResetListener.php` | Worker-safe scope reset at main request, Messenger message and console terminate (1.23.1) | FR-WK-001, FR-WK-003 |
| `src/EventListener/BeaconTraceRequestListener.php` | Seed / honour trace id | FR-WK-001 |
| `src/EventListener/BeaconFatalErrorHandler.php` | Process shutdown fatals | FR-WK-005 |
| `src/EventListener/FlushPendingTransportsListener.php` | Flush on terminate and after each worker message | FR-TR-002, FR-WK-003, FR-MQ-006 |

### Messenger (trace, failure transport, remote retry) (15)

| Path | Role | FR / notes |
|------|------|------------|
| `src/Messenger/FailureTransport/FailureTransportRecorder.php` | Per-message holder of the failure-transport id (`kernel.reset`, cleared on receive) | FR-MRR-001 |
| `src/Messenger/FailureTransport/RecordingFailureSender.php` | Failure sender decorator recording the returned `TransportMessageIdStamp` (listable transports only) | FR-MRR-001 |
| `src/Messenger/RemoteRetry/WorkerKey.php` | `bwk_…` key, HKDF auth / sign subkeys, Bearer header (wrapped secret, not serializable) | FR-MRR-003, FR-SEC-001 |
| `src/Messenger/RemoteRetry/RemoteRetryCredentials.php` | Lazy DSN + worker key resolution (wrapped raw values), project match | FR-MRR-002, FR-MRR-005, FR-SEC-001 |
| `src/Messenger/RemoteRetry/RemoteCommand.php` | Claimed command VO, canonical string, expected signature | FR-MRR-003, FR-MRR-005 |
| `src/Messenger/RemoteRetry/CommandResult.php` | Result VO (status, outcome, closed reason codes, exception class) | FR-MRR-006 |
| `src/Messenger/RemoteRetry/ClaimResponse.php` | Parsed claim response | FR-MRR-003 |
| `src/Messenger/RemoteRetry/RemoteCommandClient.php` | Claim / result HTTP calls (Bearer key, 3 s timeout, status classification, `Retry-After`) | FR-MRR-003, FR-MRR-008 |
| `src/Messenger/RemoteRetry/RemoteCommandException.php` | Classified HTTP failure (auth / rate limited / unavailable / rejected) | FR-MRR-003 |
| `src/Messenger/RemoteRetry/FailedMessageCommandExecutor.php` | Verification order, retry / discard execution, executed-ids cache | FR-MRR-005, FR-MRR-006 |
| `src/Messenger/RemoteRetry/RemoteCommandPoller.php` | `WorkerRunningEvent` poller (schedule, backoff, pause, pending results) | FR-MRR-003, FR-MRR-007 |
| `src/Messenger/RemoteRetry/PollReport.php` | Poll outcome VO (command / tests) | FR-MRR-004 |
| `src/Messenger/MessengerContext.php` | Shared `extra.messenger` builder (no message body) | FR-LI-003, FR-MQ-003 |
| `src/Messenger/BeaconTraceMiddleware.php` | Trace id propagation across Messenger hops (pass-through while the bundle is disabled) | FR-WK-001, FR-WK-006 |
| `src/Messenger/BeaconTraceStamp.php` | Trace id stamp | FR-WK-001 |

### Scheduler check-ins (5)

| Path | Role | FR / notes |
|------|------|------------|
| `src/Scheduler/CheckInPolicy.php` | Max runtime / opt-out resolution (attribute > config), class + command name only | FR-SCI-002 |
| `src/Scheduler/ScheduleCatalogBuilder.php` | Schedules → `schedule_catalog` entries (static providers only, trigger kinds, next run) | FR-SCI-003 |
| `src/Scheduler/ScheduledRun.php` | Deterministic run id + UTC ATOM dates | FR-SCI-004 |
| `src/Scheduler/SchedulerRunRegistry.php` | Bounded per-process run map (`kernel.reset`) | FR-SCI-007 |
| `src/Scheduler/TaskDescriptionResolver.php` | Catalog entry `description` (attribute > config > command description / method docblock > class docblock), bounded cache | FR-SCI-009 |

### Instrumentation and tracing (5)

| Path | Role | FR / notes |
|------|------|------------|
| `src/Trace/TraceIdProvider.php` | Request-scoped trace id (beacon-php class + `ResetInterface`) | FR-WK-001, FR-SDK-002 |
| `src/Instrumentation/SpanBuffer.php` | In-request SQL/HTTP spans (beacon-php class + `ResetInterface`) | FR-INS-001, FR-SDK-002 |
| `src/Instrumentation/SqlNormalizer.php` | Normalize SQL for spans | FR-INS-001 |
| `src/Instrumentation/DoctrineSqlMiddleware.php` | DBAL middleware (+ tracing driver/connection) | FR-INS-001 |
| `src/Instrumentation/TraceableBeaconHttpClient.php` | HttpClient decorator | FR-INS-001 |

### Monolog (1)

| Path | Role | FR / notes |
|------|------|------------|
| `src/Monolog/BeaconMonologHandler.php` | Optional Monolog → Beacon (skips transport self-logs) | FR-MO-001, FR-MO-002 |

### Console commands and connection test (8)

| Path | Role | FR / notes |
|------|------|------------|
| `src/Command/TestConnectionCommand.php` | Console `nowo:beacon:test` | FR-CMD-001..007 (`specs/003-connection-test-command`) |
| `src/Command/MessengerReportCommand.php` | Console `nowo:beacon:messenger-report` (`queue_snapshot`) | FR-MQ-005 (`specs/004-messenger-observability`) |
| `src/Command/DeployCommand.php` | Console `nowo:beacon:deploy` (`deploy` item) | FR-DEP-001 (`specs/005-deploy-markers`) |
| `src/Command/SchedulerCatalogCommand.php` | Console `nowo:beacon:scheduler-catalog` (`schedule_catalog`) | FR-SCI-005 (`specs/007-scheduler-check-ins`) |
| `src/Command/MessengerCommandsCommand.php` | Console `nowo:beacon:messenger-commands [--once] [--time-limit]` (remote command polling outside workers) | FR-MRR-004 (`specs/008-messenger-remote-retry`) |
| `src/Command/SelfTestCommand.php` | Console `nowo:beacon:self-test` (real failures) | FR-ST-001..003 (`specs/006-self-test`) |
| `src/Connection/BeaconConnectionTester.php` | DSN parse + sync probe | FR-CMD-002..007 |
| `src/Connection/ConnectionTestResult.php` | Probe result VO | FR-CMD-* |

### Self-test (service, HTTP endpoint, routing) (8)

| Path | Role | FR / notes |
|------|------|------------|
| `src/SelfTest/BeaconSelfTest.php` | Public self-test service (`status`, `assertReady`, `run`, `flush`) | FR-ST-001..004 |
| `src/SelfTest/SelfTestResult.php` | Returning-kind result VO | FR-ST-001 |
| `src/SelfTest/SelfTestUnavailableException.php` | Not configured / prerequisite missing | FR-ST-003 |
| `src/SelfTest/BeaconSelfTestException.php` | Deliberate failure | FR-ST-004 |
| `src/SelfTest/Message/BeaconSelfTestFailMessage.php` | Messenger message whose handler fails | FR-ST-001 |
| `src/SelfTest/Message/BeaconSelfTestFailHandler.php` | Always-failing handler (messenger only) | FR-ST-001, FR-ST-005 |
| `src/Controller/SelfTestController.php` | Optional JSON endpoint (roles + CSRF); `#[Route]` attributes, never autoconfigured | FR-ST-006, FR-ST-007 |
| `src/Routing/BeaconRouteLoader.php` | `type: nowo_beacon` loader: imports the controller attributes when enabled, applies `self_test.http.path` | FR-ST-006 |

## Coverage summary

| Metric | Value |
|--------|-------|
| PHP production files (`find src -name '*.php' \| wc -l`) | 99 |
| Other production files (`src/Resources/config/services.yaml`) | 1 |
| **Total production sources** (`find src -type f \| wc -l`) | **100** |
| Rows mapped in this inventory | **100** |
| Unmapped files | 0 (no placeholder IDs) |
| Success criteria in `spec.md` | SC-001 … SC-007 |
