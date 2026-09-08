<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AccountController extends ControllerBase {

  public const PASSWORD_TOKEN_ID = 'merdpos_change_password_v1';

  public function __construct(
    private readonly PortalGatewayClientInterface $gateway,
    private readonly RequestStack $requestStack,
    private readonly CsrfTokenGenerator $csrf,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('merdpos_core.portal_gateway'),
      $container->get('request_stack'),
      $container->get('csrf_token'),
    );
  }

  public function changePassword(): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request || !$request->isMethod('POST')) throw new AccessDeniedHttpException();
    $returnUrl = $this->returnUrl((string) $request->request->get('return_url', '/merdpos'));

    $token = (string) $request->request->get('form_token', '');
    if (!$this->csrf->validate($token, self::PASSWORD_TOKEN_ID)) {
      $this->messenger()->addError('This password form expired. Refresh the page and try again.');
      return new RedirectResponse($returnUrl);
    }

    $stateResult = $this->gateway->call('beta_state', 'GET');
    $state = is_array($stateResult['payload'] ?? NULL) ? $stateResult['payload'] : [];
    $permissions = $this->permissionKeys($state['permissions'] ?? []);
    if (($stateResult['status'] ?? '') !== 'ok' || !in_array('password.change_own', $permissions, true)) {
      throw new AccessDeniedHttpException('MERDPOS password.change_own permission is required.');
    }

    $current = trim((string) $request->request->get('current_password', ''));
    $new = trim((string) $request->request->get('new_password', ''));
    $confirm = trim((string) $request->request->get('confirm_password', ''));
    if (!preg_match('/^\d{1,20}$/', $current)) {
      $this->messenger()->addError('Enter your current numeric password.');
      return new RedirectResponse($returnUrl);
    }
    if (!preg_match('/^\d{6,20}$/', $new)) {
      $this->messenger()->addError('New password must contain 6–20 digits.');
      return new RedirectResponse($returnUrl);
    }
    if (!hash_equals($new, $confirm)) {
      $this->messenger()->addError('New passwords do not match.');
      return new RedirectResponse($returnUrl);
    }
    if (hash_equals($current, $new)) {
      $this->messenger()->addError('Choose a different password.');
      return new RedirectResponse($returnUrl);
    }

    $result = $this->gateway->call('change_password', 'POST', [], [
      'current_password' => $current,
      'new_password' => $new,
      'confirm_password' => $confirm,
    ]);
    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    if (($result['status'] ?? '') === 'ok' && !empty($payload['success'])) {
      $this->messenger()->addStatus('Password changed successfully.');
    }
    else {
      $error = trim((string) ($payload['error'] ?? $payload['message'] ?? $result['message'] ?? 'Password could not be changed.'));
      $this->messenger()->addError($error ?: 'Password could not be changed.');
    }
    return new RedirectResponse($returnUrl);
  }

  private function returnUrl(string $value): string {
    $value = trim(str_replace(["\r", "\n"], '', $value));
    if ($value === '' || !preg_match('#^/merdpos(?:[/?#]|$)#', $value)) return '/merdpos';
    return $value;
  }

  private function permissionKeys(mixed $value): array {
    if (!is_array($value)) return [];
    if (array_is_list($value)) return array_values(array_filter(array_map('strval', $value)));
    $keys = [];
    foreach ($value as $key => $enabled) if (is_string($key) && $enabled) $keys[] = $key;
    return array_values(array_unique($keys));
  }
}