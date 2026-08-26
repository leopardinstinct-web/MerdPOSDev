<?php
declare(strict_types=1);

/**
 * Exact legacy financial importer for the proven MERDPOS Google workbook.
 *
 * General Ledger is the canonical operational history because it already holds
 * the final ledger lines that were used by the live system:
 *   DATE, STORE_NAME, ACCOUNT, TYPE, HEAD, AMOUNT, Key
 *
 * zReport Ledger is retained in staging as immutable source/audit evidence, but
 * is NOT applied again to financial_ledger_entries. Applying both would duplicate
 * Sales (ACTUAL), transfer and closing lines already represented in General Ledger.
 */

function legacy_known_financial_sheet_kind(string $sheetName): string
{
    return match (legacy_norm($sheetName)) {
        'generalledger' => 'general_ledger',
        'zreportledger' => 'zreport_ledger',
        default => 'unsupported',
    };
}

function legacy_known_account(string $value): ?string
{
    $value = strtolower(trim($value));
    if ($value === 'register') return 'Register';
    if ($value === 'petty cash' || $value === 'pettycash') return 'Petty Cash';
    return null;
}

function legacy_known_general_ledger_row(array $row, array $maps): array
{
    $date = legacy_date(legacy_field($row,['DATE']));
    $storeText = legacy_field($row,['STORE_NAME']);
    $storeMatch = legacy_match_store($maps,$storeText);
    $account = legacy_known_account(legacy_field($row,['ACCOUNT']));
    $entryType = strtoupper(trim(legacy_field($row,['TYPE'])));
    $head = trim(legacy_field($row,['HEAD']));
    $amount = legacy_money(legacy_field($row,['AMOUNT']));

    if (!$date) return ['status'=>'rejected','code'=>'financial_date_invalid','message'=>'General Ledger DATE is invalid.'];
    if ($storeMatch['status'] !== 'matched') return ['status'=>'conflict','code'=>'financial_store_not_found','message'=>$storeMatch['message'] ?: 'General Ledger store could not be matched.'];
    if ($account === null) return ['status'=>'rejected','code'=>'financial_account_invalid','message'=>'General Ledger ACCOUNT must be Register or Petty Cash.'];
    if (!in_array($entryType,['OPENING','IN','OUT','CLOSING'],true)) return ['status'=>'rejected','code'=>'financial_type_invalid','message'=>'General Ledger TYPE must be OPENING, IN, OUT or CLOSING.'];
    if ($amount === null) return ['status'=>'rejected','code'=>'financial_amount_invalid','message'=>'General Ledger AMOUNT is invalid.'];
    if ($head === '') $head = $entryType;

    return [
        'status'=>'valid','code'=>null,'message'=>null,
        'date'=>$date,'store'=>$storeMatch['row'],'account'=>$account,'entry_type'=>$entryType,
        'head'=>substr($head,0,120),'amount'=>$amount,'source_reference'=>legacy_field($row,['Key','KEY']),
    ];
}

function legacy_known_zreport_row(array $row, array $maps): array
{
    $date = legacy_date(legacy_field($row,['DATE']));
    $storeText = legacy_field($row,['STORE_NAME']);
    $storeMatch = legacy_match_store($maps,$storeText);
    $registerTotal = legacy_money(legacy_field($row,['REGISTER_TOTAL']));
    $pettyAddin = legacy_money(legacy_field($row,['PETTYCASH_ADDIN','PETTY_CASH_ADDIN']));

    if (!$date) return ['status'=>'rejected','code'=>'zreport_date_invalid','message'=>'zReport Ledger DATE is invalid.'];
    if ($storeMatch['status'] !== 'matched') return ['status'=>'conflict','code'=>'zreport_store_not_found','message'=>$storeMatch['message'] ?: 'zReport Ledger store could not be matched.'];
    if ($registerTotal === null) return ['status'=>'rejected','code'=>'zreport_register_invalid','message'=>'zReport Ledger REGISTER_TOTAL is invalid.'];
    if ($pettyAddin === null) return ['status'=>'rejected','code'=>'zreport_petty_invalid','message'=>'zReport Ledger PETTYCASH_ADDIN is invalid.'];

    return [
        'status'=>'valid','code'=>null,'message'=>null,
        'date'=>$date,'store'=>$storeMatch['row'],'register_total'=>$registerTotal,'petty_cash_addin'=>$pettyAddin,
    ];
}

