#!/usr/bin/env bash
# Builds the full Drupal backend inside a Codespace, from the Tome export.
set -euo pipefail

ddev start
ddev composer install --no-interaction

if [ -f config/core.extension.yml ]; then
  ddev drush tome:install -y
  echo
  echo "Site ready. Get a one-time admin login link with:  ddev drush uli"
else
  echo "No Tome export yet. Install a site with:  ddev drush site:install minimal -y"
fi
