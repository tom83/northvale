<?php

declare(strict_types=1);

namespace Drupal\Tests\council_tome\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Field\LayoutSectionItemList;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\tome_sync\TomeSyncHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that Layout Builder overrides with inline blocks survive Tome Sync.
 */
#[Group('council_tome')]
#[RunTestsInSeparateProcesses]
final class LayoutOverrideSyncTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'file',
    'serialization',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'tome_base',
    'tome_sync',
    'council_tome',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Tome exports on every entity save: keep it inside the test site.
    $settings = Settings::getAll();
    $settings['tome_content_directory'] = $this->siteDirectory . '/tome/content';
    $settings['tome_files_directory'] = $this->siteDirectory . '/tome/files';
    new Settings($settings);

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installSchema('tome_sync', ['tome_sync_content_hash']);
    $this->installConfig(['system', 'node']);

    NodeType::create(['type' => 'landing_page', 'name' => 'Landing page'])->save();
    BlockContentType::create(['id' => 'text', 'label' => 'Text', 'revision' => TRUE])->save();
    $display = $this->container->get('entity_display.repository')
      ->getViewDisplay('node', 'landing_page');
    $this->assertInstanceOf(LayoutBuilderEntityViewDisplay::class, $display);
    $display->enableLayoutBuilder()->setOverridable()->save();
  }

  /**
   * The export contains the layout, referencing inline blocks by UUID.
   */
  public function testExport(): void {
    [$page, $block] = $this->createLandingPage();

    $data = $this->container->get('serializer')->normalize($page, 'json');
    $configuration = $this->getFirstComponentConfiguration($data);

    $this->assertSame('inline_block:text', $configuration['id']);
    $this->assertSame($block->uuid(), $configuration['block_uuid']);
    $this->assertArrayNotHasKey('block_id', $configuration);
    $this->assertArrayNotHasKey('block_revision_id', $configuration);
    $this->assertArrayNotHasKey('block_serialized', $configuration);

    // Tome only orders imports by entity reference fields; the subscriber
    // adds the inline block so it is imported before the page.
    $index = json_decode((string) file_get_contents($this->siteDirectory . '/tome/content/meta/index.json'), TRUE);
    $this->assertContains(TomeSyncHelper::getContentName($block), $index[TomeSyncHelper::getContentName($page)]);
  }

  /**
   * Import resolves the block's current revision and records its usage.
   */
  public function testImport(): void {
    [$page, $block] = $this->createLandingPage();
    $data = $this->container->get('serializer')->normalize($page, 'json');

    // Simulate a rebuilt site, where the block exists but:
    // - its revision IDs differ (move the current revision on, so the
    //   exported revision ID would point at the wrong one), and
    // - it has no usage record yet.
    $block->setNewRevision();
    $block->save();
    $this->container->get('inline_block.usage')->deleteUsage([$block->id()]);

    // Import the export as a page that doesn't exist yet.
    $uuid = $this->container->get('uuid')->generate();
    $data['uuid'][0]['value'] = $uuid;
    $this->container->get('tome_sync.storage.content')
      ->write(TomeSyncHelper::getContentNameFromParts('node', $uuid), $data);
    $this->container->get('tome_sync.importer')->importContent('node', $uuid);

    $imported = $this->container->get('entity.repository')->loadEntityByUuid('node', $uuid);
    $this->assertInstanceOf(NodeInterface::class, $imported);
    $this->assertNotEquals($page->id(), $imported->id());
    $components = $this->getLayout($imported)->getSection(0)->getComponents();
    $configuration = reset($components)->get('configuration');
    $this->assertEquals($block->id(), $configuration['block_id']);
    $this->assertEquals($block->getRevisionId(), $configuration['block_revision_id']);

    $usage = $this->container->get('inline_block.usage')->getUsage((int) $block->id());
    $this->assertIsObject($usage);
    $this->assertEquals('node', $usage->layout_entity_type);
    $this->assertEquals($imported->id(), $usage->layout_entity_id);
  }

  /**
   * Deleting a page deletes its inline blocks and their exports at once.
   *
   * Core leaves them for cron with a NULL usage record, and exporting such a
   * block crashes Layout Builder's access check.
   */
  public function testDeletingPageDeletesInlineBlocks(): void {
    [$page, $block] = $this->createLandingPage();
    $export = $this->siteDirectory . '/tome/content/' . TomeSyncHelper::getContentName($block) . '.json';
    $this->assertFileExists($export);

    $page->delete();

    $this->assertNull(BlockContent::load($block->id()));
    $this->assertFalse($this->container->get('inline_block.usage')->getUsage((int) $block->id()));
    $this->assertFileDoesNotExist($export);
  }

  /**
   * Importing a layout whose block is missing fails instead of losing it.
   */
  public function testImportWithMissingBlockFails(): void {
    [$page, $block] = $this->createLandingPage();
    $data = $this->container->get('serializer')->normalize($page, 'json');
    $block->delete();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage($block->uuid());
    $this->container->get('serializer')->denormalize($data, Node::class, 'json');
  }

  /**
   * Creates a landing page with an inline block, as the Layout Builder UI does.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: \Drupal\block_content\BlockContentInterface}
   *   The page and its inline block.
   */
  private function createLandingPage(): array {
    $page = Node::create(['type' => 'landing_page', 'title' => 'Bins and recycling']);
    // The Layout tab only exists once the node is saved; adding the override
    // to an unsaved node records inline block usage against a NULL ID.
    $page->save();
    $block = BlockContent::create(['type' => 'text', 'info' => 'Bin day', 'reusable' => FALSE]);
    $component = new SectionComponent($this->container->get('uuid')->generate(), 'content', [
      'id' => 'inline_block:text',
      'label' => 'Bin day',
      'label_display' => '0',
      'provider' => 'layout_builder',
      'view_mode' => 'full',
      'block_serialized' => serialize($block),
    ]);
    $this->getLayout($page)->appendSection(new Section('layout_onecol', [], [$component]));
    $page->save();

    $configuration = $this->getLayout($page)->getSection(0)->getComponent($component->getUuid())->get('configuration');
    $block = BlockContent::load($configuration['block_id']);
    $this->assertInstanceOf(BlockContent::class, $block);
    return [$page, $block];
  }

  /**
   * Gets a node's layout override field.
   */
  private function getLayout(NodeInterface $node): LayoutSectionItemList {
    $items = $node->get(OverridesSectionStorage::FIELD_NAME);
    $this->assertInstanceOf(LayoutSectionItemList::class, $items);
    return $items;
  }

  /**
   * Gets the first component's configuration from normalized entity data.
   *
   * @param array<string, mixed> $data
   *   The normalized entity.
   *
   * @return array<string, mixed>
   *   The component's block plugin configuration.
   */
  private function getFirstComponentConfiguration(array $data): array {
    $this->assertArrayHasKey(OverridesSectionStorage::FIELD_NAME, $data);
    $components = $data[OverridesSectionStorage::FIELD_NAME][0]['components'];
    return reset($components)['configuration'];
  }

}
