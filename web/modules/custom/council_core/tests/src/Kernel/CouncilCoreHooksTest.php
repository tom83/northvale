<?php

declare(strict_types=1);

namespace Drupal\Tests\council_core\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that Council Core's OOP hooks are discovered and invoked.
 */
#[Group('council_core')]
#[RunTestsInSeparateProcesses]
final class CouncilCoreHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'help', 'council_core'];

  /**
   * The help hook returns text for the module's own help page only.
   */
  public function testHelpHook(): void {
    $module_handler = $this->container->get('module_handler');
    $route_match = $this->container->get('current_route_match');

    $help = $module_handler->invoke('council_core', 'help', ['help.page.council_core', $route_match]);
    self::assertIsString($help);
    self::assertStringContainsString('Northvale Council', $help);

    $other = $module_handler->invoke('council_core', 'help', ['help.page.node', $route_match]);
    self::assertNull($other);
  }

}
