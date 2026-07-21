# Performance

BeaconBundle supports three Envelope delivery modes (`nowo_beacon.transport.mode`):

| Mode | Behaviour | Request-path cost |
|------|-----------|-------------------|
| `sync` (default) | Blocks until HTTP status is read | Full network RTT + timeout budget |
| `async` | Starts the POST immediately; finalizes status on `kernel.terminate` / console terminate | Connection start only; status I/O after the response is sent |
| `messenger` | Dispatches `SendBeaconEnvelopeMessage` to the bus; a worker POSTs with sync HTTP | Queue dispatch only (requires `symfony/messenger` + a consumer) |

Keep these constraints in mind:

- there is **no retry loop** in the client (failed deliveries are logged)
- `timeout` still applies to each HTTP attempt (sync, async finalize, and messenger workers)
- slow or unavailable Beacon servers should fail soft rather than crash the app
- `async` still uses the PHP process; for durable queues prefer `messenger`

Recommended default: keep `timeout` near `5.0` seconds (or lower) and use `async` when ingest latency must not delay HTML/API responses.
