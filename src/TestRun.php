<?php

namespace Drupal\tester;

/**
 * Implements an object that tracks execution of a test run.
 *
 * @internal
 */
class TestRun {

  /**
   * The test run results storage.
   *
   * @var \Drupal\Core\Test\TestRunResultsStorageInterface
   */
  protected $testRunResultsStorage;

  /**
   * A unique test run id.
   *
   * @var int|string
   */
  protected $testId;

  /**
   * The test database prefix.
   *
   * @var string
   */
  protected $databasePrefix;

  /**
   * The latest class under test.
   *
   * @var string
   */
  protected $testClass;

  /**
   * TestRun constructor.
   *
   * @param \Drupal\Core\Test\TestRunResultsStorageInterface $test_run_results_storage
   *   The test run results storage.
   * @param int|string $test_id
   *   A unique test run id.
   */
  public function __construct(TestRunResultsStorageInterface $test_run_results_storage, $test_id) {
    $this->testRunResultsStorage = $test_run_results_storage;
    $this->testId = $test_id;
  }

  /**
   * Returns a new test run object.
   *
   * @param \Drupal\Core\Test\TestRunResultsStorageInterface $test_run_results_storage
   *   The test run results storage.
   *
   * @return self
   *   The new test run object.
   */
  public static function createNew(TestRunResultsStorageInterface $test_run_results_storage): TestRun {
    $test_id = $test_run_results_storage->createNew();
    return new static($test_run_results_storage, $test_id);
  }

  /**
   * Returns a test run object from storage.
   *
   * @param \Drupal\Core\Test\TestRunResultsStorageInterface $test_run_results_storage
   *   The test run results storage.
   * @param int|string $test_id
   *   The test run id.
   *
   * @return self
   *   The test run object.
   */
  public static function get(TestRunResultsStorageInterface $test_run_results_storage, $test_id): TestRun {
    return new static($test_run_results_storage, $test_id);
  }

  /**
   * Returns the id of the test run object.
   *
   * @return int|string
   *   The id of the test run object.
   */
  public function id() {
    return $this->testId;
  }

  /**
   * Sets the test database prefix.
   *
   * @param string $database_prefix
   *   The database prefix.
   *
   * @throws \RuntimeException
   *   If the database prefix cannot be saved to storage.
   */
  public function setDatabasePrefix(string $database_prefix): void {
    $this->databasePrefix = $database_prefix;
    $this->testRunResultsStorage->setDatabasePrefix($this, $database_prefix);
  }

  /**
   * Gets the test database prefix.
   *
   * @return string
   *   The database prefix.
   */
  public function getDatabasePrefix(): string {
    if (is_null($this->databasePrefix)) {
      $state = $this->testRunResultsStorage->getCurrentTestRunState($this);
      $this->databasePrefix = $state['db_prefix'];
      $this->testClass = $state['test_class'];
    }
    return $this->databasePrefix;
  }

  /**
   * Gets the latest class under test.
   *
   * @return string
   *   The test class.
   */
  public function getTestClass(): string {
    if (is_null($this->testClass)) {
      $state = $this->testRunResultsStorage->getCurrentTestRunState($this);
      $this->databasePrefix = $state['db_prefix'];
      $this->testClass = $state['test_class'];
    }
    return $this->testClass;
  }

  /**
   * Adds a test log entry.
   *
   * @param array $entry
   *   The array of the log entry elements.
   *
   * @return bool
   *   TRUE if the addition was successful, FALSE otherwise.
   */
  public function insertLogEntry(array $entry): bool {
    $this->testClass = $entry['test_class'];
    return $this->testRunResultsStorage->insertLogEntry($this, $entry);
  }

  /**
   * Get test results for a test run, ordered by test class.
   *
   * @return array
   *   Array of results ordered by test class and message id.
   */
  public function getLogEntriesByTestClass(): array {
    return $this->testRunResultsStorage->getLogEntriesByTestClass($this);
  }

  /**
   * Removes the test results from the storage.
   *
   * @return int
   *   The number of log entries that were removed from storage.
   */
  public function removeResults(): int {
    return $this->testRunResultsStorage->removeResults($this);
  }

}
