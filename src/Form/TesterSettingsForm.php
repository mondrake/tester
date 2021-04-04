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

    $form['httpauth'] = [
      '#type' => 'details',
      '#title' => $this->t('HTTP authentication'),
      '#description' => $this->t('HTTP auth settings to be used by the Tester browser during testing. Useful when the site requires basic HTTP authentication.'),
    ];
    $form['httpauth']['tester_httpauth_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Method'),
      '#options' => [
        CURLAUTH_BASIC => $this->t('Basic'),
        CURLAUTH_DIGEST => $this->t('Digest'),
        CURLAUTH_GSSNEGOTIATE => $this->t('GSS negotiate'),
        CURLAUTH_NTLM => $this->t('NTLM'),
        CURLAUTH_ANY => $this->t('Any'),
        CURLAUTH_ANYSAFE => $this->t('Any safe'),
      ],
      '#default_value' => $config->get('httpauth.method'),
    ];
    $username = $config->get('httpauth.username');
    $password = $config->get('httpauth.password');
    $form['httpauth']['tester_httpauth_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $username,
    ];
    if (!empty($username) && !empty($password)) {
      $form['httpauth']['tester_httpauth_username']['#description'] = $this->t('Leave this blank to delete both the existing username and password.');
    }
    $form['httpauth']['tester_httpauth_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
    ];
    if ($password) {
      $form['httpauth']['tester_httpauth_password']['#description'] = $this->t('To change the password, enter the new password here.');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('tester.settings');
    // If a username was provided but a password wasn't, preserve the existing
    // password.
    if (!$form_state->isValueEmpty('tester_httpauth_username') && $form_state->isValueEmpty('tester_httpauth_password')) {
      $form_state->setValue('tester_httpauth_password', $config->get('httpauth.password'));
    }

    // If a password was provided but a username wasn't, the credentials are
    // incorrect, so throw an error.
    if ($form_state->isValueEmpty('tester_httpauth_username') && !$form_state->isValueEmpty('tester_httpauth_password')) {
      $form_state->setErrorByName('tester_httpauth_username', $this->t('HTTP authentication credentials must include a username in addition to a password.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('tester.settings')
      ->set('clear_results', $form_state->getValue('tester_clear_results'))
      ->set('verbose', $form_state->getValue('tester_verbose'))
      ->set('httpauth.method', $form_state->getValue('tester_httpauth_method'))
      ->set('httpauth.username', $form_state->getValue('tester_httpauth_username'))
      ->set('httpauth.password', $form_state->getValue('tester_httpauth_password'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
