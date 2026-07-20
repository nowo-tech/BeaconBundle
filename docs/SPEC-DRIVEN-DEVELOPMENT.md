# Spec-driven development

BeaconBundle uses a lightweight spec-driven workflow so product behavior, implementation, and contributor tooling stay aligned.

## Layers

This repository has three layers that should move together:

1. **Product behavior**
   - what BeaconBundle promises to integrators
   - documented in [`README.md`](../README.md), [`INSTALLATION.md`](INSTALLATION.md), [`CONFIGURATION.md`](CONFIGURATION.md), and [`USAGE.md`](USAGE.md)

2. **Spec artifacts**
   - baseline and feature specs under [`specs/`](../specs/)
   - Spec Kit scaffolding under `.specify/`
   - operator guidance in [`SPEC-KIT.md`](SPEC-KIT.md)

3. **Verification**
   - PHPUnit, PHPStan, coverage, demo smoke checks, and release checks
   - expressed through Composer scripts, Makefiles, and CI

## User stories

| ID | Story |
|----|-------|
| US-01 | As a Symfony integrator, I want to install BeaconBundle quickly so I can point my app at a Symfony Beacon server with a DSN. |
| US-02 | As an application developer, I want to capture messages and exceptions manually so I can report important events with extra context. |
| US-03 | As an operator, I want uncaught HTTP exceptions to be reported automatically so I can see production failures without wrapping every controller. |
| US-04 | As a maintainer, I want clear release, security, and upgrade docs so the bundle is easy to ship safely. |
| US-05 | As a contributor, I want demo routes and E2E guidance so I can validate success, listener, TLS, disabled, and rejected-ingest scenarios locally. |

## Engram

[`ENGRAM.md`](ENGRAM.md) is the short repository memory for BeaconBundle. It captures the context that an AI assistant or maintainer should remember between sessions: what the bundle is, which docs are authoritative, which config keys are stable, and how the demo is intended to be used.

## Spec Kit

[`SPEC-KIT.md`](SPEC-KIT.md) is the tooling companion to this document. Use it when you need to:

- understand the expected `specs/` structure
- run Spec Kit workflows
- keep baseline and feature artifacts in sync with code changes

## Working rule

When behavior changes:

1. update code
2. update docs for integrators
3. update tests and QA proof
4. update `specs/` artifacts if the production contract changed

That order keeps BeaconBundle understandable both to humans and to tooling.
