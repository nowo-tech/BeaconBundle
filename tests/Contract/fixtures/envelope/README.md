# Golden Envelope fixtures (Phase 3.6)

Canonical NDJSON bodies for the Bundle ↔ Beacon ingest contract.

- Mirrored byte-for-byte in `symfony-beacon/tests/Functional/Ingest/fixtures/envelope/`.
- Deterministic `event_id` / timestamps (not live `EnvelopeBuilder` output).
- DSN host port is the placeholder `__HTTPS_PORT__` (expanded at test runtime from
  `HTTPS_PORT`, default `9447` to match symfony-beacon `.env.dist`). Do not hard-code
  local ports in these files.
- Shape matches what `EnvelopeBuilder` + `EnvelopeTransport` produce today:
  - 3 lines: envelope header, item header (`type` + `content_type`), JSON payload
  - Auth header (HTTP, not in these files): `Beacon beacon_key=…, beacon_secret=…`

- Scheduler check-ins (1.15.0): `schedule_catalog.ndjson`, `check_in_ok.ndjson`, `check_in_error.ndjson`
  (Beacon spec `153`). `make check-envelope-goldens` reports them as `PENDING` until Beacon mirrors them.

When changing the wire format, update both copies and the contract tests.
