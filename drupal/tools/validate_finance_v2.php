<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/PortalGatewayClientInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/WorkingNowProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProviderInterface.php';
require_once dirname(__DIR__) . '/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php';

use Drupal\merdpos_core\Integration\ParityDataProvider;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Drupal\merdpos_core\Integration\WorkingNowProviderInterface;

function finance_v2_check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
final class FinanceWorking implements WorkingNowProviderInterface { public function load(): array { return ['status'=>'ok','count'=>0,'people'=>[],'message'=>'ok']; } }
final class FinanceGateway implements PortalGatewayClientInterface {
  public function call(string $route, string $method='GET', array $query=[], array $body=[], ?int $contextClientId = NULL): array {
    $payload = match($route) {
      'dashboard_data' => ['success'=>true,'role'=>['role_key'=>'DEV','role_label'=>'Developer','authority_level'=>1000],'client_defaults'=>['currency_code'=>'AUD','timezone'=>'Australia/Sydney'],'filter_options'=>['stores'=>[['id'=>1,'store_name'=>'Test Store']]],'management'=>['business_date'=>'2026-09-05','currency_code'=>'AUD','timezone'=>'Australia/Sydney','sales_by_store'=>[['store_id'=>1,'store_name'=>'Test Store','currency_code'=>'AUD','today_sales'=>400]],'financial_by_store'=>[['store_id'=>1,'store_name'=>'Test Store','currency_code'=>'AUD','register_balance'=>300,'petty_balance'=>50]],'analytics'=>['sales_period'=>[['date'=>'2026-09-04','value'=>350],['date'=>'2026-09-05','value'=>400]]]]],
      'store_identity' => ['success'=>true,'stores'=>[['id'=>1,'store_name'=>'Test Store','status'=>'active']]],
      'financials' => ['success'=>true,'statement'=>['store_id'=>1,'store_name'=>'Test Store','business_date'=>'2026-09-05','day_status'=>'open','currency_code'=>'AUD','timezone'=>'Australia/Sydney','can_cross_store'=>true,'can_open_day'=>true,'accounts'=>[['account'=>'Register','opening'=>250,'cash_in'=>75,'cash_out'=>25,'available'=>300,'closing'=>null,'status'=>'open'],['account'=>'Petty Cash','opening'=>40,'cash_in'=>15,'cash_out'=>5,'available'=>50,'closing'=>null,'status'=>'open']],'entries'=>[['account'=>'Register','entry_type'=>'cash_in','head'=>'Till movement','amount'=>20,'full_name'=>'Test Employee','created_at'=>'2026-09-05 01:00:00']]]],
      default => ['success'=>false],
    };
    return ['status'=>($payload['success']??false)?'ok':'unavailable','http_status'=>200,'payload'=>$payload,'message'=>'stub'];
  }
}
$surface=(new ParityDataProvider(new FinanceGateway(),new FinanceWorking()))->section('finance',['store_id'=>'1','business_date'=>'2026-09-05']);
finance_v2_check(($surface['status']??'')==='ok','Finance v2 did not resolve OK.');
finance_v2_check(($surface['role']['key']??'')==='DEV','Finance v2 role mismatch.');
finance_v2_check(($surface['role']['loa']??0)===1000,'Finance v2 LOA mismatch.');
finance_v2_check(count($surface['metrics']??[])===5,'Finance v2 KPI count mismatch.');
finance_v2_check(count($surface['filters']??[])===2,'Finance v2 filters missing.');
finance_v2_check(count($surface['chart_specs']??[])===5,'Finance v2 chart count mismatch.');
finance_v2_check(count($surface['account_cards']??[])===2,'Finance account cards missing.');
finance_v2_check(count($surface['ledger_rows']??[])===1,'Finance ledger missing.');
finance_v2_check(!empty($surface['selected_store']['can_cross_store']),'Finance cross-store authority missing.');
finance_v2_check(!empty($surface['read_only']),'Finance view must remain read-only.');
finance_v2_check(empty($surface['exceptions']),'Open healthy day should not produce exceptions.');

final class FinanceForbiddenGateway implements PortalGatewayClientInterface { public function call(string $route,string $method='GET',array $query=[],array $body=[], ?int $contextClientId = NULL): array { return ['status'=>'forbidden','http_status'=>403,'payload'=>['success'=>false],'message'=>'forbidden']; } }
$blocked=(new ParityDataProvider(new FinanceForbiddenGateway(),new FinanceWorking()))->section('finance',['store_id'=>'1','business_date'=>'2026-09-05']);
finance_v2_check(($blocked['status']??'')==='forbidden','Finance v2 must fail closed when authoritative services deny access.');
finance_v2_check(empty($blocked['account_cards']??[]),'Forbidden Finance must not fabricate account cards.');
finance_v2_check(empty($blocked['ledger_rows']??[]),'Forbidden Finance must not expose ledger rows.');

$root=dirname(__DIR__);
$routing=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/merdpos_core.routing.yml');
finance_v2_check(str_contains($routing,'FinanceController::finance'),'Finance route is not wired to FinanceController.');
$template=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/templates/merdpos-finance.html.twig');
finance_v2_check(str_contains($template,'Financial command centre'),'Finance rich template missing.');
finance_v2_check(str_contains($template,'Read-only Drupal view'),'Finance read-only contract missing.');
finance_v2_check(str_contains($template,'Register vs petty cash'),'Finance cash-mix chart missing.');
$css=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/css/finance-v2.css');
finance_v2_check(str_contains($css,'.merdpos-finance-charts'),'Finance chart layout CSS missing.');
finance_v2_check(str_contains($css,'@media'),'Finance responsive CSS missing.');
$provider=(string)file_get_contents($root.'/web/modules/custom/merdpos_core/src/Integration/ParityDataProvider.php');
finance_v2_check(!str_contains($provider,'SELECT '),'Drupal finance provider must not query the operational DB directly.');
$deploy=(string)file_get_contents($root.'/tools/namecheap_deploy.sh');
finance_v2_check(str_contains($deploy,'Finance v2 self-test failed.'),'Finance deployment fail-closed probe missing.');
finance_v2_check(str_contains($deploy,'finance_v2'),'Finance release marker evidence missing.');
echo "MERDPOS Drupal Finance v2 validated.\n";