<?php

namespace Drupal\tester;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\ConnectionNotDefinedException;

/**
 * Implements a test run results storage compatible with Tester.
 *
 * @internal
 */
class TestRunResultsStorage implements TestRunResultsStorageInterface {

  /**
   * The database connection to use for inserting assertions.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * TestRunResultsStorage constructor.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use for inserting assertions.
   */
  public function __construct(Connection $connection = NULL) {
    if (is_null($connection)) {
      $connection = static::getConnection();
    }
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public function createNew() {
    return $this->connection->insert('tester_test_id')
      ->useDefaults(['test_id'])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function setDatabasePrefix(TestRun $test_run, string $database_prefix): void {
    $affected_rows = $this->connection->update('tester_test_id')
      ->fields(['last_prefix' => $database_prefix])
      ->condition('test_id', $test_run->id())
      ->execute();
    if (!$affected_rows) {
      throw new \RuntimeException('Failed to set up database prefix.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function insertLogEntry(TestRun $test_run, array $entry): bool {
    $entry['test_id'] = $test_run->id();
    return (bool) $this->connection->insert('tester_log')
      ->fields($entry)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function removeResults(TestRun $test_run): int {
    $tx = $this->connection->startTransaction('delete_test_run');
    $this->connection->delete('tester_log')
      ->condition('test_id', $test_run->id())
      ->execute();
    $count = $this->connection->delete('tester_test_id')
      ->condition('test_id', $test_run->id())
      ->execute();
    $tx = NULL;
    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function getLogEntriesByTestClass(TestRun $test_run): array {
    return $this->connection->select('tester_log')
      ->fields('tester_log')
      ->condition('test_id', $test_run->id())
      ->orderBy('test_class')
      ->orderBy('message_id')
      ->execute()
      ->fetchAll();
  }

  public function xdump() {
    dump($this->connection->select('tester_test_id')
      ->fields('tester_test_id')
      ->orderBy('test_id')
      ->execute()
      ->fetchAll());
    dump($this->connection->select('tester_log')
      ->fields('tester_log')
      ->orderBy('message_id')
      ->execute()
      ->fetchAll());
    exit();
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTestRunState(TestRun $test_run): array {
    // Define a subquery to identify the latest 'message_id' given the
    // $test_id.
    $max_message_id_subquery = $this->connection
      ->select('tester_log', 'sub')
      ->condition('test_id', $test_run->id());
    $max_message_id_subquery->addExpression('MAX([message_id])', 'max_message_id');

    // Run a select query to return 'last_prefix' from {tester_test_id} and
    // 'test_class' from {tester_log}.
    $select = $this->connection->select($max_message_id_subquery, 'st_sub');
    $select->join('tester_log', 'st', '[st].[message_id] = [st_sub].[max_message_id]');
    $select->join('tester_test_id', 'sttid', '[st].[test_id] = [sttid].[test_id]');
    $select->addField('sttid', 'last_prefix', 'db_prefix');
    $select->addField('st', 'test_class');

    return $select->execute()->fetchAssoc();
  }

  /**
   * {@inheritdoc}
   */
  public function buildTestingResultsEnvironment(bool $keep_results): void {
    $schema = $this->connection->schema();
    foreach (static::testingResultsSchema() as $name => $table_spec) {
      $table_exists = $schema->tableExists($name);
      if (!$keep_results && $table_exists) {
        $this->connection->truncate($name)->execute();
      }
      if (!$table_exists) {
        $schema->createTable($name, $table_spec);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateTestingResultsEnvironment(): bool {
    $schema = $this->connection->schema();
    return $schema->tableExists('tester_log') && $schema->tableExists('tester_test_id');
  }

  /**
   * {@inheritdoc}
   */
  public function cleanUp(): int {
    // Clear test results.
    $tx = $this->connection->startTransaction('delete_tester');
    $this->connection->delete('tester_log')->execute();
    $count = $this->connection->delete('tester_test_id')->execute();
    $tx = NULL;
    return $count;
  }

  /**
   * Defines the database schema for Tester test run storage.
   *
   * @return array
   *   Array suitable for use in a hook_schema() implementation.
   */
  public static function testingResultsSchema(): array {
    $schema['tester_log'] = [
      'description' => 'Stores tester log',
      'fields' => [
        'message_id' => [
          'type' => 'serial',
          'not null' => TRUE,
          'description' => 'Primary Key: Unique log ID.',
        ],
        'test_id' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'Test ID, messages belonging to the same ID are reported together',
        ],
        'test_class' => [
          'type' => 'varchar_ascii',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
          'description' => 'The name of the class that created this message.',
        ],
        'status' => [
          'type' => 'varchar',
          'length' => 9,
          'not null' => TRUE,
          'default' => '',
          'description' => 'Test status.',
        ],
        'message_group' => [
          'type' => 'varchar_ascii',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
          'description' => 'The message group this message belongs to. For example: warning, browser, user.',
        ],
        'exit_code' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'The exit code of the test process.',
        ],
        'process_output' => [
          'type' => 'text',
          'not null' => FALSE,
          'description' => 'The console output of the test process.',
        ],
        'process_error' => [
          'type' => 'text',
          'not null' => FALSE,
          'description' => 'The console error of the test process.',
        ],
      ],
      'primary key' => ['message_id'],
      'indexes' => [
        'reporter' => ['test_class', 'message_id'],
      ],
    ];
    $schema['tester_test_id'] = [
      'description' => 'Stores tester test IDs, used to auto-increment the test ID so that a fresh test ID is used.',
      'fields' => [
        'test_id' => [
          'type' => 'serial',
          'not null' => TRUE,
          'description' => 'Unique test ID used to test results together. Each time a set of tests are run a new test ID is used.',
        ],
        'last_prefix' => [
          'type' => 'varchar',
          'length' => 60,
          'not null' => FALSE,
          'default' => '',
          'description' => 'The last database prefix used during testing.',
        ],
      ],
      'primary key' => ['test_id'],
    ];
    return $schema;
  }

}
