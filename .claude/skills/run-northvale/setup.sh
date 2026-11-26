#!/usr/bin/env bash
# Idempotent: makes the headless browser driver runnable inside the DDEV web
# container. Safe to run every time; it skips whatever is already in place.
#
# Run from the repo root on the host:
#   ddev exec --dir /var/www/html/.claude/skills/run-northvale ./setup.sh
#
# Why each step is needed:
# - node_modules/ and .browsers/ live in the project mount, so they survive
#   `ddev restart`. (Playwright's default ~/.cache/ms-playwright does not.)
# - Chromium's system libraries (libnspr4, libnss3, ...) are apt packages in
#   the container image and are wiped on every `ddev restart`/`ddev start`,
#   so they get reinstalled here when missing (~15s).
set -euo pipefail
cd "$(dirname "$0")"
export PLAYWRIGHT_BROWSERS_PATH="$PWD/.browsers"

[ -d node_modules/playwright ] || npm install --no-fund --no-audit
[ -d .browsers ] && ls .browsers | grep -q chromium_headless_shell || npx playwright install chromium

if ! ldconfig -p | grep -q libnspr4.so; then
  echo "Installing Chromium system libraries (wiped by ddev restart)..."
  sudo env PATH="$PATH" npx playwright install-deps chromium >/tmp/pw-deps.log 2>&1 \
    || { tail -20 /tmp/pw-deps.log; exit 1; }
fi
echo "Browser driver ready."
