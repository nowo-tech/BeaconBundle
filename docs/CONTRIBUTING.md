# Contributing Guide

Thanks for contributing to BeaconBundle.

## Code of Conduct

Participation in this project is governed by the [Code of Conduct](../CODE_OF_CONDUCT.md). Please report unacceptable behavior to [hectorfranco@nowo.tech](mailto:hectorfranco@nowo.tech).

## Development setup

Docker is the recommended workflow for this repository.

```bash
make up
make setup-hooks
```

What this gives you:

- the PHP container used by the bundle Makefile targets
- Composer dependencies installed inside the container
- local git hooks configured from `.githooks/`

If you prefer a host-only workflow, install dependencies manually:

```bash
composer install
```

## Common commands

Quality checks:

```bash
make cs-check
make phpstan
make test
make qa
```

Composer equivalents:

```bash
composer cs-check
composer phpstan
composer test
composer qa
```

Coverage:

```bash
make test-coverage
make test-coverage-100   # fail unless Lines are 100%
# or
composer test-coverage
```

Coverage HTML is written to `coverage/`. The release checklist expects ~100% PHP line coverage.

## Demo checks

The repository ships a runnable demo under `demo/`.

```bash
make -C demo up-symfony8
make -C demo test-symfony8
make -C demo release-check
```

See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md) for the FrankenPHP setup.

## Git hygiene

Run this once per clone:

```bash
make setup-hooks
```

Before pushing:

```bash
make check-no-cursor-coauthor
```

If CI fails because history already contains forbidden trailers, see [GITHUB_CI.md](GITHUB_CI.md) and use:

```bash
make strip-cursor-coauthor-from-history
```

## Pull requests

Before opening a PR:

- run `make qa`
- run `make test-coverage` when behavior changed materially
- update docs in `README.md` or `docs/` when user-facing behavior changed
- update `docs/CHANGELOG.md` and `docs/UPGRADING.md` when release notes or upgrade guidance changed

Questions and support requests are welcome through the [issue tracker](https://github.com/nowo-tech/BeaconBundle/issues).
