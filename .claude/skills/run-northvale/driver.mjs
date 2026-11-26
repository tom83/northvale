// Headless browser driver for the Northvale DDEV site.
//
// Runs INSIDE the DDEV web container (that's where Node, Chromium and drush
// live). From the repo root on the host:
//
//   ddev exec --dir /var/www/html/.claude/skills/run-northvale node driver.mjs [--anon] [path[::Expected text] ...]
//
// - Logs in as uid 1 via a fresh `drush uli` link (skip with --anon).
// - Visits each path (defaults below), screenshots it to shots/<n>-<slug>.png
//   (visible on the host under the skill dir).
// - `path::Text` also asserts the page body contains Text.
// - Exits non-zero if any page returns >= 400, shows Drupal's error page,
//   prints a PHP warning/fatal, misses its expected text, or logs a browser
//   console error.

import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Inside the container nginx listens on :80; the https://*.ddev.site router
// lives on the host and is not the right target from here.
const BASE = process.env.BASE_URL || 'http://localhost';
const here = dirname(fileURLToPath(import.meta.url));
// setup.sh installs Chromium here (in the project mount) so it survives
// `ddev restart`; must be set before Playwright is loaded.
process.env.PLAYWRIGHT_BROWSERS_PATH ||= join(here, '.browsers');
const { chromium } = await import('playwright');
const shots = join(here, 'shots');
rmSync(shots, { recursive: true, force: true }); // only this run's shots
mkdirSync(shots, { recursive: true });

const args = process.argv.slice(2);
const anon = args.includes('--anon');
const paths = args.filter((a) => a !== '--anon');
if (!paths.length) paths.push('/', '/admin/content::Content', '/admin/modules::Council Core');

const ERROR_MARKERS = [
  'The website encountered an unexpected error',
  '<b>Warning</b>:',
  '<b>Fatal error</b>:',
  '<b>Deprecated</b>:',
];

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const consoleErrors = [];
page.on('console', (m) => m.type() === 'error' && consoleErrors.push(m.text()));
page.on('pageerror', (e) => consoleErrors.push(String(e)));

let failed = false;

if (!anon) {
  const uli = execFileSync('drush', ['uli', `--uri=${BASE}`, '--no-browser'], {
    cwd: '/var/www/html',
    encoding: 'utf8',
  }).trim();
  await page.goto(uli);
  // The minimal profile places no account menu, so there's no logout link to
  // look for. Being allowed into /admin is the proof of login.
  const admin = await page.goto(BASE + '/admin');
  if (admin?.status() !== 200) {
    console.log(`FAIL login: /admin returned ${admin?.status()} after ${uli}`);
    failed = true;
  } else {
    console.log('ok   login as uid 1 (/admin -> 200)');
  }
}

for (const [i, spec] of paths.entries()) {
  const [path, expect = null] = spec.split('::');
  const res = await page.goto(BASE + path, { waitUntil: 'networkidle' });
  const status = res?.status() ?? 0;
  const html = await page.content();
  const title = await page.title();
  const marker =
    ERROR_MARKERS.find((m) => html.includes(m)) ||
    (expect && !(await page.locator('body').innerText()).includes(expect) ? `missing text: ${expect}` : null);
  const slug = path.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'front';
  const file = join(shots, `${String(i + 1).padStart(2, '0')}-${slug}.png`);
  await page.screenshot({ path: file, fullPage: true });
  const bad = status >= 400 || marker;
  if (bad) failed = true;
  console.log(
    `${bad ? 'FAIL' : 'ok  '} ${status} ${path}  "${title}"${marker ? `  [${marker}]` : ''}  -> shots/${file.split('/shots/')[1]}`,
  );
}

if (consoleErrors.length) {
  failed = true;
  console.log('FAIL console errors:\n  ' + consoleErrors.join('\n  '));
}

await browser.close();
process.exit(failed ? 1 : 0);
