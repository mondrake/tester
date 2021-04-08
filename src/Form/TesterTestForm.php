<?php

namespace Drupal\tester\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\tester\TestDiscovery;
use Drupal\tester\TestRun;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List tests arranged in groups that can be selected and run.
 *
 * @internal
 */
class TesterTestForm extends FormBase {

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The test discovery service.
   *
   * @var \Drupal\tester\TestDiscovery
   */
  protected $testDiscovery;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('tester.test_discovery')
    );
  }

  /**
   * Constructs a new TesterTestForm.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\tester\TestDiscovery $test_discovery
   *   The test discovery service.
   */
  public function __construct(RendererInterface $renderer, TestDiscovery $test_discovery) {
    $this->renderer = $renderer;
    $this->testDiscovery = $test_discovery;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tester_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run tests'),
      '#tableselect' => TRUE,
      '#button_type' => 'primary',
    ];
    $form['clean'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Clean test environment'),
      '#description' => $this->t('Remove tables with the prefix "test" followed by digits and temporary directories that are left over from tests that crashed. This is intended for developers when creating tests.'),
      '#weight' => 200,
    ];
    $form['clean']['op'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clean environment'),
      '#submit' => ['tester_clean_environment'],
    ];

    // Do not needlessly re-execute a full test discovery if the user input
    // already contains an explicit list of test classes to run.
    $user_input = $form_state->getUserInput();
    if (!empty($user_input['tests'])) {
      return $form;
    }

    // JavaScript-only table filters.
    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['table-filter', 'js-show'],
      ],
    ];
    $form['filters']['text'] = [
      '#type' => 'search',
      '#title' => $this->t('Search'),
      '#size' => 30,
      '#placeholder' => $this->t('Enter test name…'),
      '#attributes' => [
        'class' => ['table-filter-text'],
        'data-table' => '#tester-test-form',
        'autocomplete' => 'off',
        'title' => $this->t('Enter at least 3 characters of the test name or description to filter by.'),
      ],
    ];

    $form['tests'] = [
      '#cache' => [
        'keys' => ['tester_ui_table'],
        'contexts' => ['tester_test_discovery'],
      ],
      '#type' => 'table',
      '#id' => 'tester-form-table',
      '#tableselect' => TRUE,
      '#header' => [
        ['data' => $this->t('Test'), 'class' => ['tester-test-label']],
        ['data' => $this->t('Description'), 'class' => ['tester-test-description']],
        ['data' => $this->t('Type'), 'class' => ['tester-test-type']],
      ],
      '#empty' => $this->t('No tests to display.'),
      '#attached' => [
        'library' => [
          'tester/tester',
        ],
      ],
    ];

    // Define the images used to expand/collapse the test groups.
    $image_collapsed = [
      '#theme' => 'image',
      '#uri' => 'core/misc/menu-collapsed.png',
      '#width' => '7',
      '#height' => '7',
      '#alt' => $this->t('Expand'),
      '#title' => $this->t('Expand'),
      '#suffix' => '<a href="#" class="tester-collapse">(' . $this->t('Expand') . ')</a>',
    ];
    $image_extended = [
      '#theme' => 'image',
      '#uri' => 'core/misc/menu-expanded.png',
      '#width' => '7',
      '#height' => '7',
      '#alt' => $this->t('Collapse'),
      '#title' => $this->t('Collapse'),
      '#suffix' => '<a href="#" class="tester-collapse">(' . $this->t('Collapse') . ')</a>',
    ];
    $form['tests']['#attached']['drupalSettings']['tester']['images'] = [
      (string) $this->renderer->renderPlain($image_collapsed),
      (string) $this->renderer->renderPlain($image_extended),
    ];

    // Generate the list of tests arranged by group.
    $groups = $this->testDiscovery->getTestClasses();
    foreach ($groups as $group => $tests) {
      $form['tests'][$group] = [
        '#attributes' => ['class' => ['tester-group']],
      ];

      // Make the class name safe for output on the page by replacing all
      // non-word/decimal characters with a dash (-).
      $group_class = 'module-' . strtolower(trim(preg_replace("/[^\w\d]/", "-", $group)));

      // Override tableselect column with custom selector for this group.
      // This group-select-all checkbox is injected via JavaScript.
      $form['tests'][$group]['select'] = [
        '#wrapper_attributes' => [
          'id' => $group_class,
          'class' => ['tester-group-select-all'],
        ],
      ];
      $form['tests'][$group]['title'] = [
        // Expand/collapse image.
        '#prefix' => '<div class="tester-image" id="tester-test-group-' . $group_class . '"></div>',
        '#markup' => '<label for="' . $group_class . '-group-select-all">' . $group . '</label>',
        '#wrapper_attributes' => [
          'class' => ['tester-group-label'],
        ],
      ];
      $form['tests'][$group]['description'] = [
        '#markup' => '&nbsp;',
        '#wrapper_attributes' => [
          'class' => ['tester-group-description'],
        ],
      ];
      $form['tests'][$group]['type'] = [
        '#markup' => '&nbsp;',
        '#wrapper_attributes' => [
          'class' => ['tester-group-type'],
        ],
      ];

      // Cycle through each test within the current group.
      foreach ($tests as $class => $info) {
        $form['tests'][$class] = [
          '#attributes' => ['class' => [$group_class . '-test', 'js-hide']],
        ];
        $form['tests'][$class]['title'] = [
          '#type' => 'label',
          '#title' => $info['name'],
          '#wrapper_attributes' => [
            'class' => ['tester-test-label', 'table-filter-text-source'],
          ],
        ];
        $form['tests'][$class]['description'] = [
          '#prefix' => '<div class="description">',
          '#plain_text' => $info['description'],
          '#suffix' => '</div>',
          '#wrapper_attributes' => [
            'class' => ['tester-test-description', 'table-filter-text-source'],
          ],
        ];
        $form['tests'][$class]['type'] = [
          '#prefix' => '<div class="type">',
          '#plain_text' => $info['type'],
          '#suffix' => '</div>',
          '#wrapper_attributes' => [
            'class' => ['tester-test-type', 'table-filter-text-source'],
          ],
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Test discovery does not run upon form submission.
    $this->testDiscovery->registerTestNamespaces();

    // This form accepts arbitrary user input for 'tests'.
    // An invalid value will cause the $class_name lookup below to die with a
    // fatal error. Regular user access mechanisms to this form are intact.
    // The only validation effectively being skipped is the validation of
    // available checkboxes vs. submitted checkboxes.
    // @todo Refactor Form API to allow to POST values without constructing the
    //   entire form more easily, BUT retaining routing access security and
    //   retaining Form API CSRF #token security validation, and without having
    //   to rely on form caching.
    $user_input = $form_state->getUserInput();
    if ($form_state->isValueEmpty('tests') && !empty($user_input['tests'])) {
      $form_state->setValue('tests', $user_input['tests']);
    }

    $tests_list = array_filter($form_state->getValue('tests'));
    if (!empty($tests_list)) {
      $test_id = $this->runTests($tests_list);
      $form_state->setRedirect('tester.result_form', ['test_id' => $test_id]);
    }
  }

  /**
   * Runs tests.
   *
   * @param array[] $test_list
   *   List of test classes to run.
   *
   * @return int
   *   The test ID.
   */
  function runTests(array $test_list): int {

    $test_run_results_storage = tester_test_run_results_storage();
    $test_class = reset($test_list);
    $test_info = $this->testDiscovery->getTestClassInfo($test_class);
    $test_run = TestRun::createNew($test_run_results_storage);

    // Clear out the previous verbose files.
    try {
      \Drupal::service('file_system')->deleteRecursive('public://tester/verbose');
    }
    catch (FileException $e) {
      // Ignore failed deletes.
    }

    $batch = [
      'title' => t('Running tests'),
      'operations' => [
        ['_tester_batch_operation', [$test_run->id(), $test_list]],
      ],
      'finished' => '_tester_batch_finished',
//      'progress_message' => 'bingobongo',
      'library' => ['tester/tester'],
//      'init_message' => t('Processing test @num of @max - %test.', ['%test' => $test_info['name'], '@num' => '1', '@max' => count($test_list)]),
    ];
    batch_set($batch);

    return $test_run->id();
  }

}
