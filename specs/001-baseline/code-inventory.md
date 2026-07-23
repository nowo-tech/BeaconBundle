# Code inventory — baseline (100% of production `src/`)

**Last audited:** 2026-07-23  
**Aligned with:** **v1.6.x**

Every production PHP unit under `src/` is listed. Demos are out of scope.

| Path | Role | FR / notes |
|------|------|------------|
| `src/NowoBeaconBundle.php` | Bundle entrypoint | Registers extension |
| `src/DependencyInjection/Configuration.php` | Config tree `nowo_beacon` | FR-DI-001 |
| `src/DependencyInjection/NowoBeaconExtension.php` | DI wiring, Monolog prepend, enable/disable, transports, instrumentation | FR-DI-001/002, FR-CL-004, FR-MO-001, FR-LI-*, FR-TR-002, FR-INS-001 |
| `src/Resources/config/services.yaml` | Shared service defaults | — |
| `src/Dsn/BeaconDsn.php` | DSN value object | FR-DSN-003 |
| `src/Dsn/BeaconDsnParser.php` | Parse/validate DSN | FR-DSN-001/002 |
| `src/Dsn/InvalidBeaconDsnException.php` | Parse errors | FR-DSN-002 |
| `src/Client/BeaconClientInterface.php` | Public API | FR-CL-001..008 |
| `src/Client/BeaconClient.php` | Default client | FR-CL-001..008, FR-ENV-*, FR-SCOPE-001 |
| `src/Client/BeaconClientFactory.php` | Runtime enable/disable from DSN | FR-DI-002, FR-CL-004 |
| `src/Client/NullBeaconClient.php` | Disabled client | FR-CL-004 |
| `src/Client/ClientUserAgent.php` | Versioned `User-Agent` | FR-TR-001 |
| `src/Breadcrumb/BreadcrumbBuffer.php` | Request-scoped breadcrumb trail | FR-CL-005 |
| `src/Scope/Scope.php` | Mutable tags / context / extras | FR-SCOPE-001, FR-CL-007 |
| `src/Context/UserContextProviderInterface.php` | Optional user context | FR-ENV-006 |
| `src/Context/SecurityUserContextProvider.php` | Security token → user summary | FR-ENV-006 (`send.user`) |
| `src/Envelope/SendOptions.php` | `send.*` value object | FR-ENV-004..006 |
| `src/Envelope/EnvelopeBuilder.php` | NDJSON builder (events + transactions) | FR-ENV-001..006 |
| `src/Envelope/EnvelopeTransportInterface.php` | Transport contract | FR-TR-001 |
| `src/Envelope/FlushableEnvelopeTransportInterface.php` | Async flush contract | FR-TR-002 |
| `src/Envelope/EnvelopeTransport.php` | Sync HTTP POST ingest | FR-TR-001, FR-ENV-002 |
| `src/Envelope/AsyncEnvelopeTransport.php` | Defer send until terminate | FR-TR-002 |
| `src/Envelope/MessengerEnvelopeTransport.php` | Queue via Messenger | FR-TR-002 |
| `src/Envelope/PendingTransportRegistry.php` | Tracks flushable transports | FR-TR-002 |
| `src/Envelope/SendBeaconEnvelopeMessage.php` | Messenger message | FR-TR-002 |
| `src/Envelope/SendBeaconEnvelopeMessageHandler.php` | Messenger handler | FR-TR-002 |
| `src/EventListener/BeaconExceptionListener.php` | `kernel.exception` | FR-LI-001 |
| `src/EventListener/BeaconConsoleErrorListener.php` | `ConsoleEvents::ERROR` | FR-LI-002 |
| `src/EventListener/BeaconMessengerFailedListener.php` | Messenger final failures | FR-LI-003 |
| `src/EventListener/BeaconRequestTransactionListener.php` | Opt-in HTTP transactions | FR-LI-004 |
| `src/EventListener/FlushPendingTransportsListener.php` | Flush on terminate | FR-TR-002 |
| `src/Instrumentation/SpanBuffer.php` | In-request SQL/HTTP spans | FR-INS-001 |
| `src/Instrumentation/SqlNormalizer.php` | Normalize SQL for spans | FR-INS-001 |
| `src/Instrumentation/DoctrineSqlMiddleware.php` | DBAL middleware (+ tracing driver/connection) | FR-INS-001 |
| `src/Instrumentation/TraceableBeaconHttpClient.php` | HttpClient decorator | FR-INS-001 |
| `src/Monolog/BeaconMonologHandler.php` | Optional Monolog → Beacon | FR-MO-001 |

**Count:** 35 PHP production files under `src/` (+ `services.yaml`). Inventory complete — no placeholders.
