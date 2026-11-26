<?php

declare(strict_types=1);

namespace Drupal\council_tome\EventSubscriber;

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\layout_builder\Field\LayoutSectionItemList;
use Drupal\layout_builder\InlineBlockUsageInterface;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\tome_sync\ContentIndexerTrait;
use Drupal\tome_sync\Event\ContentCrudEvent;
use Drupal\tome_sync\Event\TomeSyncEvents;
use Drupal\tome_sync\TomeSyncHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps inline blocks consistent across a Tome export and import.
 *
 * - Export: Tome orders imports by the dependencies in content/meta/index.json,
 *   which it only collects from entity reference fields. Layouts reference
 *   inline blocks by revision ID inside the layout field, so this adds each
 *   inline block as a dependency of the entity that uses it. That guarantees
 *   LayoutOverrideNormalizer can resolve the block when the entity imports.
 * - Import: Layout Builder records inline block usage when the parent entity
 *   is saved, but only for blocks it saves itself. Imported blocks already
 *   exist, so their usage is recorded here instead. Layout Builder relies on
 *   it to clean up unused blocks.
 */
final class InlineBlockSyncSubscriber implements EventSubscriberInterface {

  // Tome's own index reader/writer. It's marked @internal, so this couples to
  // Tome's index format; the Tome version is pinned in composer.lock.
  use ContentIndexerTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly InlineBlockUsageInterface $usage,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      TomeSyncEvents::EXPORT_CONTENT => 'addInlineBlockDependencies',
      TomeSyncEvents::IMPORT_CONTENT => 'recordInlineBlockUsage',
    ];
  }

  /**
   * Adds an exported entity's inline blocks to its index dependencies.
   */
  public function addInlineBlockDependencies(ContentCrudEvent $event): void {
    $blocks = $this->getInlineBlocks($event->getContent());
    if (!$blocks) {
      return;
    }
    $name = TomeSyncHelper::getContentName($event->getContent());
    $handle = $this->acquireContentIndexLock();
    $index = json_decode((string) stream_get_contents($handle), TRUE) ?: [];
    $dependencies = $index[$name] ?? [];
    foreach ($blocks as $block) {
      $dependencies[] = TomeSyncHelper::getContentName($block);
    }
    $index[$name] = array_values(array_unique($dependencies));
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($index, JSON_PRETTY_PRINT));
    flock($handle, LOCK_UN);
    fclose($handle);
  }

  /**
   * Records usage for an imported entity's inline blocks.
   */
  public function recordInlineBlockUsage(ContentCrudEvent $event): void {
    $entity = $event->getContent();
    foreach ($this->getInlineBlocks($entity) as $block) {
      if (!$this->usage->getUsage((int) $block->id())) {
        $this->usage->addUsage($block->id(), $entity);
      }
    }
  }

  /**
   * Loads the inline blocks used in an entity's layout override.
   *
   * @return \Drupal\block_content\BlockContentInterface[]
   *   The blocks, at the revisions the layout uses.
   */
  private function getInlineBlocks(mixed $entity): array {
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField(OverridesSectionStorage::FIELD_NAME)) {
      return [];
    }
    $items = $entity->get(OverridesSectionStorage::FIELD_NAME);
    assert($items instanceof LayoutSectionItemList);
    $storage = $this->entityTypeManager->getStorage('block_content');
    $blocks = [];
    foreach ($items->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $configuration = $component->get('configuration');
        if (!str_starts_with((string) ($configuration['id'] ?? ''), 'inline_block:') || empty($configuration['block_revision_id'])) {
          continue;
        }
        $block = $storage->loadRevision($configuration['block_revision_id']);
        if ($block instanceof BlockContentInterface) {
          $blocks[] = $block;
        }
      }
    }
    return $blocks;
  }

}
