<?php

namespace Drupal\tester;

use Doctrine\Common\Reflection\StaticReflectionParser;
use Drupal\Component\Annotation\Reflection\MockFileFinder;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Test\Exception\MissingGroupException;
use Drupal\Core\Test\TestDiscovery as CoreTestDiscovery;

/**
 * Discovers available tests.
 */
class TestDiscovery extends CoreTestDiscovery {

  /**
   * @todo
   */
  protected $execManager;

  /**
   * The cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * @todo
   */
  public function __construct(string $root, $class_loader, ExecManager $exec_manager, CacheBackendInterface $cache_default) {
    parent::__construct($root, $class_loader);
    $this->execManager = $exec_manager;
    $this->cache = $cache_default;
  }

  /**
   * Discovers all available tests.
   *
   * @return array
   *   An array of tests keyed by the the group name. If a test is annotated to
   *   belong to multiple groups, it will appear under all group keys it belongs
   *   to.
   * @code
   *     $groups['block'] => array(
   *       'Drupal\Tests\block\Functional\BlockTest' => array(
   *         'name' => 'Drupal\Tests\block\Functional\BlockTest',
   *         'description' => 'Tests block UI CRUD functionality.',
   *         'group' => 'block',
   *         'groups' => ['block', 'group2', 'group3'],
   *       ),
   *     );
   * @endcode
   */
  public function getTestClasses($extension = NULL, array $types = []) {
    if ($this->testClasses) {
      return $this->testClasses;
    }

    if ($cache = $this->cache->get("tester:test_classes")) {
      $this->testClasses = $cache->data;
      return $this->testClasses;
    }

    $list = [];
    $full_info = [];

    // Discovers all test class files in all available extensions.
    $classmap = $this->findAllClassFiles();

    // Prevent expensive class loader lookups for each reflected test class by
    // registering the complete classmap of test classes to the class loader.
    // This also ensures that test classes are loaded from the discovered
    // pathnames; a namespace/classname mismatch will throw an exception.
    $this->classLoader->addClassMap($classmap);

    foreach ($this->retrievePhpUnitTestsListXml() as $test_case_class) {
      $classname = (string) $test_case_class->attributes()->name[0];
      $pathname = $classmap[$classname];
      $finder = MockFileFinder::create($pathname);
      $parser = new StaticReflectionParser($classname, $finder, TRUE);
      try {
        $info = static::getTestInfo($classname, $parser->getDocComment());
//        $info['name'] = preg_replace('/Drupal.*Tests/', '...', $info['name']);
        $info['filename'] = $pathname;
        $full_info[$classname] = $info;
      }
      catch (MissingGroupException $e) {
        // If the class is missing the @group annotation just skip it. Most
        // likely it is an abstract class, trait or test fixture.
        continue;
      }

      foreach ($info['groups'] as $group) {
        $list[$group][$classname] = $info;
      }
    }

    // Sort the groups and tests within the groups by name.
    uksort($list, 'strnatcasecmp');
    foreach ($list as &$tests) {
      uksort($tests, 'strnatcasecmp');
    }

    $this->testClasses = $list;
    $this->cache->set("tester:test_classes_info", $full_info, Cache::PERMANENT);
    $this->cache->set("tester:test_classes", $this->testClasses, Cache::PERMANENT);
    return $this->testClasses;
  }

  /**
   * @todo
   */
  public function getTestClassInfo(string $classname = NULL) {
    if ($cache = $this->cache->get("tester:test_classes_info")) {
      return $classname ? $cache->data[$classname] : $cache->data;
    }
  }

  /**
   * @todo
   */
  protected function retrievePhpUnitTestsListXml(): \SimpleXMLElement {
    // Executes PHPUnit with the --list-tests-xml option to retrieve all the
    // test classes that can be run.
    $list_command_ret = $this->execManager->execute('phpunit', [
      '-c',
      'core',
      '--list-tests-xml',
      'sites/tester/list-tests.xml',
    ], $output, $error);

    if ($list_command_ret !== 0) {
      throw new \RuntimeException("Error discovering tests: $error");
    }

    // Load the output XML for processing.
    $contents = @file_get_contents('sites/tester/list-tests.xml');
    if (!$contents) {
      throw new \RuntimeException('Could not load content of file list-tests.xml');
    }
    return new \SimpleXMLElement($contents);
  }

}
