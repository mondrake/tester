<?php

namespace Drupal\tester\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tester\EnvironmentCleanerInterface;
use Drupal\Core\Url;
use Drupal\tester\TestDiscovery;
use Drupal\tester\TestRun;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Test results form for $test_id.
 *
 * Note that the UI strings are not translated because this form is also used
 * from run-tests.sh.
 *
 * @internal
 *
 * @see tester_script_open_browser()
 * @see run-tests.sh
 */
class TesterResultsForm extends FormBase {

  /**
   * Associative array of themed result images keyed by status.
   *
   * @var array
   */
  protected $statusImageMap;

  /**
   * The database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The test discovery service.
   *
   * @var \Drupal\tester\TestDiscovery
   */
  protected $testDiscovery;

  /**
   * The environment cleaner service.
   *
   * @var \Drupal\tester\EnvironmentCleanerInterface
   */
  protected $cleaner;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('tester.test_discovery')
//      $container->get('tester.environment_cleaner')
    );
  }

  /**
   * Constructs a \Drupal\tester\Form\TesterResultsForm object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection service.
   * @param \Drupal\tester\TestDiscovery $test_discovery
   *   The test discovery service.
   */
  public function __construct(Connection $database, TestDiscovery $test_discovery /*, EnvironmentCleanerInterface $cleaner */) {
    $this->database = $database;
    $this->testDiscovery = $test_discovery;
//    $this->cleaner = $cleaner;
  }

  /**
   * Builds the status image map.
   */
  protected static function buildStatusImageMap() {
    $image_pass = [
      '#theme' => 'image',
      '#uri' => 'core/misc/icons/73b355/check.svg',
      '#width' => 18,
      '#height' => 18,
      '#alt' => 'Pass',
    ];
    $image_warn = [
      '#theme' => 'image',
      '#uri' => 'core/misc/icons/e29700/warning.svg',
      '#width' => 18,
      '#height' => 18,
      '#alt' => 'Warning',
    ];
    $image_fail = [
      '#theme' => 'image',
      '#uri' => 'core/misc/icons/e32700/error.svg',
      '#width' => 18,
      '#height' => 18,
      '#alt' => 'Fail',
    ];
    $image_error = [
      '#theme' => 'image',
      '#uri' => 'core/misc/icons/e32700/error.svg',
      '#width' => 18,
      '#height' => 18,
      '#alt' => 'Error',
    ];
    $image_fatal = [
      '#theme' => 'image',
      '#uri' => 'core/misc/icons/e32700/error.svg',
      '#width' => 18,
      '#height' => 18,
      '#alt' => 'Fatal',
    ];
    $image_debug = [
      '#theme' => 'image',
      '#uri' => 'core/misc/icons/bebebe/pencil.svg',
      '#width' => 18,
      '#height' => 18,
      '#alt' => 'Debug',
    ];
    return [
      'pass' => $image_pass,
      'warn' => $image_warn,
      'fail' => $image_fail,
      'error' => $image_error,
      'fatal' => $image_fatal,
      'debug' => $image_debug,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tester_results_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $test_id = NULL) {
    // Make sure there are test results to display and a re-run is not being
    // performed.
    $results = [];
    if (is_numeric($test_id) && !$results = $this->getResults($test_id)) {
      $this->messenger()->addError($this->t('No test results to display.'));
      return $this->redirect('tester.test_form');
    }
//dump($results);

    // Load all classes and include CSS.
    $form['#attached']['library'][] = 'tester/tester';
    // Add the results form.
    $filter = static::addResultForm($form, $results, $this->getStringTranslation());

    // Actions.
    $form['#action'] = Url::fromRoute('tester.result_form', ['test_id' => 're-run'])->toString();
    $form['action'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Actions'),
      '#attributes' => ['class' => ['container-inline']],
      '#weight' => -11,
    ];

    $pass_count = count($filter['pass']);
    $fail_count = count($filter['fail']);
    $form['action']['filter'] = [
      '#type' => 'select',
      '#title' => 'Filter',
      '#options' => [
        'all' => $this->t('All (@count)', [
          '@count' => $pass_count + $fail_count,
        ]),
        'pass' => $this->t('Pass (@count)', [
          '@count' => $pass_count,
        ]),
        'fail' => $this->t('Fail (@count)', [
          '@count' => $fail_count,
        ]),
      ],
    ];
    $form['action']['filter']['#default_value'] = ($filter['fail'] ? 'fail' : 'all');

    // Categorized test classes for to be used with selected filter value.
    $form['action']['filter_pass'] = [
      '#type' => 'hidden',
      '#default_value' => implode(',', $filter['pass']),
    ];
    $form['action']['filter_fail'] = [
      '#type' => 'hidden',
      '#default_value' => implode(',', $filter['fail']),
    ];

    $form['action']['op'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run tests'),
    ];

    $form['action']['return'] = [
      '#type' => 'link',
      '#title' => $this->t('Return to list'),
      '#url' => Url::fromRoute('tester.test_form'),
    ];

/*    if (is_numeric($test_id)) {
      $this->cleaner->cleanResultsTable($test_id);
    }
*/
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $pass = $form_state->getValue('filter_pass') ? explode(',', $form_state->getValue('filter_pass')) : [];
    $fail = $form_state->getValue('filter_fail') ? explode(',', $form_state->getValue('filter_fail')) : [];

    if ($form_state->getValue('filter') == 'all') {
      $classes = array_merge($pass, $fail);
    }
    elseif ($form_state->getValue('filter') == 'pass') {
      $classes = $pass;
    }
    else {
      $classes = $fail;
    }

    if (!$classes) {
      $form_state->setRedirect('tester.test_form');
      return;
    }

    $form_execute = [];
    $form_state_execute = new FormState();
    foreach ($classes as $class) {
      $form_state_execute->setValue(['tests', $class], $class);
    }

    // Submit the Tester test form to rerun the tests.
    // Under normal circumstances, a form object's submitForm() should never be
    // called directly, FormBuilder::submitForm() should be called instead.
    // However, it calls $form_state->setProgrammed(), which disables the Batch API.
    $tester_test_form = TesterTestForm::create(\Drupal::getContainer());
    $tester_test_form->buildForm($form_execute, $form_state_execute);
    $tester_test_form->submitForm($form_execute, $form_state_execute);
    if ($redirect = $form_state_execute->getRedirect()) {
      $form_state->setRedirectUrl($redirect);
    }
  }

  /**
   * Get test results for $test_id.
   *
   * @param int $test_id
   *   The test_id to retrieve results of.
   *
   * @return array
   *   Array of results grouped by test_class.
   */
  protected function getResults($test_id) {
    $test_run = TestRun::get(tester_test_run_results_storage(), $test_id);
    return $test_run->getLogEntriesByTestClass();
  }

  /**
   * Adds the result form to a $form.
   *
   * This is a static method so that run-tests.sh can use it to generate a
   * results page completely external to Drupal. This is why the UI strings are
   * not wrapped in t().
   *
   * @param array $form
   *   The form to attach the results to.
   * @param array $results
   *   The test results.
   *
   * @return array
   *   A list of tests the passed and failed. The array has two keys, 'pass' and
   *   'fail'. Each contains a list of test classes.
   *
   * @see tester_script_open_browser()
   * @see run-tests.sh
   */
  public static function addResultForm(array &$form, array $results) {
    // Transform the test results to be grouped by test class.
    $test_results = [];
    foreach ($results as $result) {
      if (!isset($test_results[$result->test_class])) {
        $test_results[$result->test_class] = [];
      }
      $test_results[$result->test_class][] = $result;
    }

    $image_status_map = static::buildStatusImageMap();

    // Keep track of which test cases passed or failed.
    $filter = [
      'pass' => [],
      'fail' => [],
    ];

    // Summary result widget.
    $form['result'] = [
      '#type' => 'fieldset',
      '#title' => 'Results',
      // Because this is used in a theme-less situation need to provide a
      // default.
      '#attributes' => [],
    ];
    $form['result']['summary'] = $summary = [
      '#theme' => 'tester_result_summary',
      '#pass' => 0,
      '#warn' => 0,
      '#fail' => 0,
      '#error' => 0,
      '#fatal' => 0,
      '#debug' => 0,
    ];

    // Cycle through each test group.
    $header = [
      'Status',
      'Message',
    ];
    $form['result']['results'] = [];

    foreach ($test_results as $group => $assertions) {
      // Create group details with summary information.
      $info = \Drupal::service('tester.test_discovery')->getTestClassInfo($group);
      $form['result']['results'][$group] = [
        '#type' => 'details',
        '#title' => $info['name'],
        '#open' => TRUE,
        '#description' => $info['description'],
      ];
      $form['result']['results'][$group]['summary'] = $summary;
      $group_summary =& $form['result']['results'][$group]['summary'];

      // Create table of assertions for the group.
      $rows = [];
      foreach ($assertions as $assertion) {
        $row = [];
        $row[] = ['data' => $image_status_map[$assertion->status]];
        $message = ($assertion->exit_code >= 0 && $assertion->exit_code < 3) ? $assertion->process_output : $assertion->process_error;
        $row[] = ['data' => ['#markup' => '<pre>' . $message . '</pre>']];

        $class = 'tester-' . $assertion->status;
        if ($assertion->message_group == 'Debug') {
          $class = 'tester-debug';
        }
        $rows[] = ['data' => $row, 'class' => [$class]];

        $group_summary['#' . $assertion->status]++;
        $form['result']['summary']['#' . $assertion->status]++;
      }
      $form['result']['results'][$group]['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
      ];

      // Set summary information.
      $group_summary['#ok'] = $group_summary['#fail'] + $group_summary['#error'] + $group_summary['#fatal'] == 0;
      $form['result']['results'][$group]['#open'] = !$group_summary['#ok'];

      // Store test group (class) as for use in filter.
      $filter[$group_summary['#ok'] ? 'pass' : 'fail'][] = $group;
    }

    // Overall summary status.
    $form['result']['summary']['#ok'] = $form['result']['summary']['#fail'] + $form['result']['summary']['#error'] + $form['result']['summary']['#fatal'] == 0;

    return $filter;
  }

}
