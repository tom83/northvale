<?php

declare(strict_types=1);

namespace Drupal\council_tome\Normalizer;

use Drupal\tome_sync\Normalizer\UserEntityNormalizer;
use Drupal\user\UserInterface;

/**
 * Keeps per-login timestamps out of Tome's user exports.
 *
 * Tome 1.16's UserEntityNormalizer strips access, login and init, but on
 * Drupal 11 it never runs: Tome's PathAliasNormalizer has a higher priority
 * and its getSupportedTypes() claims every content entity, so it handles
 * users too. As a result, every one-time login link re-saved the admin user
 * and changed content/ in git.
 *
 * This normalizer claims users only, at a priority above Tome's, and also
 * drops "changed", which a login updates as well.
 */
final class UserNormalizer extends UserEntityNormalizer {

  /**
   * {@inheritdoc}
   */
  public function getSupportedTypes(?string $format): array {
    return [UserInterface::class => TRUE];
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The normalized user, without per-login timestamps.
   */
  public function normalize($entity, $format = NULL, array $context = []): array {
    $values = parent::normalize($entity, $format, $context);
    unset($values['changed']);
    return $values;
  }

}
