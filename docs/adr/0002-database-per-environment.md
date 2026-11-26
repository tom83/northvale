# ADR 0002: MariaDB locally, SQLite in CI

- Status: Accepted
- Date: 2026-11-26

## Context

Tome makes the database disposable, so each environment can use whichever
database is simplest there. Drupal 11 needs SQLite 3.45 or newer. That's
available on GitHub's `ubuntu-24.04` runners, but not on every DDEV image
release.

## Decision

- **DDEV (local and Codespaces):** MariaDB 10.11, configured by DDEV's
  `settings.ddev.php`.
- **CI and anything outside DDEV:** SQLite. The path defaults to
  `.sqlite/site.sqlite` and can be overridden with `DRUPAL_SQLITE_PATH`.
- `settings.php` switches on `IS_DDEV_PROJECT`.

## Consequences

- Nothing to provision in CI, and the build is a pure function of the git tree.
- Local and CI databases differ, so the CI build is the authoritative test
  of the static output.
