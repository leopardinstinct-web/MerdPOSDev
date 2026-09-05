<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Integration;

final class AdministrationOnboardingProvisioner {

  public function __construct(private readonly PortalGatewayClientInterface $gateway) {}

  public function provision(array $input): array {
    $clientName = trim((string) ($input['onboard_client_name'] ?? ''));
    $clientCode = trim((string) ($input['onboard_client_code'] ?? ''));
    $storeName = trim((string) ($input['onboard_store_name'] ?? ''));
    $adminName = trim((string) ($input['onboard_admin_name'] ?? ''));
    $adminUserId = preg_replace('/\D+/', '', (string) ($input['onboard_admin_user_id'] ?? ''));
    $adminPassword = preg_replace('/\D+/', '', (string) ($input['onboard_admin_password'] ?? ''));
    if ($clientName === '' || $clientCode === '' || $storeName === '' || $adminName === '' || $adminUserId === '' || strlen($adminPassword) < 4) {
      return ['status'=>'invalid','message'=>'Complete the client, first store and initial admin details before provisioning.'];
    }

    $clientResult = $this->gateway->call('clients', 'POST', [], [
      'action'=>'save_client','id'=>NULL,'name'=>$clientName,'client_code'=>$clientCode,'status'=>'active',
    ]);
    $clientPayload = is_array($clientResult['payload'] ?? NULL) ? $clientResult['payload'] : [];
    if (($clientResult['status'] ?? '') !== 'ok' || empty($clientPayload['success']) || empty($clientPayload['client_id'])) return $clientResult;
    $clientId = (int) $clientPayload['client_id'];

    $directoryResult = $this->gateway->call('admin_directory', 'GET', [], [], $clientId);
    $directory = is_array($directoryResult['payload'] ?? NULL) ? $directoryResult['payload'] : [];
    if (($directoryResult['status'] ?? '') !== 'ok' || empty($directory['success'])) {
      return $this->partial('unavailable', $clientId, 'stores', 'Client created, but its administration context could not be loaded. Open the new client and continue setup.');
    }

    $adminRoleId = 0;
    foreach (($directory['actor']['roles'] ?? []) as $role) {
      if (is_array($role) && strtoupper((string) ($role['role_key'] ?? '')) === 'ADMIN') {
        $adminRoleId = (int) ($role['id'] ?? 0);
        break;
      }
    }
    if ($adminRoleId <= 0) {
      return $this->partial('invalid', $clientId, 'workforce', 'Client created, but the ADMIN role is unavailable. Open the new client and review its role configuration.');
    }

    $storeBody = [
      'action'=>'save_store','id'=>NULL,'store_name'=>$storeName,'status'=>'active',
      'week_start_day'=>max(1, min(7, (int) ($input['onboard_week_start_day'] ?? 1))),
    ];
    $profile = [
      'store_code'=>'onboard_store_code',
      'code'=>'onboard_store_code',
      'address'=>'onboard_store_address',
      'address_line1'=>'onboard_store_address',
      'email'=>'onboard_store_email',
      'store_email'=>'onboard_store_email',
      'phone'=>'onboard_store_phone',
      'phone_number'=>'onboard_store_phone',
      'timezone'=>'onboard_store_timezone',
      'currency_code'=>'onboard_store_currency',
    ];
    foreach (($directory['store_edit_fields'] ?? []) as $field) {
      $name = (string) ($field['name'] ?? '');
      if (isset($profile[$name])) $storeBody[$name] = trim((string) ($input[$profile[$name]] ?? ''));
    }
    if (!empty($input['onboard_schedule_enabled'])) {
      $days = [];
      for ($day = 1; $day <= 7; $day++) {
        $closed = !empty($input['onboard_day_' . $day . '_closed']);
        $days[] = [
          'day_of_week'=>$day,
          'start_time'=>$closed ? '' : trim((string) ($input['onboard_day_' . $day . '_start'] ?? '')),
          'end_time'=>$closed ? '' : trim((string) ($input['onboard_day_' . $day . '_end'] ?? '')),
          'is_closed'=>$closed ? 1 : 0,
        ];
      }
      $storeBody['days'] = $days;
    }

    $storeResult = $this->gateway->call('admin_directory', 'POST', [], $storeBody, $clientId);
    $storePayload = is_array($storeResult['payload'] ?? NULL) ? $storeResult['payload'] : [];
    if (($storeResult['status'] ?? '') !== 'ok' || empty($storePayload['success'])) {
      return $this->partial((string) ($storeResult['status'] ?? 'unavailable'), $clientId, 'stores', 'Client created, but the first store could not be created. Open the new client and continue setup.');
    }

    $storeId = 0;
    foreach (($storePayload['stores'] ?? []) as $store) {
      if (is_array($store) && strcasecmp(trim((string) ($store['store_name'] ?? '')), $storeName) === 0) {
        $storeId = (int) ($store['id'] ?? 0);
        break;
      }
    }
    if ($storeId <= 0) {
      return $this->partial('unavailable', $clientId, 'workforce', 'Client and store were created, but the new store could not be resolved for workforce assignment.');
    }

    $employeeBody = [
      'action'=>'save_employee','id'=>NULL,'full_name'=>$adminName,'user_id'=>$adminUserId,
      'client_role_id'=>$adminRoleId,'employee_type'=>'ADMIN','status'=>'active',
      'hourly_rate'=>trim((string) ($input['onboard_admin_rate'] ?? '0.00')),
      'rate_effective_date'=>date('Y-m-d'),'store_access_mode'=>'selected','store_ids'=>[$storeId],
    ];
    $employeeBody['new_password'] = $adminPassword;
    $employeeResult = $this->gateway->call('admin_directory', 'POST', [], $employeeBody, $clientId);
    $employeePayload = is_array($employeeResult['payload'] ?? NULL) ? $employeeResult['payload'] : [];
    if (($employeeResult['status'] ?? '') !== 'ok' || empty($employeePayload['success'])) {
      return $this->partial((string) ($employeeResult['status'] ?? 'unavailable'), $clientId, 'workforce', 'Client and store were created, but the initial admin could not be created. Open the new client and continue workforce setup.');
    }

    return ['status'=>'ok','payload'=>[
      'success'=>true,
      'message'=>'Client onboarding complete: client, first store and initial admin created.',
      'redirect_client_id'=>$clientId,
      'redirect_tab'=>'workforce',
      'client_id'=>$clientId,
      'store_id'=>$storeId,
    ]];
  }

  private function partial(string $status, int $clientId, string $tab, string $message): array {
    return ['status'=>$status,'payload'=>[
      'error'=>$message,
      'redirect_client_id'=>$clientId,
      'redirect_tab'=>$tab,
    ]];
  }

}
