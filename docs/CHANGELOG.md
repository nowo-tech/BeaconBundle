# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [1.3.0] - 2026-07-20

### Added

- Stack frames include source context (`pre_context`, `context_line`, `post_context`, `abs_path`) when the file is readable (≈5 lines around the crash site)

### Fixed

- `monolog_handler.enabled` now prepends a `type: service` Monolog handler (the `monolog.handler` tag alone is not wired by MonologBundle).

## [1.2.0] - 2026-07-20

### Added

- `captureMessage()` attaches current PHP stacktrace when `send.stacktrace` is true (BeaconBundle frames filtered out).
- HTTP `request` / `contexts.request` (url, method, query, safe headers such as Host/User-Agent) on events and transactions when `send.request` is true and a request is available.
- Demo: full `send.*`, `release` / `BEACON_RELEASE`, `symfony/monolog-bundle` + Monolog handler enabled, richer `/fingerprint` sample.

### Changed

- Demo and Flex recipe document complete outbound context defaults (`send.*`, console listener, optional Monolog).

## [1.1.1] - 2026-07-20

### Fixed

- Demo Symfony 8: post-login redirect targeted missing route `demo_debug`; now redirects to `demo_user` (`/user`). Removed obsolete `/debug` access-control rule.

### Changed

- Applied PHP-CS-Fixer / Rector cleanups on sources and tests (no public API changes).
- Demo and README docs list 1.1.x routes (breadcrumbs, user, transaction, monolog) and accurate feature bullets.

## [1.1.0] - 2026-07-20

### Added

- Configurable `send.*` switches for outbound context (environment, release, server name, stacktrace, request, user, runtime/PHP, framework/Symfony, OS).
- Event payloads include fractional `timestamp`, ISO-8601 `datetime` (microseconds), and `contexts` (runtime / framework / os).
- Optional authenticated `user` context via Security token storage (`send.user`, default `false`).
- Breadcrumbs via `BeaconClientInterface::addBreadcrumb()` (attached to the next event/transaction, then cleared).
- Performance transactions via `captureTransaction()` (Envelope item `type: transaction`).
- Optional console error listener (`register_console_listener`, default `true`).
- Optional Monolog handler (`monolog_handler.enabled`, requires `monolog/monolog`).
- Demo routes: `/breadcrumbs`, `/user`, `/transaction`, `/monolog` (Symfony 8 sample).

### Changed

- Envelope `sent_at` / event `datetime` use microsecond precision.
- Demo `.env.example` documents HTTP ingest via `host.docker.internal:9081` for local Symfony Beacon.

### Fixed

- `BeaconConsoleErrorListener` constructor defaults so autowiring does not fail when arguments are not yet set.
- Do not autoload `BeaconMonologHandler` unless Monolog’s `AbstractProcessingHandler` is available (avoids 500 in apps without `monolog/monolog`).

## [1.0.6] - 2026-07-20

### Removed

- `demo/symfony7` — only `demo/symfony8` is maintained as the sample app (bundle runtime still supports Symfony 7 via Composer).

### Changed

- Demo aggregate Makefile and docs (`demo/README.md`, Getting started) now document a single FrankenPHP demo on http://localhost:8011.

## [1.0.5] - 2026-07-20

### Fixed

- CI: remove `composer config platform.php 8.4` for Symfony 8 jobs (Composer treats `8.4` as `8.4.0`, which fails Symfony 8.1’s `php >=8.4.1` requirement).

## [1.0.4] - 2026-07-20

### Added

- [Getting started](GETTING_STARTED.md) manual: create a Symfony Beacon project, obtain a DSN, configure BeaconBundle, and verify event collection.
- Runtime `BeaconClientFactory` so empty `%env(BEACON_DSN)%` disables reporting without failing container compilation.

### Fixed

- Regenerate `composer.lock` on Symfony **7.4** so `composer install` works on PHP 8.2 (CI code-style/coverage jobs). Symfony 8 remains supported via the CI matrix `composer update` path.
- Prefer `%env(string:default::BEACON_DSN)%` so empty env values resolve to `""` instead of `null`.

## [1.0.3] - 2026-07-20

### Fixed

- Changelog now reflects restoration of `demo/symfony7` (incorrect Unreleased note from `1.0.1` era removed).

## [1.0.2] - 2026-07-20

### Fixed

- Restored the Symfony 7 demo (`demo/symfony7`) that was accidentally removed in `1.0.1`.

## [1.0.1] - 2026-07-20

### Changed

- Raised minimum requirements to **PHP `>=8.2 <8.6`** and **Symfony `^7.0 || ^8.0`** (dropped PHP 8.1 and Symfony 6.x).
- CI matrix now runs PHP 8.2–8.5 × Symfony 7.0, 7.4, 8.0, and 8.1.

## [1.0.0] - 2026-07-20

### Added

- Initial `nowo-tech/beacon-bundle` release with DSN-based Envelope transport for Symfony Beacon.
- Automatic `kernel.exception` listener with `ignore_exceptions` support.
- Flex recipe and installation defaults centered on `BEACON_DSN`.
- Expanded documentation set for installation, configuration, usage, release, security, performance, Engram, and Spec Kit workflows.
- Demo routes covering message capture, manual exception capture, listener-triggered exceptions, ignored exceptions, fingerprints, and runtime status.

[Unreleased]: https://github.com/nowo-tech/BeaconBundle/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.6...v1.1.0
[1.0.6]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/nowo-tech/BeaconBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/BeaconBundle/releases/tag/v1.0.0
