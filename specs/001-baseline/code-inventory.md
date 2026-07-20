# Code inventory — baseline (100% of production `src/`)

**Last audited:** 2026-07-20  
**Aligned with:** **v1.3.x**

Every production PHP unit under `src/` is listed. Demos are out of scope.

| Path | Role | FR / notes |
|------|------|------------|
| `src/NowoBeaconBundle.php` | Bundle entrypoint | Registers extension |
| `src/DependencyInjection/Configuration.php` | Config tree `nowo_beacon` | FR-DI-001 |
| `src/DependencyInjection/NowoBeaconExtension.php` | DI wiring, Monolog prepend, enable/disable | FR-DI-001/002, FR-CL-004, FR-MO-001, FR-LI-* |
| `src/Resources/config/services.yaml` | Shared service defaults | — |
| `src/Dsn/BeaconDsn.php` | DSN value object | FR-DSN-003 |
| `src/Dsn/BeaconDsnParser.php` | Parse/validate DSN | FR-DSN-001/002 |
| `src/Dsn/InvalidBeaconDsnException.php` | Parse errors | FR-DSN-002 |
| `src/Client/BeaconClientInterface.php` | Public API | FR-CL-001..006 |
| `src/Client/BeaconClient.php` | Default client | FR-CL-001..006, FR-ENV-* |
| `src/Client/BeaconClientFactory.php` | Runtime enable/disable from DSN | FR-DI-002, FR-CL-004 |
| `src/Client/NullBeaconClient.php` | Disabled client | FR-CL-004 |
| `src/Breadcrumb/BreadcrumbBuffer.php` | Request-scoped breadcrumb trail | FR-CL-005 |
| `src/Context/UserContextProviderInterface.php` | Optional user context | FR-ENV-006 |
| `src/Context/SecurityUserContextProvider.php` | Security token → user summary | FR-ENV-006 (`send.user`) |
| `src/Envelope/SendOptions.php` | `send.*` value object | FR-ENV-004..006 |
| `src/Envelope/EnvelopeBuilder.php` | NDJSON builder (events + transactions) | FR-ENV-001..006 |
| `src/Envelope/EnvelopeTransport.php` | HTTP POST ingest | FR-TR-001, FR-ENV-002 |
| `src/EventListener/BeaconExceptionListener.php` | `kernel.exception` | FR-LI-001 |
| `src/EventListener/BeaconConsoleErrorListener.php` | `ConsoleEvents::ERROR` | FR-LI-002 |
| `src/Monolog/BeaconMonologHandler.php` | Optional Monolog → Beacon | FR-MO-001 |

**Count:** 19 PHP production files (+ `services.yaml`). Inventory complete — no placeholders.
