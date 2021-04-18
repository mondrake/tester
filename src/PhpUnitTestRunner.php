<?php

namespace Drupal\tester;

use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Tests\Listeners\SimpletestUiPrinter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Run PHPUnit-based tests.
 *
 * This class runs PHPUnit-based tests and converts their JUnit results to a
 * format that can be stored in the {simpletest} database schema.
 *
 * This class is internal and not considered to be API.
 *
 * @code
 * $runner = PhpUnitTestRunner::create(\Drupal::getContainer());
 * $results = $runner->execute($test_run, $test_list['phpunit']);
 * @endcode
 *
 * @internal
 */
class PhpUnitTestRunner implements ContainerInjectionInterface {

  /**
   * Path to the working directory.
   *
   * JUnit log files will be stored in this directory.
   *
   * @var string
   */
  protected $workingDirectory;

  /**
   * Path to the application root.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): PhpUnitTestRunner {
    return new static(
      (string) $container->getParameter('app.root'),
      (string) $container->get('file_system')->realpath('public://tester')
    );
  }

  /**
   * Constructs a test runner.
   *
   * @param string $app_root
   *   Path to the application root.
   * @param string $working_directory
   *   Path to the working directory. JUnit log files will be stored in this
   *   directory.
   */
  public function __construct(string $app_root, string $working_directory) {
    $this->appRoot = $app_root;
    $this->workingDirectory = $working_directory;
  }

  /**
   * Returns the path to use for PHPUnit's --log-junit option.
   *
   * @param int $test_id
   *   The current test ID.
   *
   * @return string
   *   Path to the PHPUnit XML file to use for the current $test_id.
   *
   * @internal
   */
  public function xmlLogFilePath(int $test_id): string {
    return $this->workingDirectory . '/phpunit-' . $test_id . '.xml';
  }

  /**
   * Returns the command to run PHPUnit.
   *
   * @return string
   *   The command that can be run through exec().
   *
   * @internal
   */
  public function phpUnitCommand(): string {
    // Load the actual autoloader being used and determine its filename using
    // reflection. We can determine the vendor directory based on that filename.
    $autoloader = require $this->appRoot . '/autoload.php';
    $reflector = new \ReflectionClass($autoloader);
    $vendor_dir = dirname($reflector->getFileName(), 2);

    // The file in Composer's bin dir is a *nix link, which does not work when
    // extracted from a tarball and generally not on Windows.
    $command = $vendor_dir . '/phpunit/phpunit/phpunit';
    if (substr(PHP_OS, 0, 3) == 'WIN') {
      // On Windows it is necessary to run the script using the PHP executable.
      $php_executable_finder = new PhpExecutableFinder();
      $php = $php_executable_finder->find();
      $command = $php . ' -f ' . escapeshellarg($command) . ' --';
    }
    return $command;
  }

  /**
   * Executes the PHPUnit command.
   *
   * @param string[] $unescaped_test_classnames
   *   An array of test class names, including full namespaces, to be passed as
   *   a regular expression to PHPUnit's --filter option.
   * @param string $phpunit_file
   *   A filepath to use for PHPUnit's --log-junit option.
   * @param int $status
   *   (optional) The exit status code of the PHPUnit process will be assigned
   *   to this variable.
   * @param string[] $output
   *   (optional) The output by running the phpunit command. If provided, this
   *   array will contain the lines output by the command.
   *
   * @return string
   *   The results as returned by exec().
   *
   * @internal
   */
  public function runCommand(array $unescaped_test_classnames, string $phpunit_file, int &$status = NULL, array &$output = NULL): string {
    global $base_url;
    // Setup an environment variable containing the database connection so that
    // functional tests can connect to the database.
    putenv('SIMPLETEST_DB=' . Database::getConnectionInfoAsUrl());

    // Setup an environment variable containing the base URL, if it is available.
    // This allows functional tests to browse the site under test. When running
    // tests via CLI, core/phpunit.xml.dist or core/scripts/run-tests.sh can set
    // this variable.
    if ($base_url) {
      putenv('SIMPLETEST_BASE_URL=' . $base_url);
      putenv('BROWSERTEST_OUTPUT_DIRECTORY=' . $this->workingDirectory);
    }
    $phpunit_bin = $this->phpUnitCommand();

    $command = [
      $phpunit_bin,
      '--log-junit',
      escapeshellarg($phpunit_file),
      '--printer',
      escapeshellarg(SimpletestUiPrinter::class),
    ];

    // Optimized for running a single test.
    if (count($unescaped_test_classnames) == 1) {
      $class = new \ReflectionClass($unescaped_test_classnames[0]);
      $command[] = escapeshellarg($class->getFileName());
    }
    else {
      // Double escape namespaces so they'll work in a regexp.
      $escaped_test_classnames = array_map(function ($class) {
        return addslashes($class);
      }, $unescaped_test_classnames);

      $filter_string = implode("|", $escaped_test_classnames);
      $command = array_merge($command, [
        '--filter',
        escapeshellarg($filter_string),
      ]);
    }

    // Need to change directories before running the command so that we can use
    // relative paths in the configuration file's exclusions.
    $old_cwd = getcwd();
    chdir($this->appRoot . "/core");

    // exec in a subshell so that the environment is isolated when running tests
    // via the simpletest UI.
    $ret = exec(implode(" ", $command), $output, $status);

    chdir($old_cwd);
    putenv('SIMPLETEST_DB=');
    if ($base_url) {
      putenv('SIMPLETEST_BASE_URL=');
      putenv('BROWSERTEST_OUTPUT_DIRECTORY=');
    }
    return $ret;
  }