function legacy_validate_and_stage_known(PDO $pdo,int $batchId,int $clientId,array $fetched): array
{
    // Reuse the established attendance validation exactly, but do not allow the
    // old generic financial auto-detector to touch the known financial workbook.
    $attendanceOnly = $fetched;
    $attendanceOnly['financial'] = [];
    $validated = legacy_validate_and_stage($pdo,$batchId,$clientId,$attendanceOnly);
    $validated['counts']['financial_rows'] = 0;
    $validated['items']['financial'] = [];
    $validated['items']['financial_ledger'] = [];
    $validated['items']['financial_zreport_reference'] = [];

    $maps = legacy_identity_maps($pdo,$clientId);
    $seen = [];
    $hasGeneralLedger = false;

    foreach (($fetched['financial'] ?? []) as $sheet) {
        $sheetName = (string)($sheet['sheet'] ?? '');
        $kind = legacy_known_financial_sheet_kind($sheetName);
        if ($kind === 'general_ledger') $hasGeneralLedger = true;

        foreach (($sheet['rows'] ?? []) as $row) {
            $validated['counts']['financial_rows']++;

            if ($kind === 'general_ledger') {
                $normalized = legacy_known_general_ledger_row($row,$maps);
                $base = trim((string)($normalized['source_reference'] ?? ''));
                if ($base === '') {
                    $base = implode('|',[
                        (string)($normalized['date'] ?? ''),
                        legacy_norm((string)legacy_field($row,['STORE_NAME'])),
                        (string)($normalized['account'] ?? ''),
                        (string)($normalized['entry_type'] ?? ''),
                        (string)($normalized['head'] ?? ''),
                        number_format((float)($normalized['amount'] ?? 0),2,'.',''),
                    ]);
                } else {
                    $base .= '|' . legacy_norm((string)($normalized['head'] ?? '')) . '|' . number_format((float)($normalized['amount'] ?? 0),2,'.','');
                }
                $sourceKey = legacy_source_key('financial_ledger',$base,$seen);
                $status = (string)$normalized['status'];
                $stageId = legacy_stage_row(
                    $pdo,$batchId,$clientId,'financial_ledger',$sheetName,$row,$sourceKey,$status,
                    $normalized['code'] ?? null,$normalized['message'] ?? null,null,
                    isset($normalized['store']['id']) ? (int)$normalized['store']['id'] : null
                );
                if ($status === 'rejected') $validated['counts']['rejected']++;
                elseif ($status === 'conflict') {
                    $validated['counts']['conflict']++;
                    legacy_add_conflict($pdo,$batchId,$clientId,$stageId,'financial_ledger',$sourceKey,(string)$normalized['code'],(string)$normalized['message']);
                }
                $validated['items']['financial_ledger'][] = [
                    'row'=>$row,'sheet'=>$sheetName,'source_key'=>$sourceKey,'stage_id'=>$stageId,
                    'status'=>$status,'normalized'=>$normalized,
                ];
                continue;
            }

            if ($kind === 'zreport_ledger') {
                $normalized = legacy_known_zreport_row($row,$maps);
                $base = implode('|',[
                    (string)($normalized['date'] ?? ''),
                    legacy_norm((string)legacy_field($row,['STORE_NAME'])),
                    number_format((float)($normalized['register_total'] ?? 0),2,'.',''),
                    number_format((float)($normalized['petty_cash_addin'] ?? 0),2,'.',''),
                    legacy_field($row,['REGISTER_DENOMINATIONS']),
                ]);
                $sourceKey = legacy_source_key('financial_zreport_reference',$base,$seen);
                $status = (string)$normalized['status'];
                $stageId = legacy_stage_row(
                    $pdo,$batchId,$clientId,'financial_zreport_reference',$sheetName,$row,$sourceKey,$status,
                    $normalized['code'] ?? null,$normalized['message'] ?? null,null,
                    isset($normalized['store']['id']) ? (int)$normalized['store']['id'] : null
                );
                if ($status === 'rejected') $validated['counts']['rejected']++;
                elseif ($status === 'conflict') {
                    $validated['counts']['conflict']++;
                    legacy_add_conflict($pdo,$batchId,$clientId,$stageId,'financial_zreport_reference',$sourceKey,(string)$normalized['code'],(string)$normalized['message']);
                }
                $validated['items']['financial_zreport_reference'][] = [
                    'row'=>$row,'sheet'=>$sheetName,'source_key'=>$sourceKey,'stage_id'=>$stageId,
                    'status'=>$status,'normalized'=>$normalized,
                ];
                continue;
            }

            $sourceKey = legacy_source_key('financial_unsupported',$sheetName . '|' . legacy_hash_row($row),$seen);
            $message = 'Configured Financial tab "' . $sheetName . '" has no approved MERDPOS migration mapping.';
            $stageId = legacy_stage_row($pdo,$batchId,$clientId,'financial_unsupported',$sheetName,$row,$sourceKey,'rejected','unsupported_financial_tab',$message);
            $validated['counts']['rejected']++;
            $validated['items']['financial'][] = ['row'=>$row,'sheet'=>$sheetName,'source_key'=>$sourceKey,'stage_id'=>$stageId,'status'=>'rejected'];
        }
    }

    if (($fetched['financial'] ?? []) && !$hasGeneralLedger) {
        throw new MerdWorkforceException(
            'general_ledger_required',
            'The proven Financial migration requires the General Ledger tab. MERDPOS will not infer financial history from summary tabs alone.'
        );
    }

    return $validated;
}

