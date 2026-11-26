# ADR 0002: SQLite in every environment

- Status: Accepted
- Date: 2026-11-26
- Supersedes: the first version of this ADR, "MariaDB locally, SQLite in CI"

## Context

Tome makes the database disposable: `scripts/rebuild.sh` rebuilds it from the
export in `config/` and `content/` with `drush site:install --existing-config`,
then `drush tome:import`.

The first version of this ADR used MariaDB in DDEV and SQLite in CI. That can't
work with Tome. Drupal treats each database driver as a module and records it
in `core.extension.yml`, so an export made on MariaDB lists `mysql` as enabled.
Installing that config on SQLite means swapping the driver Drupal is running
on, and the installer refuses:

> Unable to uninstall the SQLite module because: The module 'SQLite' is
> providing the database driver 'sqlite'.

The `config_exclude_modules` setting can't fix this, because the installer
reads the sync directory directly, without the config transformers that apply
that setting.

The original reason for MariaDB locally was that Drupal 11 needs SQLite 3.45
or newer, which not every DDEV image had. The current DDEV web image has
SQLite 3.46.1, and GitHub's `ubuntu-24.04` runners have 3.45.

## Decision

- Use **SQLite everywhere**: DDEV, Codespaces and GitHub Actions.
- `settings.php` still includes DDEV's `settings.ddev.php` (hash salt, trusted
  hosts and so on) but always sets the SQLite connection after it.
- The database lives at `.sqlite/site.sqlite`, which is gitignored apart from
  `.sqlite/.gitkeep` so the folder exists after a clone. `DRUPAL_SQLITE_PATH`
  overrides it.
- `.ddev/config.yaml` omits DDEV's database container.
- PHPUnit uses SQLite everywhere too (the default in `phpunit.xml.dist`).

## Consequences

- An export installs identically in every environment, and the CI build stays
  a pure function of the git tree. Verified on 2026-11-26: the Phase 0 spike
  passes in DDEV, and its export rebuilds outside DDEV's settings, as CI does.
- One database to support, and one less container in DDEV and Codespaces.
- DDEV's database tools (`ddev mysql`, `ddev export-db`, `ddev import-db`,
  database GUIs) don't apply. To reset, run `ddev rebuild`.
- SQLite handles one writer at a time. That's fine for one developer and a
  static build, but this setup isn't suited to a shared, live Drupal backend.
- The DDEV image's SQLite version has to stay at 3.45 or newer. If an image
  update drops below it, pin `ddev_version_constraint` in `.ddev/config.yaml`.
