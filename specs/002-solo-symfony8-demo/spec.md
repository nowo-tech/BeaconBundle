# Feature Specification: Solo Symfony 8 Demo

**Feature Branch**: `002-solo-symfony8-demo`

**Created**: 2026-07-20

**Status**: Draft

**Input**: User description: "solo demo symfony 8"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Single demo path (Priority: P1)

An integrator or contributor wants to try Beacon Bundle locally with the least confusion. They open the demo docs, follow one path, and run a single sample app that demonstrates exception and message capture against a Beacon instance.

**Why this priority**: Dual demos (Symfony 7 and 8) force readers to choose, duplicate maintenance, and dilute the “quick start” story. One path is the MVP of this change.

**Independent Test**: From a clean clone, a newcomer can start the documented demo and reach a working local URL without deciding between multiple major-version demos.

**Acceptance Scenarios**:

1. **Given** the repository docs for demos, **When** a reader looks for how to run a local demo, **Then** they find exactly one supported sample application path (Symfony 8).
2. **Given** that sample is configured with a valid Beacon DSN, **When** they start it with the documented commands, **Then** they get a reachable local URL and can trigger demo events that report to Beacon.
3. **Given** previous references to a Symfony 7 sample, **When** docs or root demo instructions are read, **Then** they no longer present Symfony 7 as a supported/current demo option.

---

### User Story 2 - Docs and CI stay consistent (Priority: P2)

A maintainer updates or runs automation related to demos. Scripts, Makefiles, CI jobs, and documentation all point at the same single demo so nothing fails because a removed demo is still referenced.

**Why this priority**: Removing a demo tree without updating references leaves broken links and failing jobs; consistency is required for a clean cutover.

**Independent Test**: Search the repo (excluding history) for the retired demo path/name; remaining hits are only intentional historical notes (e.g. changelog) or none.

**Acceptance Scenarios**:

1. **Given** the Symfony 7 demo directory has been retired, **When** documented “demo up” / FrankenPHP instructions are followed, **Then** every command path resolves to the Symfony 8 demo.
2. **Given** CI or make targets that previously exercised both demos, **When** the pipeline or local make targets run, **Then** they only build/test/start the Symfony 8 demo (or explicitly skip demos without referencing a missing tree).

---

### User Story 3 - Bundle compatibility unchanged (Priority: P3)

A consumer still using Symfony 7.4 in their own app continues to install and use the published bundle within the declared compatibility range. Retiring the Symfony 7 *demo* does not mean dropping Symfony 7 support from the bundle product.

**Why this priority**: Clarifies scope so this feature does not accidentally become a breaking compatibility change.

**Independent Test**: README / Packagist compatibility statements and bundle tests still cover the declared Symfony range; only the sample app under `demo/` is reduced to Symfony 8.

**Acceptance Scenarios**:

1. **Given** the solo-demo change is merged, **When** a reader checks product compatibility, **Then** they still see the bundle’s declared Symfony/PHP support (including Symfony 7.x if still in `composer.json`), separate from “one demo app.”
2. **Given** someone looks for how to try the bundle quickly, **When** they use the demo, **Then** that sample targets Symfony 8 only.

---

### Edge Cases

- What happens when an external bookmark or old blog post still points at `demo/symfony7`? Docs or changelog should note the retirement so maintainers can answer; no need to keep a stub app forever unless desired later.
- How does the system handle someone cloning an old commit that still has both demos? Out of scope; only current default branch behavior matters.
- What if FrankenPHP/make ports were allocated per demo (e.g. 8010 vs another)? The remaining demo keeps a single documented port/URL scheme without requiring the retired demo’s port.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The repository MUST expose exactly one maintained local demo application, based on Symfony 8.
- **FR-002**: Documentation that explains how to run a local demo (including FrankenPHP/demo README and any root README links) MUST instruct users only for that Symfony 8 demo.
- **FR-003**: The retired Symfony 7 demo tree MUST be removed from the working tree (or clearly marked obsolete and unmaintained — default: remove).
- **FR-004**: Build/make/CI entry points that started or tested demos MUST not require or invoke the retired Symfony 7 demo.
- **FR-005**: Product compatibility of Beacon Bundle with Symfony versions declared in package metadata MUST NOT be narrowed solely because the Symfony 7 demo was removed.
- **FR-006**: Demo behavior that already illustrates Beacon capture (exceptions/messages) MUST remain available in the Symfony 8 demo so the sample still proves the integrator contract.

### Key Entities

- **Demo application**: The single sample Symfony app under the repo used to illustrate Beacon Bundle locally.
- **Demo documentation**: Human-facing instructions (paths, env vars such as `BEACON_DSN`, start commands, printed local URL).
- **Compatibility matrix**: Declared supported PHP/Symfony versions for the *bundle* product (distinct from which demo is shipped).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A newcomer can start the local demo from docs in under 10 minutes (assuming Docker/Make already available and a Beacon DSN ready).
- **SC-002**: 100% of current “run the demo” documentation paths lead to the same single sample app (no branching “choose Symfony 7 or 8”).
- **SC-003**: Zero broken references to the retired demo in active docs, make targets, and CI config on the default branch.
- **SC-004**: Bundle compatibility statements remain accurate and unchanged in scope relative to this feature (demo retirement ≠ dropping framework support).

## Assumptions

- “Solo demo Symfony 8” means **remove/stop maintaining** `demo/symfony7` and keep `demo/symfony8` as the only sample, not “add a new demo.”
- Bundle support for Symfony 7.4 (if still declared) remains; only the sample app is Symfony 8–only.
- Existing Symfony 8 demo capabilities (FrankenPHP, DSN via `.env`, capture demos) are sufficient; this feature is consolidation, not a redesign of the demo UI.
- Changelog/UPGRADING may note the demo retirement for contributors; no migration path is required for end-user apps.
- Port/URL currently documented for Symfony 8 remains the canonical demo endpoint unless a conflict forces a one-line doc update.