function legacy_financial_ledger_target_hash(array $row): string
{
    return legacy_target_hash([
        'store_id'=>(int)$row['store_id'],'business_date'=>(string)$row['business_date'],
        'account'=>(string)$row['account'],'entry_type'=>(string)$row['entry_type'],
        'head'=>(string)$row['head'],'amount'=>number_format((float)$row['amount'],2,'.',''),
    ]);
}

function legacy_financial_submission_type(string $entryType): string
{
    return match ($entryType) {
        'OPENING' => 'open_day',
        'IN' => 'cash_in',
        'OUT' => 'cash_out',
        'CLOSING' => 'z_report',
        default => throw new RuntimeException('Unsupported legacy ledger entry type.'),
    };
}

function legacy_financial_target_actor(PDO $pdo,int $clientId): ?array
{
    return legacy_financial_actor($pdo,$clientId,['employee_name'=>'']);
}

function legacy_apply_known_financial_line(PDO $pdo,int $clientId,int $batchId,array $item,array $targetActor,array &$counts): ?int
{
    if (($item['status'] ?? '') !== 'valid') return null;
    $n = $item['normalized'] ?? [];
    if (!is_array($n) || empty($n['store']['id'])) return null;

    $sourceKey = (string)$item['source_key'];
    $sourceHash = legacy_hash_row((array)$item['row']);
    $publicId = legacy_uuid_from_key($clientId . '|financial-ledger|' . $sourceKey);
    $submissionType = legacy_financial_submission_type((string)$n['entry_type']);
    $storeId = (int)$n['store']['id'];
    $date = (string)$n['date'];
    $account = (string)$n['account'];
    $entryType = (string)$n['entry_type'];
    $head = (string)$n['head'];
    $amount = (float)$n['amount'];

    $desired = [
        'store_id'=>$storeId,'business_date'=>$date,'account'=>$account,
        'entry_type'=>$entryType,'head'=>$head,'amount'=>$amount,
    ];
    $desiredHash = legacy_financial_ledger_target_hash($desired);
    $lineage = legacy_lineage($pdo,$clientId,'financial_ledger',$sourceKey);

    $find = $pdo->prepare(
        'SELECT f.id AS submission_db_id,f.payload_hash,l.id AS ledger_id,l.store_id,l.business_date,l.account,l.entry_type,l.head,l.amount '
        . 'FROM financial_submissions f LEFT JOIN financial_ledger_entries l ON l.submission_id=f.id AND l.line_no=1 '
        . 'WHERE f.client_id=? AND f.public_id=? LIMIT 1'
    );
    $find->execute([$clientId,$publicId]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);

    $payload = [
        'legacy_import'=>true,'source_sheet'=>'General Ledger','source_key'=>$sourceKey,
        'account'=>$account,'entry_type'=>$entryType,'head'=>$head,'amount'=>$amount,
        'source_reference'=>(string)($n['source_reference'] ?? ''),
    ];
    $payloadJson = json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $payloadHash = hash('sha256',$submissionType . '|' . $storeId . '|' . $date . '|' . $payloadJson);

    if (is_array($existing) && !empty($existing['ledger_id'])) {
        $currentHash = legacy_financial_ledger_target_hash($existing);
        if ($lineage && (string)$lineage['target_hash'] !== $currentHash) {
            $counts['conflict']++;
            return null;
        }
        if (!$lineage && $currentHash !== $desiredHash) {
            $counts['conflict']++;
            return null;
        }
        if ($lineage && (string)$lineage['source_hash'] === $sourceHash && $currentHash === $desiredHash) {
            $counts['unchanged']++;
            legacy_save_lineage($pdo,$clientId,'financial_ledger',$sourceKey,$sourceHash,'financial_ledger_entries',(string)$existing['ledger_id'],$currentHash,$batchId);
            return (int)$existing['submission_db_id'];
        }

        $pdo->prepare(
            'UPDATE financial_submissions SET store_id=?,employee_id=?,submission_type=?,business_date=?,payload=?,payload_hash=?,status=\'accepted\',sheet_synced_at=NULL WHERE id=? AND client_id=?'
        )->execute([$storeId,(int)$targetActor['id'],$submissionType,$date,$payloadJson,$payloadHash,(int)$existing['submission_db_id'],$clientId]);
        $pdo->prepare('UPDATE financial_ledger_entries SET store_id=?,business_date=?,account=?,entry_type=?,head=?,amount=? WHERE id=? AND client_id=?')
            ->execute([$storeId,$date,$account,$entryType,$head,number_format($amount,2,'.',''),(int)$existing['ledger_id'],$clientId]);
        $counts['updated']++;
        legacy_save_lineage($pdo,$clientId,'financial_ledger',$sourceKey,$sourceHash,'financial_ledger_entries',(string)$existing['ledger_id'],$desiredHash,$batchId);
        return (int)$existing['submission_db_id'];
    }

    if (is_array($existing) && empty($existing['ledger_id'])) {
        $counts['conflict']++;
        return null;
    }

    $pdo->prepare(
        "INSERT INTO financial_submissions (public_id,client_id,store_id,employee_id,submission_type,business_date,payload,payload_hash,status) "
        . "VALUES (?,?,?,?,?,?,?,?, 'accepted')"
    )->execute([$publicId,$clientId,$storeId,(int)$targetActor['id'],$submissionType,$date,$payloadJson,$payloadHash]);
    $submissionId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO financial_ledger_entries (submission_id,line_no,client_id,store_id,business_date,account,entry_type,head,amount) VALUES (?,1,?,?,?,?,?,?,?)'
    )->execute([$submissionId,$clientId,$storeId,$date,$account,$entryType,$head,number_format($amount,2,'.','')]);
    $ledgerId = (int)$pdo->lastInsertId();
    $counts['inserted']++;
    legacy_save_lineage($pdo,$clientId,'financial_ledger',$sourceKey,$sourceHash,'financial_ledger_entries',(string)$ledgerId,$desiredHash,$batchId);
    return $submissionId;
}

