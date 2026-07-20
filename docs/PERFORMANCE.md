# Performance

BeaconBundle performs **synchronous HTTP ingest**: one outbound POST per reported event.

Keep these constraints in mind:

- there is **no retry loop** in the client
- the current request path pays the network cost
- `timeout` should stay intentionally low
- slow or unavailable Beacon servers should fail fast rather than block user traffic

Recommended default: keep `timeout` near the current `5.0` seconds or lower if your environment needs stricter latency budgets.
