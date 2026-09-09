<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\merdpos_core\Integration\AdministrationOnboardingProvisioner;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AdministrationController extends ControllerBase {

  private const TOKEN_ID = 'merdpos-administration-write-v1';
  private const LEGACY_TOKEN_ID = 'merdpos-legacy-migration-v1';

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
    $activeClientId = max(0, (int) ($contextPayload['active_client_id'] ?? $homeClientId));
    $canSelectClient = !empty($contextPayload['can_select_client']);
    $selectableClients = is_array($contextPayload['clients'] ?? null) ? $contextPayload['clients'] : [];

    $requestedClientId = filter_var($request->query->get('client_id'), FILTER_VALIDATE_INT);
    $selectedClientId = $activeClientId ?: $homeClientId;
    if ($canSelectClient && $requestedClientId !== false && $requestedClientId > 0) {
      foreach ($selectableClients as $client) {
        if ((int) ($client['id'] ?? 0) === (int) $requestedClientId) {
          $selectedClientId = (int) $requestedClientId;
          $request->getSession()->set('merdpos_context_client_id', $selectedClientId);
          break;
        }
      }
    }

    $directoryResult = $this->gateway->call('admin_directory', 'GET', [], [], $selectedClientId ?: NULL);
    $directory = $directoryResult['status'] === 'ok' && is_array($directoryResult['payload'] ?? null)
      ? $directoryResult['payload'] : [];
    $requestedTab = $this->requestedTab($request);
    if ($request->isMethod('POST')) return $this->handlePost($request, $selectedClientId, $directory, $requestedTab);

    $storeIdentityResult = $selectedClientId > 0
      ? $this->gateway->call('store_identity', 'GET', [], [], $selectedClientId)
      : ['status'=>'invalid','payload'=>[]];
    $storeIdentityState = ($storeIdentityResult['status'] ?? '') === 'ok' && is_array($storeIdentityResult['payload'] ?? null)
      ? $storeIdentityResult['payload'] : [];
    $storeIdentityAvailable = ($storeIdentityResult['status'] ?? '') === 'ok' && !empty($storeIdentityState['success']);
    if ($storeIdentityAvailable && is_array($directory['stores'] ?? null)) {
      $identityById = [];
      foreach (($storeIdentityState['stores'] ?? []) as $row) {
        if (is_array($row) && (int)($row['id'] ?? 0) > 0) $identityById[(int)$row['id']] = $row;
      }
      foreach ($directory['stores'] as &$store) {
        $sid = is_array($store) ? (int)($store['id'] ?? 0) : 0;
        if ($sid > 0 && isset($identityById[$sid])) $store = array_replace($store, $identityById[$sid]);
      }
      unset($store);
    }

    $rolesResult = $this->gateway->call('role_authority', 'GET', [], [], $selectedClientId ?: NULL);
    $roleState = $rolesResult['status'] === 'ok' && is_array($rolesResult['payload'] ?? null)
      ? $rolesResult['payload'] : [];
    $canManageRoles = $rolesResult['status'] === 'ok';

    $timingsResult = $this->gateway->call('store_timings', 'GET', [], [], $selectedClientId ?: NULL);
    $timingsPayload = $timingsResult['status'] === 'ok' && is_array($timingsResult['payload'] ?? null)
      ? $timingsResult['payload'] : [];
    $storeTimings = [];
    foreach (($timingsPayload['timings'] ?? []) as $row) {
      if (!is_array($row)) continue;
      $sid = max(0, (int) ($row['store_id'] ?? 0));
      $day = max(0, (int) ($row['day_of_week'] ?? 0));
      if ($sid > 0 && $day >= 1 && $day <= 7) $storeTimings[$sid][$day] = $row;
    }

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

    $canManageClients = $clientsResult['status'] === 'ok';
    $defaultsResult = $selectedClientId > 0
      ? $this->gateway->call('defaults', 'GET', [], [], $selectedClientId)
      : ['status'=>'invalid','payload'=>[]];
    $defaultsState = ($defaultsResult['status'] ?? '') === 'ok' && is_array($defaultsResult['payload'] ?? null)
      ? $defaultsResult['payload'] : [];
    $canManageDefaults = ($defaultsResult['status'] ?? '') === 'ok' && !empty($defaultsState['success']);
    $legacyProbe = $selectedClientId > 0
      ? $this->gateway->call('legacy_migration', 'GET', ['client_id'=>$selectedClientId], [], $selectedClientId)
      : ['status'=>'invalid','payload'=>[]];
    $canManageLegacy = ($legacyProbe['status'] ?? '') === 'ok' && !empty($legacyProbe['payload']['success']);
    $currentTab = $requestedTab ?? ($canManageClients ? 'onboarding' : 'stores');
    if (!$canManageClients && in_array($currentTab, ['onboarding', 'clients'], true)) $currentTab = 'stores';
    if (!$canManageRoles && $currentTab === 'roles') $currentTab = 'workforce';
    if (!$canManageDefaults && $currentTab === 'defaults') $currentTab = 'stores';

    return [
      '#theme' => 'merdpos_administration',
      '#directory' => $directory,
      '#clients' => is_array($clientsPayload['clients'] ?? null) ? $clientsPayload['clients'] : [],
      '#can_manage_clients' => $canManageClients,
      '#can_manage_defaults' => $canManageDefaults,
      '#store_identity_available' => $storeIdentityAvailable,
      '#defaults_state' => $defaultsState,
      '#can_manage_legacy' => $canManageLegacy,
      '#legacy_token' => $this->csrf->get(self::LEGACY_TOKEN_ID),
      '#legacy_url' => Url::fromRoute('merdpos_core.legacy_migration')->toString(),
      '#can_select_client' => $canSelectClient,
      '#selectable_clients' => $selectableClients,
      '#selected_client_id' => $selectedClientId,
      '#selected_client' => $selectedClient,
      '#current_tab' => $currentTab,
      '#role_state' => $roleState,
      '#can_manage_roles' => $canManageRoles,
      '#form_token' => $this->csrf->get(self::TOKEN_ID),
      '#gateway_status' => $directoryResult['status'] ?? 'unavailable',
      '#store_timings' => $storeTimings,
      '#timings_available' => $timingsResult['status'] === 'ok',
      '#timezone_options' => \DateTimeZone::listIdentifiers(),
      '#currency_options' => $this->currencyOptions(),
      '#attached' => ['library' => ['merdpos_core/administration']],
      '#cache' => ['contexts' => ['user', 'url.query_args:client_id', 'url.query_args:tab'], 'max-age' => 0],
    ];
  }

  public function legacyMigration(Request $request): JsonResponse {
    $clientId = $this->positiveInt($request->isMethod('GET') ? $request->query->get('client_id') : NULL);
    $input = [];
    if ($request->isMethod('POST')) {
      $token = trim((string) $request->headers->get('X-MERDPOS-CSRF', ''));
      if (!$this->csrf->validate($token, self::LEGACY_TOKEN_ID)) return new JsonResponse(['success'=>false,'error'=>'Your form session expired. Refresh and try again.'], 403);
      try { $input = json_decode((string) $request->getContent(), true, 32, JSON_THROW_ON_ERROR); }
      catch (\Throwable) { return new JsonResponse(['success'=>false,'error'=>'Invalid migration request.'], 400); }
      if (!is_array($input)) return new JsonResponse(['success'=>false,'error'=>'Invalid migration request.'], 400);
      $clientId = $this->positiveInt($input['client_id'] ?? NULL);
    }
    if ($clientId === NULL) return new JsonResponse(['success'=>false,'error'=>'Choose a valid client.'], 422);

    if ($request->isMethod('GET')) {
      return $this->legacyGatewayResponse($this->gateway->call('legacy_migration', 'GET', ['client_id'=>$clientId], [], $clientId));
    }
    if (!$request->isMethod('POST')) return new JsonResponse(['success'=>false,'error'=>'GET or POST required.'], 405);

    $action = strtolower(trim((string) ($input['action'] ?? '')));
    if (!in_array($action, ['save_sources','preview','sync','final'], true)) return new JsonResponse(['success'=>false,'error'=>'Unsupported legacy migration action.'], 422);
    if ($action === 'final') {
      $confirmation = trim((string) ($input['confirmation_client_code'] ?? ''));
      $preflight = $this->gateway->call('legacy_migration', 'GET', ['client_id'=>$clientId], [], $clientId);
      $expected = trim((string) ($preflight['payload']['client']['client_code'] ?? ''));
      if (($preflight['status'] ?? '') !== 'ok' || empty($preflight['payload']['success'])) return $this->legacyGatewayResponse($preflight);
      if ($expected === '' || !hash_equals($expected, $confirmation)) return new JsonResponse(['success'=>false,'error'=>'Final cutover cancelled: Client Code did not match.'], 422);
    }
    $body = ['action'=>$action, 'client_id'=>$clientId];
    if ($action === 'save_sources') {
      $attendance = is_array($input['attendance_sheets'] ?? NULL) ? $input['attendance_sheets'] : [];
      $body += [
        'attendance_spreadsheet_id'=>trim((string)($input['attendance_spreadsheet_id'] ?? '')),
        'attendance_sheets'=>$this->legacyAttendanceSheets($attendance),
        'financial_spreadsheet_id'=>trim((string)($input['financial_spreadsheet_id'] ?? '')),
        'financial_sheets'=>$this->legacyStringList($input['financial_sheets'] ?? []),
      ];
    }
    return $this->legacyGatewayResponse($this->gateway->call('legacy_migration', 'POST', [], $body, $clientId));
  }

  private function handlePost(Request $request, int $selectedClientId, array $directory, ?string $requestedTab): RedirectResponse {
    $token = (string) $request->request->get('form_token', '');
    if (!$this->csrf->validate($token, self::TOKEN_ID)) {
      $this->messenger()->addError($this->t('Your form session expired. Refresh and try again.'));
      return $this->redirectBack($selectedClientId, $requestedTab);
    }

    $action = (string) $request->request->get('entity_action', '');
    $result = match ($action) {
      'onboard_client' => $this->onboardClient($request),
      'save_client' => $this->saveClient($request),
      'save_store' => $this->saveStore($request, $selectedClientId, $directory),
      'save_client_defaults' => $this->saveClientDefaults($request, $selectedClientId),
      'save_store_defaults' => $this->saveStoreDefaults($request, $selectedClientId),
      'save_employee' => $this->saveEmployee($request, $selectedClientId),
      'create_role' => $this->createRole($request, $selectedClientId),
      'save_role' => $this->saveRole($request, $selectedClientId),
      'delete_role' => $this->deleteRole($request, $selectedClientId),
      'save_role_permissions' => $this->saveRolePermissions($request, $selectedClientId),
      'save_role_usability' => $this->saveRoleUsability($request, $selectedClientId),
      default => ['status' => 'invalid', 'message' => 'Unsupported administration action.'],
    };

    $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
    $redirectClientId = max(0, (int) ($payload['redirect_client_id'] ?? $selectedClientId));
    $redirectTab = isset($payload['redirect_tab']) ? (string) $payload['redirect_tab'] : $requestedTab;
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

  private function saveClientDefaults(Request $request, int $selectedClientId): array {
    $result = $this->gateway->call('defaults', 'POST', [], [
      'action'=>'save_client_defaults',
      'default_currency'=>trim((string)$request->request->get('default_currency', '')),
      'default_timezone'=>trim((string)$request->request->get('default_timezone', '')),
    ], $selectedClientId ?: NULL);
    if (($result['status'] ?? '') === 'ok' && !empty($result['payload']['success'])) {
      $result['payload']['message'] = 'Client defaults saved. Stores without overrides now inherit the new values.';
    }
    return $result;
  }

  private function saveStoreDefaults(Request $request, int $selectedClientId): array {
    $result = $this->gateway->call('defaults', 'POST', [], [
      'action'=>'save_store_defaults',
      'store_id'=>$this->nullablePositiveInt($request->request->get('store_id')),
      'currency_code'=>trim((string)$request->request->get('currency_code', '')),
      'timezone'=>trim((string)$request->request->get('timezone', '')),
    ], $selectedClientId ?: NULL);
    if (($result['status'] ?? '') === 'ok' && !empty($result['payload']['success'])) $result['payload']['message'] = 'Store defaults saved.';
    return $result;
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
    if (!empty($directory['permissions']['stores.timings.manage'])) {
      $rawDays = $request->request->all('days');
      if (is_array($rawDays) && count($rawDays) === 7) {
        $days = [];
        foreach ($rawDays as $day => $row) {
          if (!is_array($row)) continue;
          $dayNumber = max(1, min(7, (int) $day));
          $days[] = ['day_of_week'=>$dayNumber,'start_time'=>trim((string)($row['start_time'] ?? '')),'end_time'=>trim((string)($row['end_time'] ?? '')),'is_closed'=>!empty($row['is_closed']) ? 1 : 0];
        }
        if (count($days) === 7) $body['days'] = $days;
      }
    }
    $identityEnabled = $canManageProfile && (string)$request->request->get('store_identity_enabled', '') === '1';
    if ($identityEnabled) {
      $identityResult = $this->gateway->call('store_identity', 'POST', [], [
        'action'=>'save_store',
        'id'=>$body['id'],
        'store_name'=>$body['store_name'],
        'store_code'=>trim((string)($body['store_code'] ?? $body['code'] ?? '')),
        'address'=>trim((string)($body['address'] ?? $body['address_line1'] ?? '')),
        'google_maps_url'=>trim((string)$request->request->get('google_maps_url', '')),
        'status'=>$body['status'],
      ], $selectedClientId ?: NULL);
      if (($identityResult['status'] ?? '') !== 'ok' || empty($identityResult['payload']['success'])) return $identityResult;
      $identityStoreId = $this->nullablePositiveInt($identityResult['payload']['store_id'] ?? NULL);
      if ($identityStoreId !== NULL) $body['id'] = $identityStoreId;
    }
    $saveResult = $this->gateway->call('admin_directory', 'POST', [], $body, $selectedClientId ?: NULL);
    if (($saveResult['status'] ?? '') !== 'ok' || empty($saveResult['payload']['success'])) return $saveResult;

    $logo = $request->files->get('logo');
    if ($body['id'] !== NULL && $logo !== NULL && method_exists($logo, 'isValid') && $logo->isValid()) {
      if (empty($directory['permissions']['stores.logo.manage'])) return ['status'=>'forbidden','message'=>'Your access level does not permit store logo changes.'];
      $size = (int) $logo->getSize();
      if ($size < 1 || $size > 2 * 1024 * 1024) return ['status'=>'invalid','message'=>'Store logo must be 2 MB or smaller.'];
      $path = (string) $logo->getPathname();
      $bytes = is_file($path) ? file_get_contents($path) : false;
      if (!is_string($bytes) || $bytes === '') return ['status'=>'invalid','message'=>'Store logo could not be read.'];
      $logoResult = $this->gateway->call('store_logo', 'POST', [], [
        'store_id' => $body['id'],
        'logo_base64' => base64_encode($bytes),
        'logo_name' => (string) $logo->getClientOriginalName(),
      ], $selectedClientId ?: NULL);
      if (($logoResult['status'] ?? '') !== 'ok' || empty($logoResult['payload']['success'])) return $logoResult;
      $saveResult['payload']['message'] = 'Store settings and logo saved.';
    }
    return $saveResult;
  }

  private function currencyOptions(): array {
    return ['AUD','NZD','USD','CAD','GBP','EUR','CHF','JPY','CNY','HKD','SGD','INR','PKR','AED','SAR','QAR','MYR','THB','IDR','PHP','KRW','ZAR'];
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

  private function createRole(Request $request, int $selectedClientId): array {
    return $this->gateway->call('role_authority', 'POST', [], [
      'action' => 'create_role',
      'role_label' => trim((string) $request->request->get('role_label', '')),
      'authority_level' => (int) $request->request->get('authority_level', 1),
    ], $selectedClientId ?: NULL);
  }

  private function saveRole(Request $request, int $selectedClientId): array {
    return $this->gateway->call('role_authority', 'POST', [], [
      'action' => 'save_role',
      'role_id' => $this->nullablePositiveInt($request->request->get('role_id')),
      'role_label' => trim((string) $request->request->get('role_label', '')),
      'authority_level' => (int) $request->request->get('authority_level', 1),
    ], $selectedClientId ?: NULL);
  }

  private function deleteRole(Request $request, int $selectedClientId): array {
    return $this->gateway->call('role_authority', 'POST', [], [
      'action' => 'delete_role',
      'role_id' => $this->nullablePositiveInt($request->request->get('role_id')),
    ], $selectedClientId ?: NULL);
  }

  private function saveRoleUsability(Request $request, int $selectedClientId): array {
    $roleKey = strtoupper(trim((string) $request->request->get('role_key', '')));
    if (!in_array($roleKey, ['SUPER', 'USER'], true)) return ['status'=>'invalid','message'=>'Choose SUPER or USER.'];
    $keys = $this->roleUsabilityKeys($request->request->all('permission_keys'));
    $selected = array_fill_keys($this->roleUsabilityKeys($request->request->all('enabled_keys')), true);
    $enabled = [];
    foreach ($keys as $key) $enabled[$key] = isset($selected[$key]);
    if (!$enabled) return ['status'=>'invalid','message'=>'No application capabilities were supplied.'];
    $result = $this->gateway->call('role_authority', 'POST', [], [
      'action'=>'save_usability', 'role_key'=>$roleKey, 'enabled'=>$enabled,
    ], $selectedClientId ?: NULL);
    if (($result['status'] ?? '') === 'ok' && !empty($result['payload']['success'])) {
      $result['payload']['message'] = $roleKey . ' application usability saved within the DEV-defined ceiling.';
    }
    return $result;
  }

  private function roleUsabilityKeys(mixed $value): array {
    if (!is_array($value)) return [];
    $keys = [];
    foreach ($value as $key) {
      $key = trim((string) $key);
      if (preg_match('/^[a-z][a-z0-9_.-]{1,119}$/', $key)) $keys[$key] = true;
    }
    return array_keys($keys);
  }

  private function saveRolePermissions(Request $request, int $selectedClientId): array {
    $raw = $request->request->all('levels');
    $levels = [];
    foreach (is_array($raw) ? $raw : [] as $key => $value) {
      if (is_string($key) && preg_match('/^[a-z][a-z0-9_.-]{1,119}$/', $key)) $levels[$key] = max(1, min(1000, (int) $value));
    }
    return $this->gateway->call('role_authority', 'POST', [], ['action'=>'save_permissions','levels'=>$levels], $selectedClientId ?: NULL);
  }

  private function legacyGatewayResponse(array $result): JsonResponse {
    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    if (($result['status'] ?? '') === 'ok' && !empty($payload['success'])) return new JsonResponse($this->sanitizeLegacyPayload($payload));
    $error = trim((string) ($payload['error'] ?? $payload['message'] ?? $result['message'] ?? 'The migration request could not be completed.'));
    $http = (int) ($result['http_status'] ?? 0);
    if (($result['status'] ?? '') === 'forbidden') $http = 403;
    elseif (($result['status'] ?? '') === 'ok') $http = 422;
    elseif ($http < 400 || $http > 599) $http = 503;
    return new JsonResponse(['success'=>false,'error'=>$error ?: 'The migration request could not be completed.'], $http);
  }

  private function sanitizeLegacyPayload(array $payload): array {
    $client = is_array($payload['client'] ?? NULL) ? $payload['client'] : [];
    $out = [
      'success'=>true,
      'client'=>['id'=>max(0,(int)($client['id'] ?? 0)),'name'=>trim((string)($client['name'] ?? '')),'client_code'=>trim((string)($client['client_code'] ?? '')),'status'=>trim((string)($client['status'] ?? ''))],
      'sources'=>$this->sanitizeLegacySources($payload['sources'] ?? []),
      'migration_state'=>$this->sanitizeLegacyState($payload['migration_state'] ?? []),
      'recent_batches'=>$this->sanitizeLegacyRows($payload['recent_batches'] ?? [], ['public_id','mode','status','attendance_rows','financial_rows','inserted_rows','updated_rows','unchanged_rows','conflict_rows','rejected_rows','warning_rows','started_at','finished_at','error_message']),
      'open_conflicts'=>$this->sanitizeLegacyRows($payload['open_conflicts'] ?? [], ['id','batch_id','source_type','source_key','conflict_code','message','existing_target_table','existing_target_key','created_at']),
      'record_counts'=>$this->sanitizeLegacyCounts($payload['record_counts'] ?? []),
      'suggestions'=>$this->sanitizeLegacySuggestions($payload['suggestions'] ?? []),
      'rules'=>$this->sanitizeLegacyRules($payload['rules'] ?? []),
    ];
    if (isset($payload['message'])) $out['message'] = trim((string) $payload['message']);
    if (is_array($payload['batch_result'] ?? NULL)) $out['batch_result'] = $this->sanitizeLegacyBatchResult($payload['batch_result']);
    return $out;
  }

  private function sanitizeLegacySources(mixed $value): array {
    if (!is_array($value)) return [];
    $out = [];
    foreach (['attendance','financial'] as $type) {
      $row = is_array($value[$type] ?? NULL) ? $value[$type] : NULL;
      if ($row === NULL) continue;
      $sheets = $row['sheet_names'] ?? [];
      if ($type === 'attendance' && is_array($sheets)) {
        $sheets = array_intersect_key($sheets, array_flip(['timesheet','payrate','start_time','employee_setup']));
        $sheets = array_map(static fn($v): string => mb_substr(trim((string)$v), 0, 160), $sheets);
      }
      else $sheets = $this->legacyStringList($sheets);
      $out[$type] = ['provider'=>trim((string)($row['provider'] ?? '')),'spreadsheet_id'=>mb_substr(trim((string)($row['spreadsheet_id'] ?? '')),0,160),'sheet_names'=>$sheets,'status'=>trim((string)($row['status'] ?? '')),'updated_at'=>trim((string)($row['updated_at'] ?? ''))];
    }
    return $out;
  }

  private function sanitizeLegacyState(mixed $value): array {
    $row = is_array($value) ? $value : [];
    return [
      'attendance_authority'=>trim((string)($row['attendance_authority'] ?? 'google_legacy')),
      'financial_authority'=>trim((string)($row['financial_authority'] ?? 'google_legacy')),
      'attendance_cutover_at'=>isset($row['attendance_cutover_at']) ? trim((string)$row['attendance_cutover_at']) : NULL,
      'financial_cutover_at'=>isset($row['financial_cutover_at']) ? trim((string)$row['financial_cutover_at']) : NULL,
    ];
  }

  private function sanitizeLegacyRows(mixed $value, array $allowed): array {
    if (!is_array($value)) return [];
    $out = [];
    foreach (array_slice($value, 0, 100) as $row) {
      if (!is_array($row)) continue;
      $clean = [];
      foreach ($allowed as $key) if (array_key_exists($key, $row)) {
        $clean[$key] = is_scalar($row[$key]) || $row[$key] === NULL ? $row[$key] : NULL;
      }
      $out[] = $clean;
    }
    return $out;
  }

  private function sanitizeLegacyCounts(mixed $value): array {
    $row = is_array($value) ? $value : [];
    $out = [];
    foreach (['employee_logs','attendance_shifts','financial_submissions','financial_ledger_entries','legacy_migration_records'] as $key) {
      $out[$key] = isset($row[$key]) && is_numeric($row[$key]) ? max(0, (int)$row[$key]) : NULL;
    }
    return $out;
  }

  private function sanitizeLegacySuggestions(mixed $value): array {
    $row = is_array($value) ? $value : [];
    $attendance = is_array($row['attendance_sheets'] ?? NULL) ? array_intersect_key($row['attendance_sheets'], array_flip(['timesheet','payrate','start_time','employee_setup'])) : [];
    return ['attendance_spreadsheet_id'=>mb_substr(trim((string)($row['attendance_spreadsheet_id'] ?? '')),0,160),'attendance_sheets'=>array_map(static fn($v): string => mb_substr(trim((string)$v),0,160),$attendance),'financial_spreadsheet_id'=>mb_substr(trim((string)($row['financial_spreadsheet_id'] ?? '')),0,160),'financial_sheets'=>$this->legacyStringList($row['financial_sheets'] ?? [])];
  }

  private function sanitizeLegacyRules(mixed $value): array {
    $row = is_array($value) ? $value : [];
    return [
      'provider'=>trim((string)($row['provider'] ?? 'google_public_csv')),
      'preview_before_sync'=>!empty($row['preview_before_sync']),
      'sync_requires_same_preview_snapshot'=>!empty($row['sync_requires_same_preview_snapshot']),
      'post_cutover_google_apply'=>!empty($row['post_cutover_google_apply']),
      'financial_updates'=>trim((string)($row['financial_updates'] ?? '')),
      'existing_employee_passwords_overwritten'=>!empty($row['existing_employee_passwords_overwritten']),
      'staging_payloads_redact_credentials'=>!empty($row['staging_payloads_redact_credentials']),
    ];
  }

  private function sanitizeLegacyBatchResult(array $row): array {
    $out = [];
    foreach (['batch_id','status','source_snapshot_hash','attendance_rows','financial_rows','inserted','updated','unchanged','conflicts','rejected','warnings'] as $key) {
      if (array_key_exists($key, $row)) $out[$key] = is_scalar($row[$key]) || $row[$key] === NULL ? $row[$key] : NULL;
    }
    return $out;
  }

  private function legacyAttendanceSheets(array $value): array {
    $out = [];
    foreach (['timesheet','payrate','start_time','employee_setup'] as $key) {
      $out[$key] = mb_substr(trim((string)($value[$key] ?? '')), 0, 160);
    }
    return $out;
  }

  private function legacyStringList(mixed $value): array {
    if (!is_array($value)) return [];
    $out = [];
    foreach (array_slice($value, 0, 50) as $item) {
      $item = mb_substr(trim((string)$item), 0, 160);
      if ($item !== '' && !in_array($item, $out, true)) $out[] = $item;
    }
    return $out;
  }

  private function positiveInt(mixed $value): ?int {
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    return $parsed !== false && $parsed > 0 ? (int)$parsed : NULL;
  }

  private function nullablePositiveInt(mixed $value): ?int {
    if ($value === null || $value === '') return NULL;
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    return $parsed !== false && $parsed > 0 ? (int) $parsed : NULL;
  }

  private function requestedTab(Request $request): ?string {
    $tab = strtolower(trim((string) $request->query->get('tab', '')));
    return in_array($tab, ['onboarding', 'clients', 'stores', 'defaults', 'workforce', 'roles'], true) ? $tab : NULL;
  }

  private function redirectBack(int $clientId, ?string $tab = NULL): RedirectResponse {
    $query = $clientId > 0 ? ['client_id' => $clientId] : [];
    if ($tab !== NULL && in_array($tab, ['onboarding','clients','stores','defaults','workforce','roles'], true)) $query['tab'] = $tab;
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