function legacy_financial_day_hash(array $values): string
{
    return legacy_target_hash([
        'opening_amount'=>number_format((float)$values['opening_amount'],2,'.',''),
        'in_total'=>number_format((float)$values['in_total'],2,'.',''),
        'out_total'=>number_format((float)$values['out_total'],2,'.',''),
        'closing_amount'=>$values['closing_amount'] === null ? null : number_format((float)$values['closing_amount'],2,'.',''),
        'status'=>(string)$values['status'],
    ]);
}

function legacy_rebuild_known_financial_days(PDO $pdo,int $clientId,int $batchId,array $items,array $targetActor,array &$counts): void
{
    $groups = [];
    foreach ($items as $item) {
        if (($item['status'] ?? '') !== 'valid') continue;
        $n = $item['normalized'] ?? [];
        if (!is_array($n) || empty($n['store']['id'])) continue;
        $storeId=(int)$n['store']['id'];$date=(string)$n['date'];$account=(string)$n['account'];
        $key=$storeId.'|'.$date.'|'.$account;
        if (!isset($groups[$key])) $groups[$key]=[
            'store_id'=>$storeId,'business_date'=>$date,'account'=>$account,
            'opening_amount'=>0.0,'in_total'=>0.0,'out_total'=>0.0,'closing_amount'=>null,
            'status'=>'open','closing_submission_id'=>null,
        ];
        $type=(string)$n['entry_type'];$amount=(float)$n['amount'];
        if ($type==='OPENING') $groups[$key]['opening_amount']=$amount;
        elseif ($type==='IN') $groups[$key]['in_total'] += $amount;
        elseif ($type==='OUT') $groups[$key]['out_total'] += $amount;
        elseif ($type==='CLOSING') {
            $groups[$key]['closing_amount']=$amount;$groups[$key]['status']='closed';
            $publicId=legacy_uuid_from_key($clientId . '|financial-ledger|' . (string)$item['source_key']);
            $stmt=$pdo->prepare('SELECT id FROM financial_submissions WHERE client_id=? AND public_id=? LIMIT 1');$stmt->execute([$clientId,$publicId]);
            $submissionId=$stmt->fetchColumn();if($submissionId!==false)$groups[$key]['closing_submission_id']=(int)$submissionId;
        }
    }

    foreach ($groups as $key=>$desired) {
        $sourceKey='financial_day_account:' . substr(hash('sha256',$clientId.'|'.$key),0,56);
        $sourceHash=legacy_financial_day_hash($desired);
        $lineage=legacy_lineage($pdo,$clientId,'financial_day_account',$sourceKey);
        $stmt=$pdo->prepare(
            'SELECT id,opening_amount,in_total,out_total,closing_amount,status FROM financial_day_accounts WHERE client_id=? AND store_id=? AND business_date=? AND account=? LIMIT 1'
        );
        $stmt->execute([$clientId,$desired['store_id'],$desired['business_date'],$desired['account']]);
        $existing=$stmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            $currentHash=legacy_financial_day_hash($existing);
            if ($lineage && (string)$lineage['target_hash'] !== $currentHash) {$counts['conflict']++;continue;}
            if (!$lineage && $currentHash !== $sourceHash) {$counts['conflict']++;continue;}
            if (!$lineage || (string)$lineage['source_hash'] !== $sourceHash) {
                $pdo->prepare(
                    'UPDATE financial_day_accounts SET opening_amount=?,in_total=?,out_total=?,closing_amount=?,status=?,opened_by_employee_id=?,closed_by_submission_id=?,closed_at=CASE WHEN ?=\'closed\' THEN COALESCE(closed_at,UTC_TIMESTAMP()) ELSE NULL END WHERE id=?'
                )->execute([
                    number_format((float)$desired['opening_amount'],2,'.',''),number_format((float)$desired['in_total'],2,'.',''),number_format((float)$desired['out_total'],2,'.',''),
                    $desired['closing_amount']===null?null:number_format((float)$desired['closing_amount'],2,'.',''),(string)$desired['status'],(int)$targetActor['id'],$desired['closing_submission_id'],(string)$desired['status'],(int)$existing['id']
                ]);
            }
            $id=(int)$existing['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO financial_day_accounts (client_id,store_id,business_date,account,opening_amount,in_total,out_total,closing_amount,status,opened_by_employee_id,closed_by_submission_id,closed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,CASE WHEN ?=\'closed\' THEN UTC_TIMESTAMP() ELSE NULL END)'
            )->execute([
                $clientId,$desired['store_id'],$desired['business_date'],$desired['account'],number_format((float)$desired['opening_amount'],2,'.',''),
                number_format((float)$desired['in_total'],2,'.',''),number_format((float)$desired['out_total'],2,'.',''),
                $desired['closing_amount']===null?null:number_format((float)$desired['closing_amount'],2,'.',''),(string)$desired['status'],(int)$targetActor['id'],$desired['closing_submission_id'],(string)$desired['status']
            ]);
            $id=(int)$pdo->lastInsertId();
        }
        legacy_save_lineage($pdo,$clientId,'financial_day_account',$sourceKey,$sourceHash,'financial_day_accounts',(string)$id,$sourceHash,$batchId);
    }
}

function legacy_apply_items_known(PDO $pdo,array $actor,int $clientId,int $batchId,array $items,array &$counts): void
{
    // Apply the established attendance side unchanged.
    $attendanceItems=$items;
    $attendanceItems['financial']=[];
    $attendanceItems['financial_ledger']=[];
    $attendanceItems['financial_zreport_reference']=[];
    legacy_apply_items($pdo,$actor,$clientId,$batchId,$attendanceItems,$counts);

    $financialItems=$items['financial_ledger']??[];
    if (!$financialItems) return;
    $targetActor=legacy_financial_target_actor($pdo,$clientId);
    if (!$targetActor) {$counts['conflict']++;return;}

    // One immutable, deterministic submission per source ledger row preserves
    // exact legacy values without replaying zReport calculations or writing back
    // to Google. Day-account balances are rebuilt from those exact ledger lines.
    foreach ($financialItems as $item) legacy_apply_known_financial_line($pdo,$clientId,$batchId,$item,$targetActor,$counts);
    legacy_rebuild_known_financial_days($pdo,$clientId,$batchId,$financialItems,$targetActor,$counts);
}
