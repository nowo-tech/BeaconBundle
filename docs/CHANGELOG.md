# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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

### Changed

- Documentation and examples now use Beacon-specific terminology consistently.
- README was rewritten as the canonical product entry point with DSN format, quick start, FrankenPHP, and documentation links.
- Demo app text, pages, and route matrix were aligned with BeaconBundle instead of legacy bundle wording.
