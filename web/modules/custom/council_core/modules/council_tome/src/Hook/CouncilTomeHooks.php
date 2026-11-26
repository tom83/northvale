<?php

declare(strict_types=1);

namespace Drupal\council_tome\Hook;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\layout_builder\InlineBlockEntityOperations;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;

/**
 * Hook implementations for Council Tome.
 */
final class CouncilTomeHooks {

  public function __construct(
    private readonly ClassResolverInterface $classResolver,
  ) {}

  /**
   * Implements hook_entity_delete().
   *
   * When an entity with a layout is deleted, Layout Builder keeps its inline
   * blocks and sets their usage records' layout entity to NULL, leaving cron
   * to delete them later. Until then, exporting one of those blocks with Tome
   * (any full "drush tome:export") crashes in Layout Builder's access check
   * with 'The "" entity type does not exist'. Delete them straight away, which
   * also removes their files from the Tome content export.
   */
  #[Hook('entity_delete', order: new OrderAfter(modules: ['layout_builder']))]
  public function entityDelete(EntityInterface $entity): void {
    // Only entities with layout overrides own inline blocks. This also stops
    // the hook re-running for each block it deletes.
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField(OverridesSectionStorage::FIELD_NAME)) {
      return;
    }
    /** @var \Drupal\layout_builder\InlineBlockEntityOperations $operations */
    $operations = $this->classResolver->getInstanceFromDefinition(InlineBlockEntityOperations::class);
    // Same clean-up Layout Builder's cron runs; the limit is per batch.
    $operations->removeUnused(1000);
  }

}
