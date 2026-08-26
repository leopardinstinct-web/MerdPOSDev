<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/legacy_migration_orchestrator.php';

function legacy_api_client(PDO $pdo, mixed $value): array
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) throw new MerdWorkforceException('invalid_client', 'Choose a valid client.');
    $stmt = $pdo->prepare('SELECT id,name,client_code,status FROM clients WHERE id=? LIMIT 1');
    $stmt->execute([(int)$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) throw new MerdWorkforceException('client_not_found', 'Client not found.');
    return $client;
}

function legacy_api_state(PDO $pdo, array $user, array $client): array
{
    $clientId = (int)$client['id'];
    $sources = legacy_source_state($pdo, $clientId);
    $state = legacy_migration_state($pdo, $clientId);
    $suggestions = [
        'attendance_spreadsheet_id'=>'',
        'attendance_sheets'=>['timesheet'=>'Time Sheet','payrate'=>'PayRate','start_time'=>'Start Time','employee_setup'=>'Employee Setup'],
        'financial_spreadsheet_id'=>'',
        'financial_sheets'=>[],
    ];
    if ($clientId === (int)($user['auth_client_id'] ?? $user['client_id'])) $suggestions = legacy_source_suggestions();

    $counts = [];
    foreach (['employee_logs','attendance_shifts','financial_submissions','financial_ledger_entries','legacy_migration_records'] as $table) {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE client_id=?');
            $stmt->execute([$clientId]);
            $counts[$table] = (int)$stmt->fetchColumn();
        } catch (Throwable) { $counts[$table] = null; }
    }

    return [
        'success'=>true,'csrf'=>csrf_token(),
        'client'=>['id'=>$clientId,'name'=>(string)$client['name'],'client_code'=>(string)$client['client_code'],'status'=>(string)$client['status']],
        'sources'=>$sources,'migration_state'=>$state,'recent_batches'=>legacy_recent_batches($pdo,$clientId,12),
        'open_conflicts'=>legacy_open_conflicts($pdo,$clientId,40),'record_counts'=>$counts,'suggestions'=>$suggestions,
        'rules'=>[
            'provider'=>'google_public_csv','preview_before_sync'=>true,'sync_requires_same_preview_snapshot'=>true,
            'post_cutover_google_apply'=>false,'financial_updates'=>'conflict_after_first_import',
            'existing_employee_passwords_overwritten'=>false,'staging_payloads_redact_credentials'=>true,
        ],
    ];
}

try {
    $user = beta_require_active_user();$pdo = portal_db();beta_require_permission($user,'legacy_migration.manage',$pdo);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $client=legacy_api_client($pdo,$_GET['client_id']??null);json_response(legacy_api_state($pdo,$user,$client));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['success'=>false,'error'=>'GET or POST required.'],405);
    $input=request_input();require_csrf($input);$client=legacy_api_client($pdo,$input['client_id']??null);$clientId=(int)$client['id'];$action=trim((string)($input['action']??''));

    if($action==='save_sources'){
        legacy_save_sources($pdo,$user,$clientId,$input);
        legacy_audit($pdo,$user,$clientId,'legacy_migration.sources.update',[
            'attendance_configured'=>trim((string)($input['attendance_spreadsheet_id']??''))!=='',
            'financial_configured'=>trim((string)($input['financial_spreadsheet_id']??''))!=='',
            'financial_tab_count'=>is_array($input['financial_sheets']??null)?count($input['financial_sheets']):0,
        ]);
        $state=legacy_api_state($pdo,$user,$client);$state['message']='Legacy Google sources saved. No source data was copied yet.';json_response($state);
    }

    if(in_array($action,['preview','sync','final'],true)){
        $result=legacy_run_batch_safe($pdo,$user,$clientId,$action);
        legacy_audit($pdo,$user,$clientId,'legacy_migration.'.$action,[
            'batch_id'=>$result['batch_id'],'status'=>$result['status'],'source_snapshot_hash'=>$result['source_snapshot_hash'],
            'attendance_rows'=>$result['attendance_rows'],'financial_rows'=>$result['financial_rows'],
            'inserted'=>$result['inserted'],'updated'=>$result['updated'],'unchanged'=>$result['unchanged'],'conflicts'=>$result['conflicts'],'rejected'=>$result['rejected'],
        ]);
        $state=legacy_api_state($pdo,$user,$client);$state['batch_result']=$result;
        $state['message']=$action==='preview'?'Preview complete. Sync is now locked to this exact Google source snapshot.':($action==='final'?'Final Sync completed. MERDPOS SQL is now authoritative for this client.':'Legacy Sync completed.');
        json_response($state);
    }
    json_response(['success'=>false,'error'=>'Unsupported legacy migration action.'],400);
} catch(Throwable $e){beta_api_error($e);}
