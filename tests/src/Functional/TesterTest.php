<?php

namespace Drupal\Tests\tester\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Basic functionality of the Testing module.
 *
 * @group simpletest
 */
class TesterTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['simpletest'];

  /**
   * Test that we can uninstall the module without mishap.
   *
   * Upon uninstall, simpletest will clean up after itself. This should neither
   * break the test runner's expectations, nor cause any kind of exception.
   *
   * Note that this might break run-tests.sh test runs that don't use the
   * --sqlite argument.
   */
  public function testUninstallModule() {
    /* @var $installer \Drupal\Core\Extension\ModuleInstallerInterface */
    $installer = $this->container->get('module_installer');
    $this->assertTrue($installer->uninstall(['simpletest']));
  }

}
