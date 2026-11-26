<?php

/**
 * @file
 * Drupal settings for the Northvale Council portfolio site.
 *
 * The database is disposable: everything that matters (config, content and
 * files) is exported by Tome Sync to ../config, ../content and ../files and
 * committed to git. scripts/rebuild.sh (`ddev rebuild`) rebuilds the database
 * from those.
 *
 * SQLite everywhere (DDEV, Codespaces, GitHub Actions), so a Tome export made
 * in one environment installs in any other. See docs/adr/0002.
 */

// Paths are relative to the Drupal root (web/). NORTHVALE_SYNC_ROOT lets
// throwaway runs (e.g. scripts/spike) use a scratch directory instead.
$sync_root = getenv('NORTHVALE_SYNC_ROOT') ?: '..';
$settings['config_sync_directory'] = $sync_root . '/config';
$settings['tome_content_directory'] = $sync_root . '/content';
$settings['tome_files_directory'] = $sync_root . '/files';
$settings['tome_static_directory'] = $sync_root . '/html';

// Supplied by the environment in CI. DDEV generates its own if empty.
$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: '';

$settings['update_free_access'] = FALSE;
$settings['file_scan_ignore_directories'] = ['node_modules', 'bower_components'];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['migrate_node_migrate_type_classic'] = FALSE;

if (file_exists($app_root . '/' . $site_path . '/services.yml')) {
  $settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
}

// DDEV's generated settings provide the hash salt, trusted hosts and so on.
// Its MariaDB connection is replaced below.
if (getenv('IS_DDEV_PROJECT') === 'true' && file_exists(__DIR__ . '/settings.ddev.php')) {
  include __DIR__ . '/settings.ddev.php';
}

// SQLite everywhere: Drupal records the database driver as an enabled module
// in core.extension.yml, so an export made on MariaDB can't be installed on
// SQLite (or the reverse). See docs/adr/0002. DRUPAL_SQLITE_PATH lets CI put
// the database anywhere; the parent directory must exist before installing.
$databases['default']['default'] = [
  'driver' => 'sqlite',
  'database' => getenv('DRUPAL_SQLITE_PATH') ?: $app_root . '/../.sqlite/site.sqlite',
  'prefix' => '',
  'namespace' => 'Drupal\\sqlite\\Driver\\Database\\sqlite',
  'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/',
];

// Machine-specific overrides (never committed).
if (file_exists(__DIR__ . '/settings.local.php')) {
  include __DIR__ . '/settings.local.php';
}
