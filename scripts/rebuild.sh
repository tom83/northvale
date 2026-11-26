#!/usr/bin/env bash
#
# Rebuilds the database from the Tome export in config/, content/ and files/.
# WIPES the current database. Run from the project root:
#
#   ddev rebuild                    (local / Codespaces)
#   bash scripts/rebuild.sh         (CI / plain PHP)
#
# Tome 1.x has no tome:install command (older docs mention one). The rebuild
# is a fresh install from the exported config, so the same modules are enabled
# and the site UUID matches, then tome:import for content and files.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DRUSH="${DRUSH:-vendor/bin/drush}"
# Same override as settings.php, relative to web/.
CONFIG_DIR="web/${NORTHVALE_SYNC_ROOT:-..}/config"

if [ ! -f "$CONFIG_DIR/core.extension.yml" ]; then
  echo "No Tome export in $CONFIG_DIR yet. Install a site first (see README), then run: drush tome:export -y" >&2
  exit 1
fi

mkdir -p "$(dirname "${DRUPAL_SQLITE_PATH:-$ROOT/.sqlite/site.sqlite}")"

$DRUSH site:install --existing-config -y
$DRUSH tome:import -y
echo "Rebuilt the site from $CONFIG_DIR."