  /**
   * @todo
   */
  public function execute(TestRun $test_run, string $filename, string $classname, int &$status = NULL): array {
    $phpunit_file = $this->xmlLogFilePath($test_run->id());

    $command_ret = \Drupal::service('tester.exec_manager')->execute('phpunit', [
      '-c',
      'core',
//      '--teamcity',
      '--testdox',
      '-v',
      $filename,
    ], $output, $error);

    return [
      [
        'test_id' => $test_run->id(),
        'test_class' => $classname,
        'status' => $this->label($command_ret),
        'message_group' => 'PHPUnit',
        'exit_code' => $command_ret,
        'process_output' => $output,
        'process_error' => $error,
      ],
    ];
  }

  /**
   * Turns a status code into a label string.
   *
   * @param int $status
   *   A test runner return code.
   *
   * @return string
   *   The human-readable version of the status code.
   */
  protected function label($status) {
    switch ($status) {
      case 0:
        return 'pass';

      case 1:
        return 'fail';

      case 2:
        return 'error';

      default:
        return 'fatal';

    }
  }

  /**
   * Logs the parsed PHPUnit results into the test run.
   *
   * @param \Drupal\Core\Test\TestRun $test_run
   *   The test run object.
   * @param array[] $phpunit_results
   *   An array of test results, as returned from
   *   \Drupal\Core\Test\JUnitConverter::xmlToRows(). Can be the return value of
   *   PhpUnitTestRunner::execute().
   */
  public function processPhpUnitResults(TestRun $test_run, array $phpunit_results): void {
    foreach ($phpunit_results as $result) {
      $test_run->insertLogEntry($result);
    }
  }

  /**
   * Tallies test results per test class.
   *
   * @param string[][] $results
   *   Array of results in the {simpletest} schema. Can be the return value of
   *   PhpUnitTestRunner::execute().
   *
   * @return int[][]
   *   Array of status tallies, keyed by test class name and status type.
   *
   * @internal
   */
  public function summarizeResults(array $results): array {
    $summaries = [];

    foreach ($results as $result) {
      if (!isset($summaries[$result['test_class']])) {
        switch ($result['status']) {
          case 'pass':
            $result_description = t('OK');
            break;

          case 'fail':
            $result_description = t('Failures!');
            break;

          case 'error':
            $result_description = t('Errors!');
            break;

          case 'fatal':
            $result_description = t('FATAL test process error (exit code: @code)', ['@code' => $result['exit_code']]);
            break;

          default:
            $result_description = t('Unknown result status');
            break;
        }


        $summaries[$result['test_class']] = [
          '#result' => $result_description,
          '#pass' => 0,
          '#fail' => 0,
          '#warn' => 0,
          '#error' => 0,
          '#fatal' => 0,
          '#debug' => 0,
        ];
      }

      switch ($result['status']) {
        case 'pass':
          $summaries[$result['test_class']]['#pass']++;
          break;

        case 'warn':
          $summaries[$result['test_class']]['#warn']++;
          break;

        case 'fail':
          $summaries[$result['test_class']]['#fail']++;
          break;

        case 'error':
          $summaries[$result['test_class']]['#error']++;
          break;

        case 'fatal':
          $summaries[$result['test_class']]['#fatal']++;
          break;

        case 'debug':
          $summaries[$result['test_class']]['#debug']++;
          break;
      }

    }
    return $summaries;
  }

}
