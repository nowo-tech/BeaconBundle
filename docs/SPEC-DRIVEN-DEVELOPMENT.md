# Spec-driven development

BeaconBundle uses a lightweight spec-driven workflow so product behavior, implementation and contributor tooling stay aligned. This document is the single entry point for **what the bundle guarantees**, **how it is proven**, **what it deliberately does not do**, and the **`REQ-*` anchors** used in Makefiles, scripts and tests.

There is **no** separate executable specification language (no Gherkin): **PHPUnit** (unit, integration and Envelope contract tests), **PHPStan** and **Igor** are the normative proof alongside the prose below and the Spec Kit artifacts under [`specs/`](../specs/).

## Table of contents

- [Three layers in sync](#three-layers-in-sync)
- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
  - [Goal](#goal)
  - [In scope](#in-scope)
  - [Explicit non-goals](#explicit-non-goals)
  - [Not part of the Packagist API](#not-part-of-the-packagist-api)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req-)
- [Contributor workflow](#contributor-workflow)
- [GitHub Spec Kit](#github-spec-kit)
- [Relationship to Engram](#relationship-to-engram)
- [Language](#language)
- [See also](#see-also)

## Three layers in sync

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) (user stories, `FR-*`, `SC-*`) and [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) (every file under `src/`), plus the feature specs `specs/002-…` to `specs/008-…`. Tooling manual: [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what consuming applications may rely on, documented in [`README.md`](../README.md), [`INSTALLATION.md`](INSTALLATION.md), [`CONFIGURATION.md`](CONFIGURATION.md) and [`USAGE.md`](USAGE.md), and proven by the tests under `tests/` (Envelope shapes by the golden fixtures in `tests/Contract/fixtures/envelope/`).
3. **Traceability anchors** — stable `REQ-*` identifiers in Makefiles, scripts, tests and code comments (see [Requirement identifiers](#requirement-identifiers-req-)), so PRs and issues can cite tooling behavior.

## User stories

| ID | Story | Functional scope | Integrator docs |
|----|-------|------------------|-----------------|
| US-01 | As a Symfony integrator, I want to install BeaconBundle quickly so I can point my app at a Symfony Beacon server with a DSN. | DSN parsing, runtime enable / disable (`FR-DSN-*`, `FR-DI-*`) | [INSTALLATION](INSTALLATION.md), [CONFIGURATION](CONFIGURATION.md) |
| US-02 | As an application developer, I want to capture messages and exceptions manually so I can report important events with extra context. | `BeaconClientInterface` (`FR-CL-*`) | [USAGE](USAGE.md) |
| US-03 | As an operator, I want uncaught HTTP, console and Messenger failures reported automatically so I see production failures without wrapping every controller. | Listeners (`FR-LI-*`, `FR-PW-001`, `FR-WK-005`) | [USAGE](USAGE.md), [CONFIGURATION](CONFIGURATION.md) |
| US-04 | As a maintainer, I want clear release, security and upgrade docs so the bundle is easy to ship safely. | Docs and release tooling | [RELEASE](RELEASE.md), [SECURITY](SECURITY.md), [UPGRADING](UPGRADING.md) |
| US-05 | As a contributor, I want a demo with success, listener, TLS, disabled and rejected-ingest scenarios to validate the bundle locally. | `demo/symfony8` (`specs/002-solo-symfony8-demo`) | [DEMO-FRANKENPHP](DEMO-FRANKENPHP.md) |
| US-06 | As an application developer, I want breadcrumbs, transactions, spans and configurable `send.*` context so Beacon receives richer events. | `FR-CL-005..007`, `FR-ENV-*`, `FR-INS-001` | [USAGE](USAGE.md), [CONFIGURATION](CONFIGURATION.md) |
| US-07 | As an operator, I want event delivery that never breaks or stalls a request, with retries when Beacon has a transient error. | Transports and retries (`FR-TR-001..003`) | [CONFIGURATION](CONFIGURATION.md#retries-on-beacon-server-errors) |
| US-08 | As an operator running FrankenPHP workers, I want no request data to leak between requests. | Worker safety (`FR-WK-*`) | [FRANKENPHP-WORKER-AUDIT](FRANKENPHP-WORKER-AUDIT.md) |
| US-09 | As an operator, I want Messenger worker observability, deploy markers, a self-test, Scheduler check-ins and remote retry of failed messages. | `specs/003` … `specs/008` | [USAGE](USAGE.md) |

## Bundle functional scope

### Goal

Send errors, messages, performance transactions and operational items (queue snapshots, deploys, schedule catalogs, check-ins) from a Symfony application to a self-hosted **[Symfony Beacon](https://github.com/nowo-tech/symfony-beacon)** server as Envelope NDJSON, configured by one DSN (`BEACON_DSN`), without ever breaking or stalling the host request.

### In scope

- DSN parsing and validation; empty DSN or `enabled: false` disables sending at runtime.
- `BeaconClientInterface`: messages, exceptions, breadcrumbs, tags / scope, transactions, `before_send`.
- Automatic reporting: `kernel.exception`, console errors, final Messenger failures, PHP warnings, process fatals, optional Monolog handler.
- Outbound context switches (`send.*`) with secret redaction; opt-in Doctrine SQL / HttpClient spans.
- Transports `sync`, `async`, `messenger`; bounded HTTP timeouts; retries with backoff on Beacon 5xx answers — a synchronous send never waits for a `Retry-After` above `transport.retry.max_delay_ms`, background sends honour it in full.
- FrankenPHP worker safety without `kernel.reset` between requests.
- Opt-in features with their own specs: connection test command (`specs/003`), Messenger observability (`specs/004`), deploy markers (`specs/005`), self-test command / service / opt-in JSON endpoint (`specs/006`), Scheduler check-ins (`specs/007`), Messenger remote retry / discard pulled from Beacon (`specs/008`).

### Explicit non-goals

- Full APM / session-replay parity with commercial SaaS products.
- Hosting the Beacon server (see `nowo-tech/symfony-beacon`) or any inbound endpoint other than the opt-in self-test JSON endpoint.
- A client-side JavaScript SDK.
- An offline queue or on-disk buffer for events that could not be delivered (use `transport.mode: messenger` with a persistent transport instead).
- Retrying 4xx answers or transport errors (DNS, refused connection, timeout).
- Executing Messenger handlers in the remote-retry poller, or pushing commands into the application (pull model only).
- Shipping demos for several Symfony majors (the sample app is Symfony 8 only).

### Not part of the Packagist API

`demo/`, `.specify/`, `specs/`, `.scripts/`, `docs/ENGRAM.md`, `docs/SPEC-KIT.md` and this document are excluded from the Composer archive (`archive.exclude`). Classes under `tests/` and internal helpers documented as such in the code inventory are not a public contract; the public surface is `BeaconClientInterface` and its capability interfaces, `BeaconSelfTest`, the `#[BeaconCheckIn]` attribute, the console commands, the route names and the `nowo_beacon` configuration tree.

## Validating the functional spec

```bash
make qa                 # cs-check + phpstan + PHPUnit (Docker)
make test-coverage-100  # fails unless line coverage is 100%
make igor               # worker-state audit (REQ-CS-008)
make release-check      # full pre-release chain (REQ-MAKE-002)
composer qa             # same QA without Docker
```

Rule: every behavior change ships with **tests** under `tests/` (unit, integration or contract). Changes to Envelope shapes update the golden fixtures and are checked against Symfony Beacon with `make check-envelope-goldens`.

## Requirement identifiers (`REQ-*`)

| ID | Location | Meaning |
|----|----------|---------|
| REQ-MAKE-002 | `Makefile` → `release-check` | Pre-release chain: git hygiene, open PRs, raw-form gate, composer sync, cs, rector, phpstan, igor, coverage, demos |
| REQ-MAKE-008 | `Makefile`, `demo/symfony8/Makefile` → soft `-include …/Makefile.update-deps.mk` | `update-deps` target from the monorepo helper |
| REQ-MAKE-009 | `Makefile`, `demo/Makefile`, `demo/symfony8/Makefile` | Optional includes only (`-include`), so a standalone checkout and CI still work |
| REQ-MAKE-010 | `Makefile`, `demo/symfony8/Makefile` → `COMPOSE_BIN` | Prefer Compose V2, fall back to `docker-compose` V1 |
| REQ-CS-007 | [`PSR.md`](PSR.md) | PSR-3 / PSR-18 / PSR-20 evaluation |
| REQ-CS-008 | `Makefile` → `igor`, `igor.json`, `@igor-ignore` justifications in `src/` | Worker-state audit of long-lived services |
| REQ-GIT-001 | `.githooks/commit-msg`, `make setup-hooks`, `make check-no-cursor-coauthor`, `.scripts/check-no-cursor-coauthor.sh` | No AI co-author trailers in commits |
| REQ-REL-003 | `make check-open-prs`, `.scripts/check-open-prs.sh` | No unresolved open PRs before a release |
| REQ-TWIG-005 | `make check-no-raw-html-form`, `.scripts/check-no-raw-html-form.sh` | No raw `<form>` / `<input>` in Twig (demo login uses a FormType) |
| REQ-TEST-011 | `make demo-smoke` | Boot `demo/symfony8` and assert one HTTP 200 |
| REQ-DEMO-008 | [`FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md) | Worker-mode audit (kernel not reset between requests) |
| REQ-RUNTIME-001 | `src/Envelope/BoundedRetryAfterStrategy.php`, `src/Envelope/IngestHttpClientFactory.php`, `tests/Unit/Envelope/IngestHttpClientFactoryTest.php` | Bounded remote I/O: a synchronous send never waits for a `Retry-After` above `transport.retry.max_delay_ms`; background sends honour it |
| REQ-SF-004 | `src/Controller/SelfTestController.php` (`#[Route]`), `src/Routing/BeaconRouteLoader.php`, `tests/Integration/RoutingControllersOnlyTest.php` | Attribute routes that stay opt-in: not autoconfigured, exposed only by the `type: nowo_beacon` import |
| REQ-IDE-006 | [`CONTRIBUTING.md`](CONTRIBUTING.md#cursor-agent-req-ide-006) | Cursor Agent: Ctrl+Enter submit, queued follow-ups |

When scripted behavior changes, update the matching row or add a new ID, and keep the `REQ-*` comment next to the target or test that implements it.

## Contributor workflow

1. **Clarify** the behavior: update the relevant spec (`specs/001-baseline/spec.md` or a feature spec; new features via `/speckit-specify`).
2. **Implement with tests** under `tests/`; keep coverage at 100%.
3. **Anchor** scripted behavior with a `REQ-*` identifier (table above).
4. **Update the baseline**: `specs/001-baseline/code-inventory.md` for every new or changed file under `src/` (see the [SPEC-KIT maintainer checklist](SPEC-KIT.md#maintainer-checklist)).
5. **Ship integrator docs** when behavior or configuration changes: `USAGE.md`, `CONFIGURATION.md`, `CHANGELOG.md`, `UPGRADING.md`.

## GitHub Spec Kit

This repository uses GitHub Spec Kit with **Cursor Agent** (`.specify/`, `.cursor/skills/speckit-*`). Key paths: [`specs/001-baseline/`](../specs/001-baseline/) (baseline spec and 100% code inventory), `specs/002-…` to `specs/008-…` (features), `.specify/memory/constitution.md` (principles). Re-init after upgrading Specify CLI:

```bash
specify init --here --force --integration cursor-agent --script sh
```

The full manual — install, initialization, folder structure, skills and troubleshooting — is **[`SPEC-KIT.md`](SPEC-KIT.md)**.

## Relationship to Engram

[`ENGRAM.md`](ENGRAM.md) covers the org-wide checklist plane (documentation hygiene, MCP, badges, automation hints) and the short repository memory for assistants. This document covers behavior and traceability. Both are maintained; neither duplicates the other.

## Language

Integrator documentation under `docs/`, the root `README.md` and the specs under `specs/` are maintained in **English**.

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual
- [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) — baseline functional spec
- [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md), [`RELEASE.md`](RELEASE.md)
- [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md), [`FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md)
