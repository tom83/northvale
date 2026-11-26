# Northvale Council

A white-label UK local council website built with **Drupal 11** and **Layout
Builder**, and published as flat HTML with **Tome**, so hosting is free.

Northvale is a fictional council. This project shows the architecture, content
modelling, editor experience and custom PHP you'd expect in a real council
build, without using any client's code or content.

> Status: Phase 0/1. Project scaffold and Tome spike.

## How it works

```
Local (DDEV)                 GitHub Actions                    Static host
Drupal 11 + Layout Builder   composer install                  Cloudflare Pages
Tome Sync writes             scripts/rebuild.sh (SQLite)  ─►   (free tier)
config/ content/ files/ ─►   drush tome:static → html/
```

- `config/`, `content/` and `files/` are the source of truth and are committed.
- The database is disposable. `ddev rebuild` (`scripts/rebuild.sh`) rebuilds it
  from git.
- Decisions are recorded in [docs/adr](docs/adr).

## Getting started

Needs WSL2 and [DDEV](https://ddev.com) (on Windows, put the project inside
the WSL filesystem, e.g. `~/projects/northvale`, not under `/mnt/c`). You can
also click **Code → Codespaces** on GitHub to get a ready-made environment.

```bash
ddev start
ddev composer install

# First time only, before there is a Tome export:
ddev drush site:install minimal -y
ddev drush pm:install -y tome layout_builder council_core council_tome

# Every time after that:
ddev rebuild                 # rebuild the database from config/ + content/
ddev drush uli               # one-time admin login link
```

Content and config are exported automatically when you save them in the UI.
To export everything at once, run `ddev drush tome:export -y`.

## Commands

| Command | What it does |
|---|---|
| `ddev rebuild` | Rebuild the database from the Tome export (**wipes the local DB**) |
| `ddev qa` | PHPCS (Drupal standards) + PHPStan (level 6) + PHPUnit |
| `ddev qa lint` / `analyse` / `test` | Run just one of those |
| `ddev build-static [uri]` | Export the site to `./html` |
| `ddev spike` | Phase 0 Tome checks in `./.spike` (**wipes the local DB**) |

## Layout

```
config/                     Drupal config (Tome Sync)
content/                    Content as JSON (Tome Sync)
files/                      Uploaded files (Tome Sync)
web/modules/custom/         Custom modules (council_core, ...)
web/sites/default/settings.php
scripts/spike/              Phase 0 risk checks
docs/adr/                   Architecture decision records
.github/workflows/          CI, static build, spike
```
