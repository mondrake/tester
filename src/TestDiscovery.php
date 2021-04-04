<?php

namespace Drupal\tester;

use Doctrine\Common\Reflection\StaticReflectionParser;
use Drupal\Component\Annotation\Reflection\MockFileFinder;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Test\Exception\MissingGroupException;

/**
 * Discovers available tests.
 */
class TestDiscovery {

  /**
   * The app root.
   *
   * @var string
   */
  protected $root;

  /**
   * @todo
   */
  protected $execManager;

  /**
   * Constructs a new test discovery.
   *
   * @param string $root
   *   The app root.
   * @param \Drupal\tester\ExecManager $exec_manager
   *   The execution manager.
   */
  public function __construct(string $root, ExecManager $exec_manager) {
    $this->root = $root;
    $this->execManager = $exec_manager;
  }

  /**
   * Discovers all available tests in all extensions.
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
  public function getTestClasses() {
    $list = [];

    $classmap = $this->findAllClassFiles($extension);

    // Prevent expensive class loader lookups for each reflected test class by
    // registering the complete classmap of test classes to the class loader.
    // This also ensures that test classes are loaded from the discovered
    // pathnames; a namespace/classname mismatch will throw an exception.
    $this->classLoader->addClassMap($classmap);

    foreach ($classmap as $classname => $pathname) {
      $finder = MockFileFinder::create($pathname);
      $parser = new StaticReflectionParser($classname, $finder, TRUE);
      try {
        $info = static::getTestInfo($classname, $parser->getDocComment());
      }
      catch (MissingGroupException $e) {
        // If the class name ends in Test and is not a migrate table dump.
        if (preg_match('/Test$/', $classname) && strpos($classname, 'migrate_drupal\Tests\Table') === FALSE) {
          throw $e;
        }
        // If the class is @group annotation just skip it. Most likely it is an
        // abstract class, trait or test fixture.
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

    if (!isset($extension) && empty($types)) {
      $this->testClasses = $list;
    }

    if ($types) {
      $list = NestedArray::filter($list, function ($element) use ($types) {
        return !(is_array($element) && isset($element['type']) && !in_array($element['type'], $types));
      });
    }

    return $list;
  }

}
