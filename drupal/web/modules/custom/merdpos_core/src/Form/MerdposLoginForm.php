<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\merdpos_core\Auth\MerdposAuthenticatorInterface;
use Drupal\merdpos_core\Auth\MerdposIdentityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

final class MerdposLoginForm extends FormBase {

  public function __construct(
    private readonly MerdposAuthenticatorInterface $authenticator,
    private readonly MerdposIdentityManager $identityManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('merdpos_core.authenticator'),
      $container->get('merdpos_core.identity_manager'),
    );
  }

  public function getFormId(): string {
    return 'merdpos_login_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'merdpos-login-form';
    $form['#attributes']['autocomplete'] = 'off';
    $form['user_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User ID'),
      '#required' => TRUE,
      '#maxlength' => 20,
      '#attributes' => [
        'inputmode' => 'numeric',
        'pattern' => '[0-9]*',
        'placeholder' => $this->t('Numeric User ID'),
        'autocomplete' => 'username',
        'autofocus' => 'autofocus',
      ],
    ];
    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#required' => TRUE,
      '#maxlength' => 20,
      '#attributes' => [
        'inputmode' => 'numeric',
        'pattern' => '[0-9]*',
        'placeholder' => $this->t('Numeric Password'),
        'autocomplete' => 'current-password',
      ],
    ];
    $destination = $this->getRequest()->query->get('destination');
    if (is_string($destination) && $destination !== '') {
      $form['destination'] = ['#type' => 'hidden', '#value' => $destination];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Enter MERDPOS'),
      '#attributes' => ['class' => ['merdpos-login-submit']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $userId = preg_replace('/\D+/', '', (string) $form_state->getValue('user_id')) ?? '';
    $password = preg_replace('/\D+/', '', (string) $form_state->getValue('password')) ?? '';
    $result = $this->authenticator->authenticate($userId, $password);
    if (($result['status'] ?? '') !== 'ok' || !is_array($result['identity'] ?? NULL)) {
      $form_state->setErrorByName('user_id', (string) ($result['message'] ?? $this->t('Invalid User ID or Password.')));
      return;
    }
    $form_state->set('merdpos_identity', $result['identity']);
    $form_state->setValue('password', '');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $identity = $form_state->get('merdpos_identity');
    if (!is_array($identity)) return;
    try {
      $account = $this->identityManager->synchronize($identity);
      $destination = $form_state->getValue('destination');
      if (is_string($destination) && $destination !== '') {
        $this->getRequest()->query->set('destination', $destination);
      }
      else {
        $route = $account->hasPermission('view merdpos management dashboard')
          ? 'merdpos_core.dashboard'
          : 'merdpos_core.reports';
        $form_state->setRedirect($route);
      }
      \user_login_finalize($account);
    }
    catch (Throwable) {
      $this->messenger()->addError($this->t('MERDPOS signed in, but the Drupal session could not be created. Try again.'));
    }
  }

}
