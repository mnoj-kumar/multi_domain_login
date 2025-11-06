<?php

namespace Drupal\multi_domain_login\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * The MultiDomainLoginForm Class.
 */
class MultiDomainLoginForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'multi_domain_login';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'multi_domain_login.settings',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * This form should reflect the fields in the zone content type from the
   * police_zones module. Settings will be stored localy in a config storage,
   * but will also be synched to the central storage.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('multi_domain_login.settings');

    $form['#tree'] = TRUE;
    $form['#title'] = $this->t('Multi domain login settings');

    $form['timeout'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Timeout'),
      '#description' => $this->t('Time in seconds the login urls are valid.'),
      '#default_value' => $config->get('timeout') ? $config->get('timeout') : '',
    ];

    $form['redirect_success'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect on success'),
      '#description' => $this->t('The url to redirect to when login succeeds.'),
      '#default_value' => $config->get('redirect_success'),
    ];

    $form['redirect_error'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect on error'),
      '#description' => $this->t('The url to redirect to when login fails.'),
      '#default_value' => $config->get('redirect_error'),
    ];

    $form['force_logout'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force logout'),
      '#description' => $this->t('Forces logout on the other domain if visitor was previously logged in with another user session.'),
      '#default_value' => $config->get('force_logout'),
    ];

    $form['enable_extra_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable extra logging'),
      '#default_value' => $config->get('enable_extra_logging'),
    ];

    $form['domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Domains'),
      '#description' => $this->t('Enter each domain on a separate line including the url scheme (example: https://www.mydomain.com).'),
      '#default_value' => implode("\n", $config->get('domains')),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->addCleanValueKey('actions');
    $values = $form_state->cleanValues()->getValues();

    // Clean domains value.
    $values['domains'] = str_replace("\r\n", "\n", trim($values['domains']));
    $values['domains'] = explode("\n", $values['domains']);

    $this->configFactory->getEditable('multi_domain_login.settings')->setData($values)->save();

    parent::submitForm($form, $form_state);
  }

}
