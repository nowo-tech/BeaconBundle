# Spec Kit

This repository uses **GitHub Spec Kit** as the maintainer workflow for writing and evolving product specs. It complements [`SPEC-DRIVEN-DEVELOPMENT.md`](SPEC-DRIVEN-DEVELOPMENT.md), which describes BeaconBundle behavior and traceability.

## Minimum sections

Every maintained Spec Kit repository should keep these pieces in sync:

| Area | Minimum content |
|------|------------------|
| `.specify/` | Spec Kit scaffolding, templates, scripts, constitution |
| `.cursor/skills/speckit-*` | Cursor Agent skills used to create specs, plans, and tasks |
| `specs/001-baseline/` | Baseline product spec and code inventory |
| `specs/00N-feature/` | Feature-specific `spec.md`, optionally `plan.md` and `tasks.md` |
| `docs/SPEC-DRIVEN-DEVELOPMENT.md` | Human-readable explanation of product layers and traceability |
| `docs/SPEC-KIT.md` | This operator guide |

## Baseline

The baseline for BeaconBundle lives under [`specs/001-baseline/`](../specs/001-baseline/).

At minimum it should contain:

- `spec.md` describing the bundle behavior
- `code-inventory.md` mapping production code in `src/` to requirements

When production code changes, the baseline must be reviewed as part of the same work.

## Feature workflow

Typical flow for new work:

1. Create or update a feature spec.
2. Add a technical plan if the change is non-trivial.
3. Break the work into tasks.
4. Implement the change with tests and docs.
5. Converge the codebase back to the spec if drift appeared.

In Cursor Agent, the repository already exposes matching skills such as:

- `/speckit-specify`
- `/speckit-plan`
- `/speckit-tasks`
- `/speckit-implement`
- `/speckit-converge`

## Maintainer checklist

Before merging a PR that changes production behavior:

- [ ] relevant `specs/` content still matches the code
- [ ] `README.md` and `docs/` are updated when integrators are affected
- [ ] tests and QA still prove the documented behavior
- [ ] release notes are updated when the change is user-visible

## Related documents

- [SPEC-DRIVEN-DEVELOPMENT.md](SPEC-DRIVEN-DEVELOPMENT.md)
- [ENGRAM.md](ENGRAM.md)
- [`specs/001-baseline/`](../specs/001-baseline/)
