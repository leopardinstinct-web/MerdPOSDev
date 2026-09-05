<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/PortalGatewayClientInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/WorkingNowProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php';
use Drupal\merdpos_core\Integration\ParityDataProvider;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Drupal\merdpos_core\Integration\WorkingNowProviderInterface;
function dev_v2_check(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
final class DevWorking implements WorkingNowProviderInterface { public function load(): array { return ['status'=>'ok','count'=>0,'people'=>[],'message'=>'ok']; } }
final class DevGateway implements PortalGatewayClientInterface {
  public function call(string $route,string $method='GET',array $query=[],array $body=[]): array {
    $p=match($route){
      'dev_status'=>['success'=>true,'app'=>'MERDPOS beta','branch'=>'namecheap-beta-live','php_version'=>'8.4.0','server_version'=>'8.0','authorization_model'=>'central_permission_loa_v1','actor_loa'=>1000,'tables'=>['employees'=>44,'stores'=>4,'google_sheet_outbox'=>3]],
      'clients'=>['success'=>true,'clients'=>[['name'=>'MERD','client_code'=>'MERD','status'=>'active','store_count'=>4,'employee_count'=>44]]],
      'role_authority'=>['success'=>true,'roles'=>[['role_label'=>'Developer','role_key'=>'DEV','base_role'=>'DEV','authority_level'=>1000,'employee_count'=>1,'status'=>'active','allowed_widgets'=>['sync_status_table']]],'permissions'=>[['permission_key'=>'dev.status','label'=>'DEV Status','category'=>'System','min_authority_level'=>1000,'dev_only'=>true]]],
      'client_context'=>['success'=>true,'client'=>['name'=>'MERD','client_code'=>'MERD'],'scope'=>'dev_selected_client','cross_client_context'=>false],
      'dashboard_data'=>['success'=>true,'management'=>['analytics'=>['sync_statuses'=>[['status'=>'failed','count'=>1],['status'=>'pending','count'=>2]]]]],
      'beta_state'=>['success'=>true,'role'=>'DEV','role_key'=>'DEV','role_label'=>'Developer','authority_level'=>1000,'attendance_flags'=>[['full_name'=>'Test','attempted_store'=>'Store','reason'=>'simultaneous_qr_at_different_store','status'=>'open','created_at'=>'2026-09-06 00:00:00']]],
      default=>['success'=>false],
    };
    return ['status'=>($p['success']??false)?'ok':'unavailable','http_status'=>200,'payload'=>$p,'message'=>'stub'];
  }
}
$surface=(new ParityDataProvider(new DevGateway(),new DevWorking()))->section('dev');
dev_v2_check(($surface['status']??'')==='ok','DEV v2 did not resolve OK.');
dev_v2_check(($surface['role']['key']??'')==='DEV','DEV role mismatch.');
dev_v2_check(($surface['role']['loa']??0)===1000,'DEV LOA mismatch.');
dev_v2_check(count($surface['metrics']??[])===6,'DEV KPI count mismatch.');
dev_v2_check(count($surface['chart_specs']??[])===4,'DEV chart count mismatch.');
dev_v2_check(count($surface['source_statuses']??[])===6,'DEV source health missing.');
dev_v2_check(count($surface['security_rows']??[])===1,'DEV security event missing.');
dev_v2_check(count($surface['sync_rows']??[])===2,'DEV sync rows missing.');
dev_v2_check(!empty($surface['read_only'])&&!empty($surface['studio_excluded']),'DEV safety boundary missing.');
final class DevForbidden implements PortalGatewayClientInterface { public function call(string $route,string $method='GET',array $query=[],array $body=[]): array { return ['status'=>'forbidden','http_status'=>403,'payload'=>['success'=>false],'message'=>'forbidden']; } }
$blocked=(new ParityDataProvider(new DevForbidden(),new DevWorking()))->section('dev');
dev_v2_check(($blocked['status']??'')==='forbidden','DEV must fail closed.');
$root=dirname(__DIR__);
$routing=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/merdpos_core.routing.yml');
dev_v2_check(str_contains($routing,'DevController::dev'), 'DEV route not wired to dedicated controller.');
dev_v2_check(str_contains($routing,"_permission: 'access merdpos dev tools'"),'DEV-only route permission missing.');
$template=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/templates/merdpos-dev.html.twig');
dev_v2_check(str_contains($template,'Platform command centre')&&str_contains($template,'Studio excluded'),'DEV rich/safety UI missing.');
$css=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/css/dev-v2.css');
dev_v2_check(str_contains($css,'.merdpos-dev-charts')&&str_contains($css,'@media(max-width:430px)'),'DEV responsive CSS missing.');
$provider=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php');
dev_v2_check(!str_contains($provider,'SELECT '),'Drupal DEV provider must not query operational DB directly.');
$controller=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/src/Controller/DevController.php');
dev_v2_check(!str_contains($controller,'password')&&!str_contains($controller,'secret'),'DEV release presenter must not expose secrets.');
$deploy=(string)file_get_contents($root.'/tools/namecheap_deploy.sh');
dev_v2_check(str_contains($deploy,'DEV v2 self-test failed.')&&str_contains($deploy,'dev_v2'),'DEV production probe missing.');
echo "MERDPOS Drupal DEV v2 validated.\n";
