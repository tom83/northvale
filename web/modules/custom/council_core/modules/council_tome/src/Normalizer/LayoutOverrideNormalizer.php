<?php

declare(strict_types=1);

namespace Drupal\council_tome\Normalizer;

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\layout_builder\Field\LayoutSectionItemList;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\tome_sync\Normalizer\ContentEntityNormalizer;

/**
 * Exports and imports per-entity Layout Builder overrides with Tome Sync.
 *
 * Core forbids view access to the layout field for every user
 * (LayoutSectionItemList::defaultAccess(), see
 * https://www.drupal.org/node/2942975), and the serializer silently skips
 * fields the user can't view. Without this class Tome exports landing pages
 * with no layout at all.
 *
 * Inline blocks are referenced in the layout by block_content revision ID.
 * Tome doesn't keep entity or revision IDs, so on export each inline block is
 * referenced by UUID instead, and on import the UUID is resolved back to the
 * block's current revision. InlineBlockSyncSubscriber makes sure the blocks
 * are imported before the entity whose layout uses them.
 */
final class LayoutOverrideNormalizer extends ContentEntityNormalizer {

  /**
   * The layout override field name.
   */
  private const FIELD = OverridesSectionStorage::FIELD_NAME;

  /**
   * {@inheritdoc}
   */
  public function supportsNormalization($data, ?string $format = NULL, array $context = []): bool {
    return parent::supportsNormalization($data, $format, $context)
      && $data instanceof FieldableEntityInterface
      && $data->hasField(self::FIELD);
  }

  /**
   * {@inheritdoc}
   *
   * @param mixed $data
   *   The data to denormalize.
   * @param string $type
   *   The class to denormalize into.
   * @param string|null $format
   *   The format.
   * @param array<string, mixed> $context
   *   The serializer context.
   */
  public function supportsDenormalization(mixed $data, string $type, ?string $format = NULL, array $context = []): bool {
    return parent::supportsDenormalization($data, $type, $format, $context)
      && is_array($data)
      && array_key_exists(self::FIELD, $data);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedTypes(?string $format): array {
    // Not cacheable: support depends on whether the entity has the field.
    return [ContentEntityInterface::class => FALSE];
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The normalized entity, including its layout override.
   */
  public function normalize($entity, $format = NULL, array $context = []): array {
    $values = parent::normalize($entity, $format, $context);
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
    $items = $entity->get(self::FIELD);
    assert($items instanceof LayoutSectionItemList);
    $sections = [];
    foreach ($items->getSections() as $section) {
      $section = $section->toArray();
      foreach ($section['components'] as &$component) {
        $component['configuration'] = $this->exportInlineBlock($component['configuration']);
      }
      $sections[] = $section;
    }
    if ($sections) {
      $values[self::FIELD] = $sections;
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []): mixed {
    $sections = $data[self::FIELD];
    unset($data[self::FIELD]);
    $entity = parent::denormalize($data, $class, $format, $context);
    $items = [];
    foreach ($sections as $section) {
      foreach ($section['components'] as &$component) {
        $component['configuration'] = $this->importInlineBlock($component['configuration']);
      }
      $items[] = ['section' => Section::fromArray($section)];
    }
    $entity->set(self::FIELD, $items);
    return $entity;
  }

  /**
   * Replaces an inline block's revision ID with its UUID.
   *
   * @param array<string, mixed> $configuration
   *   A component's block plugin configuration.
   *
   * @return array<string, mixed>
   *   The configuration to export.
   */
  private function exportInlineBlock(array $configuration): array {
    if (!$this->isInlineBlock($configuration) || empty($configuration['block_revision_id'])) {
      return $configuration;
    }
    $block = $this->entityTypeManager->getStorage('block_content')
      ->loadRevision($configuration['block_revision_id']);
    if (!$block instanceof BlockContentInterface) {
      throw new \RuntimeException(sprintf('Inline block revision %s used in a layout does not exist.', $configuration['block_revision_id']));
    }
    unset($configuration['block_id'], $configuration['block_revision_id'], $configuration['block_serialized']);
    $configuration['block_uuid'] = $block->uuid();
    return $configuration;
  }

  /**
   * Replaces an inline block's UUID with its current revision ID.
   *
   * @param array<string, mixed> $configuration
   *   A component's block plugin configuration, as exported.
   *
   * @return array<string, mixed>
   *   The configuration Layout Builder expects.
   */
  private function importInlineBlock(array $configuration): array {
    if (!$this->isInlineBlock($configuration) || empty($configuration['block_uuid'])) {
      return $configuration;
    }
    $blocks = $this->entityTypeManager->getStorage('block_content')
      ->loadByProperties(['uuid' => $configuration['block_uuid']]);
    $block = reset($blocks);
    if (!$block instanceof BlockContentInterface) {
      // Fail loudly: saving the layout without the block would lose content.
      throw new \RuntimeException(sprintf('Inline block %s is not imported yet. Run "drush tome:export" to rebuild the content index, then import again.', $configuration['block_uuid']));
    }
    unset($configuration['block_uuid']);
    $configuration['block_id'] = $block->id();
    $configuration['block_revision_id'] = $block->getRevisionId();
    $configuration['block_serialized'] = NULL;
    return $configuration;
  }

  /**
   * Checks whether a component's configuration is for an inline block.
   *
   * @param array<string, mixed> $configuration
   *   A component's block plugin configuration.
   */
  private function isInlineBlock(array $configuration): bool {
    return str_starts_with((string) ($configuration['id'] ?? ''), 'inline_block:');
  }

}
