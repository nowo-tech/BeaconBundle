# Code inventory — baseline (100% of production `src/`)

**Last audited:** 2026-07-20

Every production PHP unit under `src/` is listed. Demos are out of scope.

| Path | Role | FR / notes |
|------|------|------------|
| `src/NowoBeaconBundle.php` | Bundle entrypoint | Registers extension |
| `src/DependencyInjection/Configuration.php` | Config tree `nowo_beacon` | FR-DI-001 |
| `src/DependencyInjection/NowoBeaconExtension.php` | DI wiring, enable/disable | FR-DI-001/002, FR-CL-004 |
| `src/Resources/config/services.yaml` | Parser + listener defaults | — |
| `src/Dsn/BeaconDsn.php` | DSN value object | FR-DSN-003 |
| `src/Dsn/BeaconDsnParser.php` | Parse/validate DSN | FR-DSN-001/002 |
| `src/Dsn/InvalidBeaconDsnException.php` | Parse errors | FR-DSN-002 |
| `src/Client/BeaconClientInterface.php` | Public API | FR-CL-001..003 |
| `src/Client/BeaconClient.php` | Default client | FR-CL-001..003, FR-ENV-* |
| `src/Client/NullBeaconClient.php` | Disabled client | FR-CL-004 |
| `src/Envelope/EnvelopeBuilder.php` | NDJSON builder | FR-ENV-001 |
| `src/Envelope/EnvelopeTransport.php` | HTTP POST ingest | FR-TR-001, FR-ENV-002 |
| `src/EventListener/BeaconExceptionListener.php` | `kernel.exception` | FR-LI-001 |

**Count:** 13 PHP production files (+ `services.yaml`). Inventory complete — no placeholders.
