# Code inventory — baseline (100% of production `src/`)

**Last audited:** 2026-10-11  
**Aligned with:** **v1.11.0** (Messenger observability `specs/004`, deploy markers `specs/005`)

Every production PHP unit under `src/` is listed. Demos are out of scope.

| Path | Role | FR / notes |
|------|------|------------|
| `src/NowoBeaconBundle.php` | Bundle class (boots fatal / PHP warning handlers once per process) | FR-WK-005, FR-PW-001 |
| `src/Command/TestConnectionCommand.php` | Console `nowo:beacon:test` | FR-CMD-001..007 (`specs/003-connection-test-command`) |
| `src/Command/MessengerReportCommand.php` | Console `nowo:beacon:messenger-report` (`queue_snapshot`) | FR-MQ-005 (`specs/004-messenger-observability`) |
| `src/Command/DeployCommand.php` | Console `nowo:beacon:deploy` (`deploy` item) | FR-DEP-001 (`specs/005-deploy-markers`) |
| `src/Connection/BeaconConnectionTester.php` | DSN parse + sync probe | FR-CMD-002..007 |
| `src/Connection/ConnectionTestResult.php` | Probe result VO | FR-CMD-* |
| `src/Envelope/TransportResult.php` | Sync POST result VO | FR-CMD-002, FR-TR-001 |
| `src/DependencyInjection/Configuration.php` | Config tree `nowo_beacon` | FR-DI-001 |
| `src/DependencyInjection/NowoBeaconExtension.php` | DI wiring, Monolog prepend, enable/disable, transports, instrumentation, connection test command | FR-DI-001/002, FR-CL-004, FR-MO-001, FR-LI-*, FR-TR-002, FR-INS-001, FR-CMD-001 |
| `src/Resources/config/services.yaml` | Shared service defaults | — |
| `src/Dsn/BeaconDsn.php` | DSN value object | FR-DSN-003 |
| `src/Dsn/BeaconDsnParser.php` | Parse/validate DSN | FR-DSN-001/002 |
| `src/Dsn/InvalidBeaconDsnException.php` | Parse errors | FR-DSN-002 |
| `src/Client/BeaconClientInterface.php` | Public API | FR-CL-001..008 |
| `src/Client/BeaconClient.php` | Default client | FR-CL-001..008, FR-ENV-*, FR-SCOPE-001 |
| `src/Client/BeaconClientFactory.php` | Runtime enable/disable from DSN | FR-DI-002, FR-CL-004 |
| `src/Client/NullBeaconClient.php` | Disabled client | FR-CL-004 |
| `src/Client/QueueSnapshotCapableInterface.php` | Optional `captureQueueSnapshot()` capability | FR-MQ-005 |
| `src/Client/DeployCapableInterface.php` | Optional `captureDeploy()` capability | FR-DEP-001 |
| `src/Client/ClientUserAgent.php` | Versioned `User-Agent` | FR-TR-001 |
| `src/Breadcrumb/BreadcrumbBuffer.php` | Request-scoped breadcrumb trail | FR-CL-005 |
| `src/Scope/Scope.php` | Mutable tags / context / extras | FR-SCOPE-001, FR-CL-007 |
| `src/Context/UserContextProviderInterface.php` | Optional user context | FR-ENV-006 |
| `src/Context/SecurityUserContextProvider.php` | Security token → user summary | FR-ENV-006 (`send.user`) |
| `src/Context/DatabaseExceptionContext.php` | SQL / SQLSTATE facts → `contexts.db` | FR-ENV-007 |
| `src/Envelope/SendOptions.php` | `send.*` value object | FR-ENV-004..006 |
| `src/Envelope/EnvelopeBuilder.php` | NDJSON builder (events, transactions, `queue_snapshot`, `deploy`) | FR-ENV-001..007, FR-WK-004, FR-MQ-005, FR-DEP-001 |
| `src/Envelope/EnvelopeTransportInterface.php` | Transport contract | FR-TR-001 |
| `src/Envelope/FlushableEnvelopeTransportInterface.php` | Async flush contract | FR-TR-002 |
| `src/Envelope/EnvelopeTransport.php` | Sync HTTP POST ingest | FR-TR-001, FR-ENV-002 |
| `src/Envelope/AsyncEnvelopeTransport.php` | Defer send until terminate | FR-TR-002 |
| `src/Envelope/MessengerEnvelopeTransport.php` | Queue via Messenger | FR-TR-002 |
| `src/Envelope/PendingTransportRegistry.php` | Tracks flushable transports | FR-TR-002 |
| `src/Envelope/SendBeaconEnvelopeMessage.php` | Messenger message | FR-TR-002 |
| `src/Envelope/SendBeaconEnvelopeMessageHandler.php` | Messenger handler | FR-TR-002 |
| `src/EventListener/BeaconExceptionListener.php` | `kernel.exception` | FR-LI-001, FR-LI-005 |
| `src/EventListener/BeaconConsoleErrorListener.php` | `ConsoleEvents::ERROR` | FR-LI-002 |
| `src/EventListener/BeaconMessengerFailedListener.php` | Messenger final failures (+ `retry_state`, root-cause culprit) | FR-LI-003, FR-MQ-002 |
| `src/EventListener/BeaconMessengerRetryListener.php` | Opt-in retry / recovered events | FR-MQ-002 |
| `src/EventListener/BeaconMessengerTransactionListener.php` | Opt-in per-message transactions | FR-MQ-001 |
| `src/EventListener/BeaconWorkerMemoryListener.php` | Opt-in worker memory warning | FR-MQ-004 |
| `src/EventListener/BeaconPhpWarningHandler.php` | PHP warnings / notices → events | FR-PW-001 |
| `src/EventListener/BeaconRequestTransactionListener.php` | Opt-in HTTP transactions | FR-LI-004, FR-LI-005, FR-WK-002 |
| `src/EventListener/BeaconRequestScopeResetListener.php` | Worker-safe request buffer reset | FR-WK-001, FR-WK-003 |
| `src/EventListener/BeaconTraceRequestListener.php` | Seed / honour trace id | FR-WK-001 |
| `src/EventListener/BeaconFatalErrorHandler.php` | Process shutdown fatals | FR-WK-005 |
| `src/EventListener/FlushPendingTransportsListener.php` | Flush on terminate and after each worker message | FR-TR-002, FR-WK-003, FR-MQ-006 |
| `src/Messenger/MessengerContext.php` | Shared `extra.messenger` builder (no message body) | FR-LI-003, FR-MQ-003 |
| `src/Messenger/BeaconTraceMiddleware.php` | Trace id propagation across Messenger hops | FR-WK-001 |
| `src/Messenger/BeaconTraceStamp.php` | Trace id stamp | FR-WK-001 |
| `src/Trace/TraceIdProvider.php` | Request-scoped trace id | FR-WK-001 |
| `src/Support/IgnoredRequestPath.php` | Path-prefix matcher + default ignore list | FR-LI-005 |
| `src/Support/HttpRequestSnapshot.php` | Safe HTTP request extras | FR-ENV-005 |
| `src/Support/ConsoleInputSnapshot.php` | Safe console input extras (no raw argv) | FR-LI-002 |
| `src/Support/SensitiveValueRedactor.php` | Secret-like key / value redaction | FR-ENV-004..005 |
| `src/Support/FrameVarsSerializer.php` | Frame `vars` from call arguments | FR-ENV-004 (`send.stack_vars`) |
| `src/Instrumentation/SpanBuffer.php` | In-request SQL/HTTP spans | FR-INS-001 |
| `src/Instrumentation/SqlNormalizer.php` | Normalize SQL for spans | FR-INS-001 |
| `src/Instrumentation/DoctrineSqlMiddleware.php` | DBAL middleware (+ tracing driver/connection) | FR-INS-001 |
| `src/Instrumentation/TraceableBeaconHttpClient.php` | HttpClient decorator | FR-INS-001 |
| `src/Monolog/BeaconMonologHandler.php` | Optional Monolog → Beacon | FR-MO-001 |

**Count:** 60 PHP production files under `src/` (+ `services.yaml`). Inventory complete — no placeholders.
