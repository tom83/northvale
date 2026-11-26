---
name: run-northvale
description: Start, run, build, test and screenshot the Northvale Council Drupal 11 site (DDEV + Tome). Use when asked to run or start the site, log in to Drupal admin, take a screenshot of a page, check a page renders, build the static HTML export, or run QA (PHPCS/PHPStan/PHPUnit).
---

Northvale is a Drupal 11 site served by DDEV. Drive it with the headless
browser driver `.claude/skills/run-northvale/driver.mjs`. It runs **inside the
DDEV web container** (Node 22 + passwordless sudo live there; the WSL host has
neither Node nor sudo), logs in via `drush uli`, visits pages, screenshots
them and fails on HTTP/PHP/console errors.

All paths are relative to the repo root. Commands run on the host unless they
go through `ddev exec`.

## Prerequisites

`ddev` and `docker` on the host. Nothing else: PHP, Composer, Node and drush
come from the DDEV web container.

## Setup (cold start, no Tome export yet)

```bash
ddev start
ddev composer install
ddev drush site:install minimal -y
ddev drush pm:install -y tome layout_builder council_core
git checkout web/sites/default/settings.php   # site:install appended DB creds + hash_salt; settings.ddev.php already provides both
ddev drush cr
```

Once `config/` holds a Tome export, `ddev rebuild` replaces the
`site:install`/`pm:install` lines. It wipes the DB, then runs
`site:install --existing-config` + `tome:import` (Tome 1.x has no
`tome:install`, despite older docs). Without an export it stops with
`No Tome export in web/../config yet`. To rebuild from another export dir
(e.g. the spike's):

```bash
ddev exec 'NORTHVALE_SYNC_ROOT=../.spike DRUSH=drush bash scripts/rebuild.sh'
```

## Run (agent path)

```bash
# Every session (idempotent; ~15s after a ddev start/restart, instant otherwise):
ddev exec --dir /var/www/html/.claude/skills/run-northvale ./setup.sh

# Default smoke: login, /, /admin/content, /admin/modules (asserts "Council Core").
ddev exec --dir /var/www/html/.claude/skills/run-northvale node driver.mjs

# Your own pages; `path::Text` asserts the text is on the page; --anon skips login.
ddev exec --dir /var/www/html/.claude/skills/run-northvale node driver.mjs '/admin/content::Content' /admin/structure
ddev exec --dir /var/www/html/.claude/skills/run-northvale node driver.mjs --anon '/user/login::Log in'
```

Output is one line per page (`ok`/`FAIL`, status, path, title, screenshot).
Exit code is non-zero on any failure. Screenshots land in
`.claude/skills/run-northvale/shots/NN-<path-slug>.png` (gitignored, cleared each run). **Read
them.** The site is unstyled (minimal profile, Stark theme), and that's normal.

Quick server check without a browser:

```bash
curl -sk -o /dev/null -w '%{http_code}\n' https://northvale.ddev.site/user/login
ddev drush uli    # one-time admin login link for a human
```

## Direct invocation (PRs touching module internals)

Call code in the bootstrapped site without the browser:

```bash
ddev drush php:eval 'echo \Drupal::moduleHandler()->invoke("council_core", "help", ["help.page.council_core", \Drupal::routeMatch()]), PHP_EOL;'
```

After changing PHP (new hooks, services, routes) run `ddev drush cr` first.

## Static build (what gets deployed)

```bash
ddev build-static                                    # wipes and rewrites ./html
grep -o '<title>[^<]*' html/index.html               # -> "Log in | Drush Site-Install" on a fresh minimal install
```

## Test

```bash
ddev qa          # PHPCS + PHPStan (level 6) + PHPUnit; or: ddev qa lint|analyse|test
```

Passes with 1 PHPUnit test. PHPStan prints a harmless
`drupal_root parameter is deprecated` notice.

## Gotchas

- **`symfony/runtime` must be `true` in `allow-plugins`.** Drupal 11.3's
  `web/index.php` requires `vendor/autoload_runtime.php`, which that plugin
  generates. Setting it `false` lets `composer install` pass, but every page
  then 500s.
- **`ddev drush site:install` edits the committed `settings.php`** by
  appending a MariaDB block and a literal `hash_salt`. Always
  `git checkout web/sites/default/settings.php` afterwards.
- **`ddev restart` wipes Chromium and its apt libraries from the container.**
  That's why `setup.sh` installs the browser to `.browsers/` in the project
  mount (the driver sets `PLAYWRIGHT_BROWSERS_PATH` to that path itself) and
  reinstalls the system libraries when `libnspr4` is missing.
- **Inside the container, use `http://localhost`, not `https://northvale.ddev.site`.**
  The driver defaults to that; override with `BASE_URL=...`.
- **No logout link exists to prove login.** The minimal profile places no
  account menu. The driver checks `/admin` returns 200 instead.
- **`/` is the user page when logged in and the login form when anonymous.**
  The minimal profile has no front page or content types, so `/node/add`
  lists nothing and the static `html/index.html` is the login form.
- **The `council_core` help text isn't reachable in the UI.** The Help module
  isn't installed, so use the `php:eval` call above to exercise it.

## Troubleshooting

- **`composer install` fails with `symfony/runtime contains a Composer plugin which is blocked by your allow-plugins config`**:
  run `ddev composer config --no-plugins allow-plugins.symfony/runtime true`.
- **Every page shows `Failed opening required '.../vendor/autoload_runtime.php'`**:
  the plugin above is set to `false`. Set it to `true`, then run `ddev composer install`.
- **Driver dies with `chrome-headless-shell: error while loading shared libraries: libnspr4.so`**:
  the container was restarted. Run `setup.sh`.
