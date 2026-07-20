# Release checklist

Use this checklist when cutting a new BeaconBundle version. The workflow [`release.yml`](../.github/workflows/release.yml) runs on push of a tag matching `v*` and creates the GitHub Release from the tag message plus the matching `docs/CHANGELOG.md` entry.

## Before tagging

1. **Update `docs/CHANGELOG.md`**
   - Move items from `## [Unreleased]` into a new version section: `## [X.Y.Z] - YYYY-MM-DD`.
   - Keep an empty `## [Unreleased]` section at the top for the next cycle.
   - Make sure the release notes reflect the actual bundle state, docs, and demo changes.

2. **Update `docs/UPGRADING.md`**
   - Document anything consumers must change when moving to `X.Y.Z`.
   - Rename any placeholder section such as "to the next release" into the concrete target version.
   - Leave a short placeholder for the next release if useful.

3. **Run the pre-release checks**

```bash
make release-check
composer audit
```

`make release-check` already includes:

- `check-no-cursor-coauthor`
- `composer-sync`
- `cs-fix`
- `cs-check`
- `rector-dry`
- `phpstan`
- `test-coverage`
- demo release verification

4. **Create the release commit**
   - Commit `docs/CHANGELOG.md`, `docs/UPGRADING.md`, and any last release-only adjustments.

5. **Re-run the git-hygiene check after the release commit**

```bash
make check-no-cursor-coauthor
```

Do this again even if `make release-check` already passed earlier: the release commit itself must also be clean before push.

## Tag and push

Replace `X.Y.Z` with the version you are releasing:

```bash
git checkout main
git pull origin main
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin main
git push origin vX.Y.Z
```

Notes:

- Use an **annotated tag** (`git tag -a`), not a lightweight tag.
- The tag must start with `v` because [`release.yml`](../.github/workflows/release.yml) listens to `v*`.
- The tag message becomes the first part of the GitHub Release body.
- If `docs/CHANGELOG.md` contains a matching section for `X.Y.Z`, the workflow appends that entry to the release body automatically.

## After push

- Watch the **Create Release** GitHub Actions run.
- Confirm the GitHub Release was created for `vX.Y.Z`.
- Confirm Packagist picked up the new tag for [`nowo-tech/beacon-bundle`](https://packagist.org/packages/nowo-tech/beacon-bundle).

## Quick reference

```bash
make release-check
make check-no-cursor-coauthor
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin main
git push origin vX.Y.Z
```
