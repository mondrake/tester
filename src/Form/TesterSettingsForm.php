<?php

namespace Drupal\tester\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Tester settings for this site.
 *
 * @internal
 */
class TesterSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tester_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['tester.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('tester.settings');
    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General'),
      '#open' => TRUE,
    ];
    $form['general']['tester_clear_results'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Clear results after each complete test suite run'),
      '#description' => $this->t('By default Tester will clear the results after they have been viewed on the results page, but in some cases it may be useful to leave the results in the database. The results can then be viewed at <em>admin/config/development/testing/results/[test_id]</em>. The test ID can be found in the database, tester table, or kept track of when viewing the results the first time. Additionally, some modules may provide more analysis or features that require this setting to be disabled.'),
      '#default_value' => $config->get('clear_results'),
    ];
    $form['general']['tester_verbose'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Provide verbose information when running tests'),
      '#description' => $this->t('The verbose data will be printed along with the standard assertions and is useful for debugging. The verbose data will be erased between each test suite run. The verbose data output is very detailed and should only be used when debugging.'),
      '#default_value' => $config->get('verbose'),
    ];

    $form['runner_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Runner options'),
    ];
    $form['runner_options']['mapping'] = [
      '#type' => 'details',
      '#collapsible' => TRUE,
      '#open' => TRUE,
      '#title' => $this->t('Enable/disable image formats'),
      '#description' => $this->t("Edit the map below to enable/disable image formats. Enabled image file extensions will be determined by the enabled formats, through their MIME types. More information in the module's README.txt"),
    ];
    $form['runner_options']['mapping']['textbox'] = [
      '#type' => 'textarea',
      '#rows' => 15,
      '#default_value' => 'boing',

//      '#default_value' => Yaml::encode($config->get('image_formats')),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('tester.settings');
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('tester.settings')
      ->set('clear_results', $form_state->getValue('tester_clear_results'))
      ->set('verbose', $form_state->getValue('tester_verbose'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
