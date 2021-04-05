<?php

namespace Drupal\tester;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Helper class for cleaning test environments.
 */
class EnvironmentCleanerService implements EnvironmentCleanerInterface {

  /**
   * Path to Drupal root directory.
   *
   * @var string
   */
  protected $root;

  /**
   * Connection to the database being used for tests.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $testDatabase;

  /**
   * The test run results storage.
   *
   * @var \Drupal\Core\Test\TestRunResultsStorageInterface
   */
  protected $testRunResultsStorage;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * @todo.
   */
  public function __construct(string $root, Connection $test_database, TestRunResultsStorageInterface $test_run_results_storage, MessengerInterface $messenger, TranslationInterface $translation, ConfigFactory $config, CacheBackendInterface $cache_default, FileSystemInterface $file_system) {
    $this->root = $root;
    $this->testDatabase = $test_database;
    $this->testRunResultsStorage = $test_run_results_storage;
    $this->messenger = $messenger;
    $this->translation = $translation;
    $this->configFactory = $config;
    $this->cacheDefault = $cache_default;
    $this->fileSystem = $file_system;
  }
  /**
   * {@inheritdoc}
   */
  public function cleanEnvironment(bool $clear_results = TRUE, bool $clear_temp_directories = TRUE, bool $clear_database = TRUE): void {
    $count = 0;
    if ($clear_database) {
      $this->doCleanDatabase();
    }
    if ($clear_temp_directories) {
      $this->doCleanTemporaryDirectories();
    }
    if ($clear_results) {
      $count = $this->cleanResults();
      $this->messenger->addMessage($this->translation->formatPlural($results_removed, 'Removed 1 test result.', 'Removed @count test results.'));
    }
    else {
      $this->messenger->addMessage($this->translation->translate('Clear results is disabled and the test results table will not be cleared.'), 'warning');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function cleanDatabase(): void {
    $count = $this->doCleanDatabase();
    if ($count > 0) {
      $this->messenger->addMessage($this->translation->formatPlural($tables_removed, 'Removed 1 leftover table.', 'Removed @count leftover tables.'));
    }
    else {
      $this->messenger->addMessage($this->translation->translate('No leftover tables to remove.'));
    }
  }

  /**
   * Performs the fixture database cleanup.
   *
   * @return int
   *   The number of tables that were removed.
   */
  protected function doCleanDatabase(): int {
    /* @var $schema \Drupal\Core\Database\Schema */
    $schema = $this->testDatabase->schema();
    $tables = $schema->findTables('test%');
    $count = 0;
    foreach ($tables as $table) {
      // Only drop tables which begin wih 'test' followed by digits, for example,
      // {test12345678node__body}.
      if (preg_match('/^test\d+.*/', $table, $matches)) {
        $schema->dropTable($matches[0]);
        $count++;
      }
    }
    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function cleanTemporaryDirectories(): void {
    $count = $this->doCleanTemporaryDirectories();
    if ($count > 0) {
      $this->messenger->addMessage($this->translation->formatPlural($directories_removed, 'Removed 1 temporary directory.', 'Removed @count temporary directories.'));
    }
    else {
      $this->messenger->addMessage($this->translation->translate('No temporary directories to remove.'));
    }
  }

  /**
   * Performs the cleanup of temporary test directories.
   *
   * @return int
   *   The count of temporary directories removed.
   */
  protected function doCleanTemporaryDirectories(): int {
    $count = 0;
    $simpletest_dir = $this->root . '/sites/tester';
    if (is_dir($simpletest_dir)) {
      $files = scandir($simpletest_dir);
      foreach ($files as $file) {
        if ($file[0] != '.') {
          $path = $simpletest_dir . '/' . $file;
          $this->fileSystem->deleteRecursive($path, function ($any_path) {
            @chmod($any_path, 0700);
          });
          $count++;
        }
      }
    }
    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function cleanResults(TestRun $test_run = NULL): int {
    return $test_run ? $test_run->removeResults() : $this->testRunResultsStorage->cleanUp();
  }

}
