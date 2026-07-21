#!/usr/bin/env sh
# Diff golden Envelope fixtures between BeaconBundle and symfony-beacon when both checkouts exist.
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
BUNDLE_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
BUNDLE_FIXTURES="$BUNDLE_ROOT/tests/Contract/fixtures/envelope"
BEACON_FIXTURES="${BEACON_FIXTURES:-$BUNDLE_ROOT/../../other/symfony-beacon/tests/Ingest/fixtures/envelope}"

if [ ! -d "$BEACON_FIXTURES" ]; then
  echo "SKIP: sibling symfony-beacon fixtures not found at $BEACON_FIXTURES"
  exit 0
fi

failed=0
for name in event_happy.ndjson event_exception.ndjson transaction_with_spans.ndjson; do
  if ! cmp -s "$BUNDLE_FIXTURES/$name" "$BEACON_FIXTURES/$name"; then
    echo "MISMATCH: $name differs between Bundle and Beacon golden fixtures"
    diff -u "$BUNDLE_FIXTURES/$name" "$BEACON_FIXTURES/$name" || true
    failed=1
  fi
done

if [ "$failed" -ne 0 ]; then
  echo "Golden Envelope fixtures are out of sync. Update both copies (Phase 3.6)."
  exit 1
fi

echo "OK: golden Envelope fixtures match ($BUNDLE_FIXTURES ↔ $BEACON_FIXTURES)"
