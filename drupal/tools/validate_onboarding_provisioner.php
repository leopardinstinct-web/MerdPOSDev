<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/PortalGatewayClientInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/AdministrationOnboardingProvisioner.php';

use Drupal\merdpos_core\Integration\AdministrationOnboardingProvisioner;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;

function onboarding_flow_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
}

final class OnboardingGatewayStub implements PortalGatewayClientInterface {
  public array $calls = [];
  public function __construct(private readonly bool $failEmployee = false) {}
  public function call(string $route, string $method = 'GET', array $query = [], array $body = [], ?int $contextClientId = NULL): array {
    $this->calls[] = compact('route','method','query','body','contextClientId');
    if ($route === 'clients' && $method === 'POST') return ['status'=>'ok','payload'=>['success'=>true,'client_id'=>9]];
    if ($route === 'admin_directory' && $method === 'GET') return ['status'=>'ok','payload'=>[
      'success'=>true,
      'actor'=>['roles'=>[['id'=>50,'role_key'=>'ADMIN']]],
      'store_edit_fields'=>[['name'=>'store_code'],['name'=>'address'],['name'=>'timezone'],['name'=>'currency_code']],
    ]];
    if ($route === 'admin_directory' && $method === 'POST' && ($body['action'] ?? '') === 'save_store') {
      return ['status'=>'ok','payload'=>['success'=>true,'stores'=>[['id'=>77,'store_name'=>(string)($body['store_name'] ?? '')]]]];
    }
    if ($route === 'admin_directory' && $method === 'POST' && ($body['action'] ?? '') === 'save_employee') {
      if ($this->failEmployee) return ['status'=>'forbidden','payload'=>['success'=>false,'error'=>'denied']];
      return ['status'=>'ok','payload'=>['success'=>true,'employees'=>[['id'=>88,'full_name'=>(string)($body['full_name'] ?? '')]]]];
    }
    return ['status'=>'invalid','payload'=>['success'=>false]];
  }
}

$input = [
  'onboard_client_name'=>'Example Retail Group',
  'onboard_client_code'=>'EXAMPLE',
  'onboard_store_name'=>'Example Central',
  'onboard_store_code'=>'EX-CENTRAL',
  'onboard_store_address'=>'1 Example St',
  'onboard_store_timezone'=>'Australia/Sydney',
  'onboard_store_currency'=>'AUD',
  'onboard_admin_name'=>'Initial Admin',
  'onboard_admin_user_id'=>'10001',
  'onboard_admin_rate'=>'25.50',
];
$input['onboard_admin_password'] = str_repeat('7', 4);
$input['onboard_schedule_enabled'] = '1';
for ($day = 1; $day <= 7; $day++) {
  $input['onboard_day_' . $day . '_start'] = '09:00';
  $input['onboard_day_' . $day . '_end'] = '17:00';
}
$input['onboard_day_7_closed'] = '1';

$gateway = new OnboardingGatewayStub();
$result = (new AdministrationOnboardingProvisioner($gateway))->provision($input);
onboarding_flow_check(($result['status'] ?? '') === 'ok', 'Successful onboarding did not resolve OK.');
onboarding_flow_check(($result['payload']['client_id'] ?? 0) === 9, 'Onboarding client id mismatch.');
onboarding_flow_check(($result['payload']['store_id'] ?? 0) === 77, 'Onboarding store id mismatch.');
onboarding_flow_check(($result['payload']['redirect_tab'] ?? '') === 'workforce', 'Onboarding success redirect mismatch.');
onboarding_flow_check(count($gateway->calls) === 4, 'Onboarding must execute exactly four signed gateway calls.');
onboarding_flow_check(($gateway->calls[1]['contextClientId'] ?? 0) === 9, 'New client context was not used for directory lookup.');
onboarding_flow_check(($gateway->calls[2]['body']['action'] ?? '') === 'save_store', 'First operational write must create the store.');
onboarding_flow_check(count($gateway->calls[2]['body']['days'] ?? []) === 7, 'Onboarding schedule must include seven weekdays when enabled.');
onboarding_flow_check(($gateway->calls[2]['body']['days'][6]['is_closed'] ?? 0) === 1, 'Closed-day schedule state was not preserved.');
onboarding_flow_check(($gateway->calls[3]['body']['action'] ?? '') === 'save_employee', 'Final operational write must create the initial admin.');
onboarding_flow_check(($gateway->calls[3]['body']['client_role_id'] ?? 0) === 50, 'Initial admin role was not resolved from authoritative roles.');
onboarding_flow_check(($gateway->calls[3]['body']['store_ids'][0] ?? 0) === 77, 'Initial admin was not assigned to the first store.');

$failed = (new AdministrationOnboardingProvisioner(new OnboardingGatewayStub(true)))->provision($input);
onboarding_flow_check(($failed['status'] ?? '') === 'forbidden', 'Employee-stage authorization failure must fail closed.');
onboarding_flow_check(($failed['payload']['redirect_client_id'] ?? 0) === 9, 'Partial onboarding must preserve recovery client context.');
onboarding_flow_check(($failed['payload']['redirect_tab'] ?? '') === 'workforce', 'Partial onboarding must route to recovery surface.');

echo "MERDPOS Drupal onboarding provisioner sequence validated.\n";
