# Golden Envelope fixtures (Phase 3.6)

Canonical NDJSON bodies for the Bundle ↔ Beacon ingest contract.

- Mirrored byte-for-byte in `symfony-beacon/tests/Ingest/fixtures/envelope/`.
- Deterministic `event_id` / timestamps (not live `EnvelopeBuilder` output).
- Shape matches what `EnvelopeBuilder` + `EnvelopeTransport` produce today:
  - 3 lines: envelope header, item header (`type` + `content_type`), JSON payload
  - Auth header (HTTP, not in these files): `Beacon beacon_key=…, beacon_secret=…`

When changing the wire format, update both copies and the contract tests.
