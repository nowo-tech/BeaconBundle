# Usage

## Automatic exception reporting

With `register_error_listener: true` and a non-empty DSN, uncaught HTTP exceptions are reported automatically through the `kernel.exception` listener.

Use `ignore_exceptions` when a class should never be reported by that automatic listener:

```yaml
nowo_beacon:
    ignore_exceptions:
        - InvalidArgumentException
```

## Manual reporting

```php
use Nowo\BeaconBundle\Client\BeaconClientInterface;

final class CheckoutController
{
    public function __construct(private readonly BeaconClientInterface $beacon)
    {
    }

    public function __invoke(): void
    {
        $this->beacon->captureMessage('Checkout started', 'info', [
            'cart_id' => 12,
        ]);

        try {
            // ...
        } catch (\Throwable $exception) {
            $this->beacon->captureException($exception, ['cart_id' => 12], [
                'checkout',
                'critical',
            ]);

            throw $exception;
        }
    }
}
```

Manual APIs return the locally generated event id or `null` when the client is disabled.

## Disabled mode

When `enabled: false` or the DSN is empty, the container injects `NullBeaconClient`. Calls become no-ops and return `null`.

## Ingest endpoint

The client POSTs Envelope bodies to:

```text
{scheme}://{host}[:{port}]/api/{project_id}/envelope/
```

with:

- `Content-Type: application/x-beacon-envelope`
- the full DSN included in the envelope header for authentication

## End-to-end against `symfony-beacon`

Reference server checkout:

```text
/home/hector/nowo/developer.local.server/repositories/other/symfony-beacon
```

Typical local flow:

```bash
cd /home/hector/nowo/developer.local.server/repositories/other/symfony-beacon
make up
make seed
```

Default ports:

- HTTPS: `https://localhost:9444`
- HTTP: `http://localhost:9081`

The seed command prints a DSN like:

```text
https://<public_key>@localhost:9444/<project_id>
```

For local self-signed HTTPS, configure the demo or your app with:

```yaml
when@dev:
    nowo_beacon:
        verify_peer: false
```

## Scenario matrix

| Scenario | Setup | Route / action | Expected result |
|----------|-------|----------------|-----------------|
| Success | Valid DSN to `https://localhost:9444/...` or `http://localhost:9081/...` | `GET /report` | Demo renders an event id and the event appears in Beacon. |
| Error-level message | Valid DSN | `GET /report-error` | Event is sent with level `error`. |
| Manual exception | Valid DSN | `GET /exception` | Demo renders the captured exception event id; Beacon receives the event. |
| Listener capture | `register_error_listener: true` and valid DSN | `GET /boom` | Request fails with `500`; the uncaught exception is reported by the listener. |
| Ignored exception | `ignore_exceptions` contains `InvalidArgumentException` | `GET /boom-ignored` | Request fails with `500`; the exception is intentionally not reported by the listener. |
| Fingerprint | Valid DSN | `GET /fingerprint` | Event is sent with a custom fingerprint to influence grouping. |
| TLS failure | Self-signed HTTPS with `verify_peer: true` | `GET /report` | Demo may still render a local event id, but Beacon does not ingest it; transport logs an error. |
| Empty DSN | `BEACON_DSN=` | `GET /report` or `GET /status` | Client is disabled, event id is `null`, and status shows `enabled: false`. |
| Wrong key / 403 | DSN host/project valid but public key invalid | `GET /report` | Demo may still render a local event id, but Beacon rejects the envelope and no event appears in the Beacon UI. |

The key operational detail is that event ids are generated before transport. Delivery success should be verified in Beacon itself or in server/client logs, especially for TLS and 403 scenarios.
