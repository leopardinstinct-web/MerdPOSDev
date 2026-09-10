<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use JsonException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AccountController extends ControllerBase {

  public const PASSWORD_TOKEN_ID = 'merdpos_change_password_v1';
  public const WORKING_CLIENT_TOKEN_ID = 'merdpos_working_client_v1';
  public const WORKING_ROLE_TOKEN_ID = 'merdpos_working_role_v1';
  public const TIMESHEET_SYNC_TOKEN_ID = 'merdpos_timesheet_google_sync_v1';

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

  public function selectWorkingClient(): JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request || !$request->isMethod('POST')) throw new AccessDeniedHttpException();
    if (!$this->csrf->validate(trim((string) $request->headers->get('X-MERDPOS-CSRF', '')), self::WORKING_CLIENT_TOKEN_ID)) {
      return new JsonResponse(['success'=>false, 'error'=>'Your client selection session expired. Refresh and try again.'], 403);
    }
    $input = $this->jsonInput($request);
    if ($input === NULL) return new JsonResponse(['success'=>false, 'error'=>'Invalid Working client request.'], 400);
    $clientId = filter_var($input['client_id'] ?? NULL, FILTER_VALIDATE_INT);
    if ($clientId === false || $clientId <= 0) return new JsonResponse(['success'=>false, 'error'=>'Choose a valid client.'], 422);

    $contextResult = $this->gateway->call('client_context', 'GET');
    $context = is_array($contextResult['payload'] ?? NULL) ? $contextResult['payload'] : [];
    if (($contextResult['status'] ?? '') !== 'ok' || empty($context['can_select_client'])) {
      throw new AccessDeniedHttpException('Only the actual DEV identity can switch the Working client.');
    }
    $selected = NULL;
    foreach (($context['clients'] ?? []) as $client) {
      if (is_array($client) && (int) ($client['id'] ?? 0) === (int) $clientId) { $selected = $client; break; }
    }
    if ($selected === NULL) return new JsonResponse(['success'=>false, 'error'=>'Active client not found.'], 404);

    $result = $this->gateway->call('client_context', 'POST', [], ['action'=>'select_client', 'client_id'=>(int) $clientId]);
    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    if (($result['status'] ?? '') === 'ok' && !empty($payload['success'])) {
      $request->getSession()->set('merdpos_context_client_id', (int) $clientId);
      $name = trim((string) ($selected['name'] ?? ('Client ' . $clientId)));
      return new JsonResponse(['success'=>true, 'active_client_id'=>(int) $clientId, 'client'=>$selected, 'message'=>'Working client changed to ' . $name . '.']);
    }
    return $this->gatewayError($result, $payload, 'Working client could not be changed.');
  }

  public function selectWorkingRole(): JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request || !$request->isMethod('POST')) throw new AccessDeniedHttpException();
    if (!$this->csrf->validate(trim((string) $request->headers->get('X-MERDPOS-CSRF', '')), self::WORKING_ROLE_TOKEN_ID)) {
      return new JsonResponse(['success'=>false, 'error'=>'Your role selection session expired. Refresh and try again.'], 403);
    }
    $input = $this->jsonInput($request);
    if ($input === NULL) return new JsonResponse(['success'=>false, 'error'=>'Invalid Working Role request.'], 400);
    $roleKey = strtoupper(trim((string) ($input['role_key'] ?? '')));
    if (!in_array($roleKey, ['DEV','ADMIN','SUPER','USER'], true)) return new JsonResponse(['success'=>false, 'error'=>'Choose a valid Working Role.'], 422);

    $contextResult = $this->gateway->call('client_context', 'GET');
    $context = is_array($contextResult['payload'] ?? NULL) ? $contextResult['payload'] : [];
    if (($contextResult['status'] ?? '') !== 'ok' || empty($context['can_select_client']) || strtoupper((string) ($context['actual_role'] ?? '')) !== 'DEV') {
      throw new AccessDeniedHttpException('Only the actual DEV identity can switch the Working Role.');
    }
    $request->getSession()->set('merdpos_context_role_key', $roleKey);
    return new JsonResponse(['success'=>true, 'role_key'=>$roleKey, 'message'=>'Working Role changed to ' . ucfirst(strtolower($roleKey)) . '.']);
  }

  public function syncGoogleTimeSheet(): JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request || !$request->isMethod('POST')) throw new AccessDeniedHttpException();
    if (!$this->csrf->validate(trim((string) $request->headers->get('X-MERDPOS-CSRF', '')), self::TIMESHEET_SYNC_TOKEN_ID)) {
      return new JsonResponse(['success'=>false, 'error'=>'Your Time Sheet sync session expired. Refresh and try again.'], 403);
    }
    $input = $this->jsonInput($request);
    if ($input === NULL) return new JsonResponse(['success'=>false, 'error'=>'Invalid Time Sheet sync request.'], 400);
    $clientId = filter_var($input['client_id'] ?? NULL, FILTER_VALIDATE_INT);
    if ($clientId === false || $clientId <= 0) return new JsonResponse(['success'=>false, 'error'=>'Choose a valid Working client.'], 422);

    $contextResult = $this->gateway->call('client_context', 'GET');
    $context = is_array($contextResult['payload'] ?? NULL) ? $contextResult['payload'] : [];
    if (($contextResult['status'] ?? '') !== 'ok' || empty($context['can_select_client'])) {
      throw new AccessDeniedHttpException('Only the actual DEV identity can refresh Google Time Sheet data.');
    }
    $activeClientId = (int) ($context['active_client_id'] ?? 0);
    if ($activeClientId <= 0 || $activeClientId !== (int) $clientId) {
      return new JsonResponse(['success'=>false, 'error'=>'Working client changed before sync. Reload the account menu and try again.'], 409);
    }

    @set_time_limit(200);
    $result = $this->gateway->call('timesheet_google_refresh', 'POST', [], ['action'=>'refresh_timesheet', 'client_id'=>(int) $clientId], (int) $clientId);
    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    if (($result['status'] ?? '') === 'ok' && !empty($payload['success'])) return new JsonResponse($payload);
    return $this->gatewayError($result, $payload, 'Time Sheet could not be synced from Google.');
  }

  private function jsonInput(Request $request): ?array {
    try { $input = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return NULL; }
    return is_array($input) ? $input : NULL;
  }

  private function gatewayError(array $result, array $payload, string $fallback): JsonResponse {
    $message = trim((string) ($payload['error'] ?? $payload['message'] ?? $result['message'] ?? $fallback));
    $http = (int) ($result['http_status'] ?? 503);
    if ($http < 400 || $http > 599) $http = ($result['status'] ?? '') === 'forbidden' ? 403 : 503;
    return new JsonResponse(['success'=>false, 'error'=>$message ?: $fallback], $http);
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