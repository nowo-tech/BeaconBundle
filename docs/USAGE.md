# Usage

For a full end-to-end setup (create a Symfony Beacon project, copy the DSN, verify issues in the UI), start with [Getting started](GETTING_STARTED.md).

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

With default `send.stacktrace: true`, `captureMessage()` includes a current PHP stacktrace and `culprit` (no `Throwable` required). When source files are readable, frames may also include `abs_path` and ≈5 lines of source context. With `send.request: true`, HTTP events also attach `request` / `contexts.request` when a request is active.

## Breadcrumbs

```php
$beacon->addBreadcrumb('Opened checkout', 'navigation');
$beacon->addBreadcrumb('Applied coupon', 'cart', 'info', ['code' => 'SAVE10']);
$beacon->captureMessage('Checkout failed', 'error'); // breadcrumbs attached, then cleared
```

## Performance transactions

```php
$start = microtime(true);
// ... work ...
$end = microtime(true);
$beacon->captureTransaction('checkout', $start, $end, [
    [
        'op' => 'db.query',
        'description' => 'SELECT …',
        'span_id' => bin2hex(random_bytes(8)),
        'start_timestamp' => $start,
        'timestamp' => $end,
    ],
]);
```

Transactions appear under **Performance** in Symfony Beacon.

## Console errors

With `register_console_listener: true` (default), uncaught console command errors are reported with `extra.console` / `extra.command`.

## Messenger failures

With `register_messenger_listener: true` (default) and `symfony/messenger` installed, final worker failures (`WorkerMessageFailedEvent` when `willRetry()` is false) are reported with `extra.messenger.message_class` / `receiver_name`.

## Automatic HTTP transactions

Opt in to one performance transaction per main HTTP request:

```yaml
nowo_beacon:
    auto_http_transaction: true
```

Profiler, WDT, `/health/*`, and `/build*` paths are skipped. Prefer this for coarse request timing; use `captureTransaction()` for finer spans.

## Monolog

```yaml
nowo_beacon:
    monolog_handler:
        enabled: true
        level: error
```

Requires `monolog/monolog`. Records at/above the level are forwarded as Beacon messages (or `captureException` when `context.exception` is a `Throwable`).

## Disabled mode

When `enabled: false` or the DSN is empty, the container injects `NullBeaconClient`. Calls become no-ops and return `null`.

## Ingest endpoint

The client POSTs Envelope bodies to:

```text
{scheme}://{host}[:{port}]/api/{project_id}/envelope/
```

with:

- `Content-Type: application/x-beacon-envelope`
- the full DSN (public + secret) included in the envelope header for authentication
- `X-Beacon-Auth` with `beacon_key` + `beacon_secret` (preferred by Symfony Beacon)

## End-to-end against `symfony-beacon`

See [Getting started](GETTING_STARTED.md) for the complete project + DSN + verification checklist.

Short local reminder:

```bash
cd /path/to/symfony-beacon
make up
make seed   # prints DSN + demo login
```

Default ports:

- HTTPS: `https://localhost:9444`
- HTTP: `http://localhost:9081`

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
| Fingerprint | Valid DSN | `GET /fingerprint` | Event is sent with a custom fingerprint, breadcrumbs, current stacktrace, and request context. |
| Breadcrumbs | Valid DSN | `GET /breadcrumbs` | Event includes `breadcrumbs.values`. |
| User context | Valid DSN, logged in, `send.user: true` | `GET /user` | Event includes `user` when authenticated. |
| Transaction | Valid DSN | `GET /transaction` | Performance transaction appears in Beacon. |
| Auto HTTP transaction | `auto_http_transaction: true` | Any non-skipped page | Transaction named after the route (or `METHOD path`). |
| Messenger failure | `register_messenger_listener: true` + Messenger | Final failed worker message | Exception event with `extra.messenger`. |
| Monolog | `monolog_handler.enabled: true` | `GET /monolog` | Error log is forwarded to Beacon. |
| TLS failure | Self-signed HTTPS with `verify_peer: true` | `GET /report` | Demo may still render a local event id, but Beacon does not ingest it; transport logs an error. |
| Empty DSN | `BEACON_DSN=` | `GET /report` or `GET /status` | Client is disabled, event id is `null`, and status shows `enabled: false`. |
| Wrong key / 403 | DSN host/project valid but public key invalid | `GET /report` | Demo may still render a local event id, but Beacon rejects the envelope and no event appears in the Beacon UI. |

The key operational detail is that event ids are generated before transport. Delivery success should be verified in Beacon itself or in server/client logs, especially for TLS and 403 scenarios.
