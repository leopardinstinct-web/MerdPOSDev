<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\merdpos_core\Integration\AdministrationOnboardingProvisioner;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AdministrationController extends ControllerBase {

  private const TOKEN_ID = 'merdpos-administration-write-v1';

  public function __construct(
    private readonly PortalGatewayClientInterface $gateway,
    private readonly AdministrationOnboardingProvisioner $onboarding,
    private readonly RequestStack $requestStack,
    private readonly CsrfTokenGenerator $csrf,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('merdpos_core.portal_gateway'),
      $container->get('merdpos_core.administration_onboarding'),
      $container->get('request_stack'),
      $container->get('csrf_token'),
    );
  }

  public function administration(): array|RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request) return $this->errorBuild('Request context is unavailable.');

    $context = $this->gateway->call('client_context');
    $contextPayload = $context['status'] === 'ok' && is_array($context['payload'] ?? null)
      ? $context['payload'] : [];
    $homeClientId = max(0, (int) ($contextPayload['home_client_id'] ?? $contextPayload['active_client_id'] ?? 0));
    $canSelectClient = !empty($contextPayload['can_select_client']);
    $selectableClients = is_array($contextPayload['clients'] ?? null) ? $contextPayload['clients'] : [];

    $requestedClientId = filter_var($request->query->get('client_id'), FILTER_VALIDATE_INT);
    $selectedClientId = $homeClientId;
    if ($canSelectClient && $requestedClientId !== false && $requestedClientId > 0) {
      foreach ($selectableClients as $client) {
        if ((int) ($client['id'] ?? 0) === (int) $requestedClientId) {
          $selectedClientId = (int) $requestedClientId;
          break;
        }
      }
    }

    $directoryResult = $this->gateway->call('admin_directory', 'GET', [], [], $selectedClientId ?: NULL);
    $directory = $directoryResult['status'] === 'ok' && is_array($directoryResult['payload'] ?? null)
      ? $directoryResult['payload'] : [];
    if ($request->isMethod('POST')) return $this->handlePost($request, $selectedClientId, $directory);

    $clientsResult = $this->gateway->call('clients');
    $clientsPayload = $clientsResult['status'] === 'ok' && is_array($clientsResult['payload'] ?? null)
      ? $clientsResult['payload'] : [];

    $selectedClient = null;
    foreach ($selectableClients as $client) {
      if ((int) ($client['id'] ?? 0) === $selectedClientId) {
        $selectedClient = $client;
        break;
      }
    }
    if ($selectedClient === null && is_array($contextPayload['client'] ?? null)) {
      $selectedClient = $contextPayload['client'];
    }

    return [
      '#theme' => 'merdpos_administration',
      '#directory' => $directory,
      '#clients' => is_array($clientsPayload['clients'] ?? null) ? $clientsPayload['clients'] : [],
      '#can_manage_clients' => $clientsResult['status'] === 'ok',
      '#can_select_client' => $canSelectClient,
      '#selectable_clients' => $selectableClients,
      '#selected_client_id' => $selectedClientId,
      '#selected_client' => $selectedClient,
      '#form_token' => $this->csrf->get(self::TOKEN_ID),
      '#gateway_status' => $directoryResult['status'] ?? 'unavailable',
      '#attached' => ['library' => ['merdpos_core/administration']],
      '#cache' => ['contexts' => ['user', 'url.query_args:client_id', 'url.query_args:tab'], 'max-age' => 0],
    ];
  }

  private function handlePost(Request $request, int $selectedClientId, array $directory): RedirectResponse {
    $token = (string) $request->request->get('form_token', '');
    if (!$this->csrf->validate($token, self::TOKEN_ID)) {
      $this->messenger()->addError($this->t('Your form session expired. Refresh and try again.'));
      return $this->redirectBack($selectedClientId);
    }

    $action = (string) $request->request->get('entity_action', '');
    $result = match ($action) {
      'onboard_client' => $this->onboardClient($request),
      'save_client' => $this->saveClient($request),
      'save_store' => $this->saveStore($request, $selectedClientId, $directory),
      'save_employee' => $this->saveEmployee($request, $selectedClientId),
      default => ['status' => 'invalid', 'message' => 'Unsupported administration action.'],
    };

    $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
    $redirectClientId = max(0, (int) ($payload['redirect_client_id'] ?? $selectedClientId));
    $redirectTab = isset($payload['redirect_tab']) ? (string) $payload['redirect_tab'] : NULL;
    if (($result['status'] ?? '') === 'ok' && !empty($payload['success'])) {
      $message = trim((string) ($payload['message'] ?? 'Saved.')) ?: 'Saved.';
      $this->messenger()->addStatus($message);
    }
    else {
      $message = trim((string) ($payload['error'] ?? $result['message'] ?? 'The change could not be saved.'));
      $this->messenger()->addError($message ?: 'The change could not be saved.');
    }
    return $this->redirectBack($redirectClientId, $redirectTab);
  }

  private function onboardClient(Request $request): array {
    return $this->onboarding->provision($request->request->all());
  }

  private function saveClient(Request $request): array {
    return $this->gateway->call('clients', 'POST', [], [
      'action' => 'save_client',
      'id' => $this->nullablePositiveInt($request->request->get('id')),
      'name' => trim((string) $request->request->get('name', '')),
      'client_code' => trim((string) $request->request->get('client_code', '')),
      'status' => (string) $request->request->get('status', 'active'),
    ]);
  }

  private function saveStore(Request $request, int $selectedClientId, array $directory): array {
    $body = [
      'action' => 'save_store',
      'id' => $this->nullablePositiveInt($request->request->get('id')),
      'store_name' => trim((string) $request->request->get('store_name', '')),
      'status' => (string) $request->request->get('status', 'active'),
      'week_start_day' => max(1, min(7, (int) $request->request->get('week_start_day', 1))),
    ];
    $canManageProfile = !empty($directory['permissions']['stores.profile.manage']);
    $existingStore = NULL;
    if (!$canManageProfile && $body['id'] !== NULL) {
      foreach (($directory['stores'] ?? []) as $store) {
        if (is_array($store) && (int) ($store['id'] ?? 0) === $body['id']) { $existingStore = $store; break; }
      }
      if ($existingStore === NULL) return ['status'=>'invalid','message'=>'Store context is stale. Refresh and try again.'];
    }
    foreach (($directory['store_edit_fields'] ?? []) as $field) {
      if (!is_array($field) || empty($field['name'])) continue;
      $name = (string) $field['name'];
      if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) continue;
      if ($canManageProfile) $body[$name] = trim((string) $request->request->get($name, ''));
      elseif (is_array($existingStore)) $body[$name] = $existingStore[$name] ?? '';
    }
    return $this->gateway->call('admin_directory', 'POST', [], $body, $selectedClientId ?: NULL);
  }

  private function saveEmployee(Request $request, int $selectedClientId): array {
    $storeIds = $request->request->all('store_ids');
    $storeIds = array_values(array_filter(array_map(
      static fn (mixed $value): int => max(0, (int) $value),
      is_array($storeIds) ? $storeIds : [],
    )));
    $body = [
      'action' => 'save_employee',
      'id' => $this->nullablePositiveInt($request->request->get('id')),
      'full_name' => trim((string) $request->request->get('full_name', '')),
      'user_id' => preg_replace('/\D+/', '', (string) $request->request->get('user_id', '')),
      'client_role_id' => $this->nullablePositiveInt($request->request->get('client_role_id')),
      'employee_type' => strtoupper(trim((string) $request->request->get('employee_type', 'USER'))),
      'status' => (string) $request->request->get('status', 'active'),
      'hourly_rate' => trim((string) $request->request->get('hourly_rate', '')),
      'rate_effective_date' => trim((string) $request->request->get('rate_effective_date', '')),
      'new_password' => preg_replace('/\D+/', '', (string) $request->request->get('new_password', '')),
      'store_access_mode' => (string) $request->request->get('store_access_mode', 'all'),
      'store_ids' => $storeIds,
    ];
    return $this->gateway->call('admin_directory', 'POST', [], $body, $selectedClientId ?: NULL);
  }

  private function nullablePositiveInt(mixed $value): ?int {
    if ($value === null || $value === '') return NULL;
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    return $parsed !== false && $parsed > 0 ? (int) $parsed : NULL;
  }

  private function redirectBack(int $clientId, ?string $tab = NULL): RedirectResponse {
    $query = $clientId > 0 ? ['client_id' => $clientId] : [];
    if ($tab !== NULL && in_array($tab, ['onboarding','clients','stores','workforce'], true)) $query['tab'] = $tab;
    $options = $query ? ['query' => $query] : [];
    return new RedirectResponse(Url::fromRoute('merdpos_core.administration', [], $options)->toString());
  }

  private function errorBuild(string $message): array {
    return [
      '#markup' => '<div class="merdpos-admin-error">' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>',
      '#cache' => ['max-age' => 0],
    ];
  }

}
