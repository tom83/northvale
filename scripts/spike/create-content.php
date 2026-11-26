<?php

/**
 * @file
 * Phase 0 spike: builds the content that exercises Tome's known risk areas.
 *
 * Run with: drush php:script scripts/spike/create-content.php
 *
 * Creates:
 * - A "page" type with per-node Layout Builder overrides, and one page whose
 *   layout contains a non-reusable inline block holding a unique marker.
 * - An "article" type with an image field rendered through an image style.
 * - 12 promoted articles, so the /node front page needs a pager.
 */

declare(strict_types=1);

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

$marker = getenv('SPIKE_MARKER') ?: 'SPIKE-INLINE-BLOCK-7f3a';
$displays = \Drupal::service('entity_display.repository');

/**
 * Adds a field to a bundle and shows it on the default view display.
 */
$add_field = static function (string $entity_type, string $bundle, string $name, string $type, array $display_options = []) use ($displays): void {
  if (!FieldStorageConfig::loadByName($entity_type, $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'type' => $type,
    ])->save();
  }
  if (!FieldConfig::loadByName($entity_type, $bundle, $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => ucfirst(str_replace(['field_', '_'], ['', ' '], $name)),
    ])->save();
  }
  $displays->getViewDisplay($entity_type, $bundle, 'default')
    ->setComponent($name, ['label' => 'hidden'] + $display_options)
    ->save();
};

// Content types.
foreach (['page' => 'Landing page', 'article' => 'News'] as $id => $label) {
  if (!NodeType::load($id)) {
    NodeType::create(['type' => $id, 'name' => $label])->save();
  }
}

// Inline block type.
if (!BlockContentType::load('spike_text')) {
  BlockContentType::create([
    'id' => 'spike_text',
    'label' => 'Spike text',
    'revision' => TRUE,
  ])->save();
}
$add_field('block_content', 'spike_text', 'field_text', 'text_long');

// Image field rendered through the "medium" image style.
$add_field('node', 'article', 'field_image', 'image', [
  'type' => 'image',
  'settings' => ['image_style' => 'medium'],
]);

// Per-node Layout Builder overrides for landing pages.
$displays->getViewDisplay('node', 'page', 'default')
  ->enableLayoutBuilder()
  ->setOverridable()
  ->save();

// A landing page with an inline (non-reusable) block. Passing the block as
// block_serialized mirrors what the Layout Builder UI does: Layout Builder
// saves the block and records its usage when the node is saved.
$block = BlockContent::create([
  'type' => 'spike_text',
  'info' => 'Spike inline block',
  'reusable' => FALSE,
  'field_text' => ['value' => $marker, 'format' => 'plain_text'],
]);
$component = new SectionComponent(\Drupal::service('uuid')->generate(), 'content', [
  'id' => 'inline_block:spike_text',
  'label' => 'Spike inline block',
  'label_display' => '0',
  'provider' => 'layout_builder',
  'view_mode' => 'full',
  'block_serialized' => serialize($block),
  'context_mapping' => [],
]);
$page = Node::create([
  'type' => 'page',
  'title' => 'Spike landing page',
  'status' => 1,
  'uid' => 1,
  'path' => ['alias' => '/spike-landing-page'],
]);
$page->get(OverridesSectionStorage::FIELD_NAME)->appendSection(new Section('layout_onecol', [], [$component]));
$page->save();

// A generated image, so there is no binary fixture to commit.
$file_system = \Drupal::service('file_system');
$directory = 'public://spike';
$file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
$image = imagecreatetruecolor(1200, 675);
imagefill($image, 0, 0, imagecolorallocate($image, 0, 94, 165));
ob_start();
imagepng($image);
$file = \Drupal::service('file.repository')->writeData((string) ob_get_clean(), "$directory/spike-image.png", FileExists::Replace);

// Enough promoted articles to make the front page paginate (10 per page).
for ($i = 1; $i <= 12; $i++) {
  $values = [
    'type' => 'article',
    'title' => "Spike news item $i",
    'status' => 1,
    'promote' => 1,
    'uid' => 1,
    'created' => \Drupal::time()->getRequestTime() - ($i * 3600),
  ];
  if ($i === 1) {
    $values['field_image'] = ['target_id' => $file->id(), 'alt' => 'Spike image'];
  }
  Node::create($values)->save();
}

echo "Created landing page {$page->id()} with inline block marker '$marker', 12 articles and 1 image.\n";
