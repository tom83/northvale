<?php

declare(strict_types=1);

namespace Drupal\council_core\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for Council Core.
 *
 * Uses the attribute-based OOP hooks introduced in Drupal 11.1, so no
 * procedural .module file is needed.
 */
final class CouncilCoreHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'help.page.council_core') {
      return NULL;
    }
    return '<p>' . $this->t('Site-specific functionality for the Northvale Council website: content model helpers, Layout Builder plugins and static-export integrations.') . '</p>';
  }

}
