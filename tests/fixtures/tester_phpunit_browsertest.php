<?php

namespace Drupal\Tests\tester\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * A PHPUnit-based browser test that will be run from Simpletest.
 *
 * To avoid accidentally running it is not in a normal PSR-4 directory.
 *
 * @group simpletest
 */
class TesterPhpunitBrowserTest extends BrowserTestBase {

  /**
   * Dummy test that logs the visited front page for HTML output.
   */
  public function testOutput() {
    $this->drupalGet('<front>');
    $this->assertSession()->responseContains('<h2>TEST escaping</h2>');
  }

}
