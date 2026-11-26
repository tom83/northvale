<?php

declare(strict_types=1);

namespace Drupal\Tests\council_tome\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that user exports don't change when someone logs in.
 */
#[Group('council_tome')]
#[RunTestsInSeparateProcesses]
final class UserExportTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'serialization',
    'tome_base',
    'tome_sync',
    'council_tome',
    'layout_builder',
    'layout_discovery',
    'block_content',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Tome exports on every entity save: keep it inside the test site, never
    // the repo's content/ directory.
    $settings = Settings::getAll();
    $settings['tome_content_directory'] = $this->siteDirectory . '/tome/content';
    $settings['tome_files_directory'] = $this->siteDirectory . '/tome/files';
    new Settings($settings);

    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');
    $this->installSchema('tome_sync', ['tome_sync_content_hash']);
  }

  /**
   * Login timestamps are left out; the user ID is kept.
   */
  public function testLoginFieldsNotExported(): void {
    // Tome exports as the admin user, who can see these fields.
    $this->setUpCurrentUser(['uid' => 1]);
    $user = $this->createUser([], 'editor');
    $user->setLastLoginTime(1_800_000_000)->setLastAccessTime(1_800_000_000)->save();

    $data = $this->container->get('serializer')->normalize($user, 'json');

    $this->assertSame((int) $user->id(), (int) $data['uid'][0]['value']);
    foreach (['access', 'login', 'init', 'changed'] as $field) {
      $this->assertArrayNotHasKey($field, $data);
    }
  }

}
