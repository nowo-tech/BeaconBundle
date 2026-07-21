# Beacon Bundle

[![CI](https://github.com/nowo-tech/BeaconBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/BeaconBundle/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/beacon-bundle.svg)](https://packagist.org/packages/nowo-tech/beacon-bundle)
[![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/beacon-bundle.svg)](https://packagist.org/packages/nowo-tech/beacon-bundle)
[![License](https://img.shields.io/packagist/l/nowo-tech/beacon-bundle.svg)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://packagist.org/packages/nowo-tech/beacon-bundle)
[![Symfony 7 | 8.0 | 8.1+](https://img.shields.io/badge/Symfony-7%20%7C%208.0%20%7C%208.1%2B-000000.svg)](https://github.com/nowo-tech/BeaconBundle/actions/workflows/ci.yml)
[![GitHub stars](https://img.shields.io/github/stars/nowo-tech/BeaconBundle.svg?style=social)](https://github.com/nowo-tech/BeaconBundle/stargazers)
[![Coverage](https://img.shields.io/badge/coverage-97%25-brightgreen.svg)](#tests-and-coverage)

Symfony client for [Symfony Beacon](https://github.com/nowo-tech/symfony-beacon), the self-hosted error-tracking server from Nowo. BeaconBundle sends Envelope requests to any Beacon host described by a DSN and provides both manual APIs and an optional automatic exception listener.

## Features

- DSN-driven ingest with host, optional port, public key, **required secret**, and project id
- Empty DSN disables reporting without changing application code
- Envelope transport to `POST /api/{project_id}/envelope/` with `X-Beacon-Auth` + envelope DSN auth
- Manual APIs through `BeaconClientInterface`
- Optional `kernel.exception` listener for uncaught HTTP exceptions
- `ignore_exceptions` support for listener-side filtering
- Configurable outbound context (`send.*`: stacktrace, request, user, PHP/Symfony versions, OS, …)
- Message events can include current stacktrace; frames may include source context when files are readable
- HTTP events attach request URL/method (and safe headers) when available
- Breadcrumbs (`addBreadcrumb`) and performance transactions (`captureTransaction`)
- Optional console / Messenger failure listeners and optional Monolog handler
- Optional automatic HTTP request transactions (`auto_http_transaction`)
- Precise timestamps (fractional Unix + ISO-8601 with microseconds)
- Local FrankenPHP demo covering messages, exceptions, full context, fingerprints, breadcrumbs, user, transactions / N+1, auto HTTP tx, Monolog, Messenger failures, and console errors

## Installation

```bash
composer require nowo-tech/beacon-bundle
```

Symfony Flex registers the bundle, creates `config/packages/nowo_beacon.yaml`, and adds an empty `BEACON_DSN` entry to your env file.

## Quick start

Full walkthrough (create a Beacon project, copy the DSN, verify events): [Getting started](docs/GETTING_STARTED.md).

```env
BEACON_DSN=https://PUBLIC_KEY:SECRET_KEY@localhost:9444/1
```

```yaml
nowo_beacon:
    enabled: true
    dsn: '%env(string:default::BEACON_DSN)%'
    environment: '%kernel.environment%'
    release: null
    server_name: null
    verify_peer: true
    timeout: 5.0
    register_error_listener: true
    ignore_exceptions: []
    send:
        environment: true
        release: true
        server_name: true
        stacktrace: true
        request: true
        user: false          # opt-in; may include PII
        runtime: true        # PHP version
        framework: true      # Symfony version
        os: true
```

See [Configuration](docs/CONFIGURATION.md) for the full `send.*` reference.

For local self-signed HTTPS only:

```yaml
when@dev:
    nowo_beacon:
        verify_peer: false
```

```php
use Nowo\BeaconBundle\Client\BeaconClientInterface;

final class PaymentService
{
    public function __construct(private readonly BeaconClientInterface $beacon)
    {
    }

    public function charge(): void
    {
        try {
            // ...
        } catch (\Throwable $exception) {
            $this->beacon->captureException($exception, ['order_id' => 42]);
            throw $exception;
        }
    }
}
```

## DSN format

```text
{scheme}://{public_key}:{secret}@{host}[:{port}]/{project_id}
```

| Example | Meaning |
|---------|---------|
| `https://KEY:SECRET@localhost:9444/1` | Local HTTPS Beacon on port `9444`, project `1` |
| `https://KEY:SECRET@errors.example.com/3` | Hosted Beacon over default HTTPS port |
| `http://KEY:SECRET@beacon.internal:9081/2` | Internal HTTP Beacon (Docker ingest) |

Generate keys in Beacon project settings, or run `make seed` in the `symfony-beacon` repository to create demo data and print a DSN (includes secret).

## FrankenPHP worker

The bundled demo uses FrankenPHP. Its production-style Caddyfile enables worker mode, while local dev defaults to `Caddyfile.dev` without workers so code changes show up immediately. See [Demo/FrankenPHP](docs/DEMO-FRANKENPHP.md).

## Documentation

- [Getting started (Symfony Beacon + bundle)](docs/GETTING_STARTED.md)
- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Security](docs/SECURITY.md)
- [Release](docs/RELEASE.md)
- [Demo/FrankenPHP](docs/DEMO-FRANKENPHP.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [Spec Kit](docs/SPEC-KIT.md)
- [GitHub CI](docs/GITHUB_CI.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)

## Tests and coverage

Run the test suite with:

```bash
composer test
composer test-coverage
```

or, in the Docker-based maintainer workflow:

```bash
make test
make test-coverage
```

Coverage (Lines): **96.94%** (measured with `make test-coverage` / PCOV).

| Suite | Status |
|-------|--------|
| PHP unit + integration | 96.94% Lines |
| TypeScript / Python | N/A (no frontend or Python in this bundle) |

## Found this useful?

If BeaconBundle helps your project, please star the repository, report issues, and share feedback:

- Repository: [nowo-tech/BeaconBundle](https://github.com/nowo-tech/BeaconBundle)
- Package: [nowo-tech/beacon-bundle](https://packagist.org/packages/nowo-tech/beacon-bundle)
