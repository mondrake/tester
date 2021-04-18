<?php

namespace Drupal\tester\Form;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
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
      '#description' => $this->t('By default Tester will clear the results after they have been viewed on the results page, but in some cases it may be useful to leave the results in the database. The results can then be viewed at <em>admin/config/development/testing/results/[test_id]</em>.'),
      '#default_value' => $config->get('clear_results'),
    ];

    $form['runner_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Execution options'),
      '#collapsible' => TRUE,
      '#open' => TRUE,
    ];
    $form['runner_options']['config_yaml'] = [
      '#type' => 'textarea',
      '#rows' => 15,
      '#attributes' => ['data-yaml-editor' => 'true'],
      '#title' => $this->t('PHPUnit'),
      '#description' => $this->t("Edit the map below to configure the PHPUnit CLI runner environment."),
      '#default_value' => $config->get('phpunit.config_yaml'),
//      '#default_value' => Yaml::encode($config->get('image_formats')),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('tester.settings');

    try {
      $config_yaml = Yaml::decode($form_state->getValue('config_yaml'));
    }
    catch (InvalidDataTypeException $e) {
      // Invalid YAML detected, show details.
      $form_state->setErrorByName('config_yaml', $this->t("YAML syntax error: @error", ['@error' => $e->getMessage()]));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('tester.settings')
      ->set('clear_results', $form_state->getValue('tester_clear_results'))
      ->set('phpunit.config_yaml', $form_state->getValue('config_yaml'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
