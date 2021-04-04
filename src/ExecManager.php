<?php

namespace Drupal\tester;

use Drupal\Component\Utility\Timer;
use Drupal\Core\Config\ConfigFactoryInterface;
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
  public function checkPath(string $path, string $package = NULL): array {
    $status = [
      'output' => '',
      'errors' => [],
    ];

    // Execute gm or convert based on settings.
    $package = $package ?: $this->getPackage();
    $binary = $package === 'imagemagick' ? 'convert' : 'gm';
    $executable = $this->getExecutable($binary, $path);

    // If a path is given, we check whether the binary exists and can be
    // invoked.
    if (!empty($path)) {
      // Check whether the given file exists.
      if (!is_file($executable)) {
        $status['errors'][] = $this->t('The @suite executable %file does not exist.', ['@suite' => $this->getPackageLabel($package), '%file' => $executable]);
      }
      // If it exists, check whether we can execute it.
      elseif (!is_executable($executable)) {
        $status['errors'][] = $this->t('The @suite file %file is not executable.', ['@suite' => $this->getPackageLabel($package), '%file' => $executable]);
      }
    }

    // In case of errors, check for open_basedir restrictions.
    if ($status['errors'] && ($open_basedir = ini_get('open_basedir'))) {
      $status['errors'][] = $this->t('The PHP <a href=":php-url">open_basedir</a> security restriction is set to %open-basedir, which may prevent to locate the @suite executable.', [
        '@suite' => $this->getPackageLabel($package),
        '%open-basedir' => $open_basedir,
        ':php-url' => 'http://php.net/manual/en/ini.core.php#ini.open-basedir',
      ]);
    }

    // Unless we had errors so far, try to invoke convert.
    if (!$status['errors']) {
      $error = NULL;
      $this->runOsShell($executable, '-version', $package, $status['output'], $error);
      if ($error !== '') {
        $status['errors'][] = $error;
      }
    }

    return $status;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(string $command, array $arguments, string &$output = NULL, string &$error = NULL, string $path = NULL): bool {
    $cmd = $this->getExecutable($command, $path);
    $return_code = $this->runOsShell($cmd, $arguments, $output, $error);
    if ($return_code !== FALSE) {
      // If the executable returned a non-zero code, log to the watchdog.
      if ($return_code != 0) {
        if ($error === '') {
          $this->logger->warning("@suite returned with code @code [command: @command @cmdline]", [
            '@suite' => $this->getPackageLabel(),
            '@code' => $return_code,
            '@command' => $cmd,
            '@cmdline' => $cmdline,
          ]);
        }
        else {
          // Log $error with context information.
          $this->logger->error("@suite error @code: @error [command: @command @cmdline]", [
            '@suite' => $this->getPackageLabel(),
            '@code' => $return_code,
            '@error' => $error,
            '@command' => $cmd,
            '@cmdline' => $cmdline,
          ]);
        }
        // Executable exited with an error code, return FALSE.
        return FALSE;
      }

      // The shell command was executed successfully.
      return TRUE;
    }
    // The shell command could not be executed.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function runOsShell(string $command, array $arguments, string &$output = NULL, string &$error = NULL): int {
    $command_line = array_merge([$command], $arguments);
    $output = '';
    $error = '';

    Timer::start('tester:runOsShell');
    $process = new Process($command_line, $this->appRoot);
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
   * Logs a debug message, and shows it on the screen for authorized users.
   *
   * @param string $message
   *   The debug message.
   * @param string[] $context
   *   Context information.
   */
  public function debugMessage(string $message, array $context) {
    $this->logger->debug($message, $context);
    if ($this->currentUser->hasPermission('administer site configuration')) {
      // Strips raw text longer than 10 lines to optimize displaying.
      if (isset($context['@raw'])) {
        $raw = explode("\n", $context['@raw']);
        if (count($raw) > 10) {
          $tmp = [];
          for ($i = 0; $i < 9; $i++) {
            $tmp[] = $raw[$i];
          }
          $tmp[] = (string) $this->t('[Further text stripped. The watchdog log has the full text.]');
          $context['@raw'] = implode("\n", $tmp);
        }
      }
      // @codingStandardsIgnoreLine
      $this->messenger->addMessage($this->t($message, $context), 'status', TRUE);
    }
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
