<?php

namespace Drupal\tester;

use Drupal\Component\Utility\Timer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Manage execution of Tester commands.
 */
class ExecManager {

  use StringTranslationTrait;

  /**
   * Whether we are running on Windows OS.
   *
   * @var bool
   */
  protected $isWindows;

  /**
   * The app root.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * The execution timeout.
   *
   * @var int
   */
  protected $timeout = 60;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs an ImagemagickExecManager object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param string $app_root
   *   The app root.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(LoggerInterface $logger, ConfigFactoryInterface $config_factory, string $app_root, AccountProxyInterface $current_user, MessengerInterface $messenger) {
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->appRoot = $app_root;
    $this->currentUser = $current_user;
    $this->messenger = $messenger;
    $this->isWindows = substr(PHP_OS, 0, 3) === 'WIN';
  }

  /**
   * {@inheritdoc}
   */
  public function setTimeout(int $timeout): ImagemagickExecManagerInterface {
    $this->timeout = $timeout;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(string $command, array $arguments, string &$output = NULL, string &$error = NULL, string $path = NULL): int {
    $cmd = $this->getExecutable($command, $path);
    return $this->runOsShell($cmd, $arguments, $output, $error);
  }

  /**
   * {@inheritdoc}
   */
  public function runOsShell(string $command, array $arguments, string &$output = NULL, string &$error = NULL): int {
    $command_line = array_merge([$command], $arguments);
    $output = '';
    $error = '';

    $process_environment_variables = [
      'SIMPLETEST_DB' => Database::getConnectionInfoAsUrl(),
    ];

    Timer::start('tester:runOsShell');
    $process = new Process($command_line, $this->appRoot, $process_environment_variables);
    $process->inheritEnvironmentVariables();
    $process->setTimeout($this->timeout);
    try {
      $process->run();
      $output = utf8_encode($process->getOutput());
      $error = utf8_encode($process->getErrorOutput());
      $return_code = $process->getExitCode();
    }
    catch (\Exception $e) {
      $error = $e->getMessage();
      $return_code = $process->getExitCode() ? $process->getExitCode() : 1;
    }
    $execution_time = Timer::stop('tester:runOsShell')['time'];

    return $return_code;
  }

  /**
   * Returns the full path to the executable.
   *
   * @param string $command
   *   (optional) The command, 'phpunit' by default.
   * @param string $path
   *   (optional) A custom path to the folder of the executable.
   *
   * @return string
   *   The full path to the executable.
   */
  protected function getExecutable(string $command = NULL, string $path = NULL): string {
    $path = $path ?? 'vendor/bin/';

    $executable = $command ?? 'phpunit';
    if ($this->isWindows) {
      $executable .= '.exe';
    }

    return $path . $executable;
  }

}
