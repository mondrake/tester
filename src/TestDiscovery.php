<?php

namespace Drupal\tester;

use Doctrine\Common\Reflection\StaticReflectionParser;
use Drupal\Component\Annotation\Reflection\MockFileFinder;
use Drupal\Component\Utility\NestedArray;
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
   * Constructs a TestDiscovery object.
   *
   * @param string $root
   *   The app root.
   * @param $class_loader
   *   The class loader. Normally Composer's ClassLoader, as included by the
   *   front controller, but may also be decorated; e.g.,
   *   \Symfony\Component\ClassLoader\ApcClassLoader.
   * @param \Drupal\tester\ExecManager $exec_manager
   *   The execution manager.
   */
  public function __construct(string $root, $class_loader, ExecManager $exec_manager) {
    parent::__construct($root, $class_loader);
    $this->execManager = $exec_manager;
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

    $list = [];

    // Discovers all test class files in all available extensions.
    $classmap = $this->findAllClassFiles();

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
  dump($info);
        $info['name'] = preg_replace('/Drupal\\.*Tests/', '...', $info['name']);
  dump($info);
exit();
        $info['filename'] = $pathname;
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



/*

    $list_command_ret = $this->execManager->execute('phpunit', [
      '-c',
      'core',
      '--list-tests-xml',
      'sites/tester/list-tests.xml',
    ], $output, $error);

//    dump([$output, $error]);

    $contents = @file_get_contents('sites/tester/list-tests.xml');
    if (!$contents) {
      return [];
    }
    $xml = new \SimpleXMLElement($contents);

    foreach ($xml as $testCaseClass) {
      $class = [];
      $groups = explode(',', (string) $testCaseClass->children()[0]->attributes()->groups[0]);
      $group = $groups[0];
      $classname = (string) $testCaseClass->attributes()->name[0];

      $class['name'] = $classname;
      $class['description'] = 'fake';
      $class['group'] = $group;
      $class['groups'] = $groups;

      $list[$group][$classname] = $class;
    }
*/

    // Sort the groups and tests within the groups by name.
    uksort($list, 'strnatcasecmp');
    foreach ($list as &$tests) {
      uksort($tests, 'strnatcasecmp');
    }

    dump($list);
    dump($list['#slow']);

    exit();

    $this->testClasses = $list;

    return $this->testClasses;
  }

}
