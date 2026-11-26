#!/usr/bin/env bash
#
# Phase 0 spike: does Tome round-trip and statically export the things this
# project relies on? Run from the project root:
#
#   ddev exec bash scripts/spike/run.sh       (local, WIPES the DDEV database)
#   bash scripts/spike/run.sh                 (CI / plain PHP with SQLite)
#
# Everything Tome writes goes to ./.spike, never to ./config or ./content.
#
# Checks:
#   1. A Layout Builder inline block survives tome:export -> wipe -> tome:import.
#   2. Aggregated CSS and JS are written to the static output.
#   3. Image style derivatives are written to the static output.
#   4. How the front page pager is exported (reported, not pass/fail).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

DRUSH="${DRUSH:-vendor/bin/drush}"
URI="${SITE_URL:-https://northvale.example}"
export NORTHVALE_SYNC_ROOT="../.spike"
export SPIKE_MARKER="SPIKE-INLINE-BLOCK-7f3a"
SPIKE_DIR="$ROOT/.spike"

step() { printf '\n==> %s\n' "$*"; }

step "Resetting $SPIKE_DIR"
rm -rf "$SPIKE_DIR"
mkdir -p "$SPIKE_DIR"/{config,content,files,html}
[ -z "${IS_DDEV_PROJECT:-}" ] && mkdir -p "$(dirname "${DRUPAL_SQLITE_PATH:-$ROOT/.sqlite/site.sqlite}")"

step "Installing a fresh site (minimal profile)"
$DRUSH site:install minimal -y --site-name="Northvale Council (spike)" --account-name=admin

step "Enabling modules and theme"
$DRUSH pm:install -y node views text image file block_content layout_builder layout_discovery path tome council_tome
$DRUSH theme:install -y olivero
$DRUSH config:set -y system.theme default olivero
$DRUSH config:set -y system.site page.front /node
$DRUSH config:set -y system.performance css.preprocess 1
$DRUSH config:set -y system.performance js.preprocess 1
$DRUSH role:perm:add anonymous 'access content'

step "Creating spike content"
$DRUSH php:script "$ROOT/scripts/spike/create-content.php"

step "Exporting with Tome Sync"
$DRUSH tome:export -y
echo "Exported $(find "$SPIKE_DIR/content" -type f | wc -l) content files, $(find "$SPIKE_DIR/config" -type f | wc -l) config files."
echo "Block content JSON files:"; ls "$SPIKE_DIR/content" | grep '^block_content' || echo "  (none!)"

# Tome 1.x has no tome:install (despite older docs). Rebuild = a fresh install
# from the exported config (so tome and the other modules are enabled and the
# site UUID matches), then tome:import for content and files. Delete the
# spike's uploaded files too, so the image has to come back from the export.
step "Wiping the site and rebuilding it from the export (site:install --existing-config + tome:import)"
rm -rf "$ROOT/web/sites/default/files/spike"
$DRUSH site:install --existing-config -y --account-name=admin
$DRUSH tome:import -y

step "Generating static HTML"
$DRUSH tome:static -y --uri="$URI"

HTML="$SPIKE_DIR/html"
FAILURES=0
pass() { printf '  PASS  %s\n' "$*"; }
fail() { printf '  FAIL  %s\n' "$*"; FAILURES=$((FAILURES + 1)); }

step "Results"
if grep -rlq "$SPIKE_MARKER" "$HTML"; then
  pass "Inline block content survived the round trip ($(grep -rl "$SPIKE_MARKER" "$HTML" | head -1))"
else
  fail "Inline block marker '$SPIKE_MARKER' not found in static output"
fi

css_count=$(find "$HTML" -path '*/files/css/*' -name '*.css' | wc -l)
js_count=$(find "$HTML" -path '*/files/js/*' -name '*.js' | wc -l)
[ "$css_count" -gt 0 ] && pass "$css_count aggregated CSS file(s)" || fail "No aggregated CSS in output"
[ "$js_count" -gt 0 ] && pass "$js_count aggregated JS file(s)" || fail "No aggregated JS in output"

style_count=$(find "$HTML" -path '*/styles/medium/*' -type f | wc -l)
[ "$style_count" -gt 0 ] && pass "$style_count image style derivative(s)" || fail "No image style derivatives in output"

echo "  INFO  Pager links on the front page:"
# Tome rewrites ?page=N links to /page/N+1 paths, so look for both.
grep -oE 'href="[^"]*(page=|/page/)[^"]*"' "$HTML/index.html" | sort -u | sed 's/^/          /' || echo "          (none found)"
echo "  INFO  Exported files whose path mentions 'page':"
find "$HTML" -path '*page*' -name '*.html' | sed "s|$HTML|          |" | head -10

echo
if [ "$FAILURES" -eq 0 ]; then
  echo "Spike passed. Inspect $HTML to review the output by eye."
else
  echo "Spike found $FAILURES problem(s). Record them in docs/adr/ before Phase 2."
  exit 1
fi
