<?php
declare(strict_types=1);

require_once __DIR__ . '/legacy_migration.php';

function legacy_supersede_old_conflicts(PDO $pdo, int $clientId, int $currentBatchId, int $actorId, string $currentPublicId): void
{
    $stmt = $pdo->prepare(
        "UPDATE legacy_migration_conflicts SET status='resolved',resolved_by_employee_id=?,resolved_at=UTC_TIMESTAMP(),"
        . "resolution_note=? WHERE client_id=? AND status='open' AND batch_id<>?"
    );
    $stmt->execute([$actorId,'Superseded by newer migration batch ' . $currentPublicId,$clientId,$currentBatchId]);
}

function legacy_preview_snapshot(PDO $pdo, array $state): ?array
{
    $id = (int)($state['last_preview_batch_id'] ?? 0);
    if ($id < 1) return null;
    $stmt = $pdo->prepare("SELECT id,public_id,status,source_snapshot_hash,finished_at FROM legacy_migration_batches WHERE id=? AND client_id=? AND mode='preview' LIMIT 1");
    $stmt->execute([$id,(int)$state['client_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function legacy_run_batch_safe(PDO $pdo, array $actor, int $clientId, string $mode): array
{
    if (!in_array($mode,['preview','sync','final'],true)) throw new MerdWorkforceException('invalid_migration_mode','Invalid migration mode.');
    $state = legacy_migration_state($pdo,$clientId);
    if ($mode !== 'preview' && ((string)$state['attendance_authority'] === 'merdpos_sql' || (string)$state['financial_authority'] === 'merdpos_sql')) {
        throw new MerdWorkforceException('migration_cutover_complete','This client is already SQL-authoritative. Google can be previewed but cannot overwrite MERDPOS after cutover.');
    }
    $sources = legacy_source_state($pdo,$clientId);
    if (!isset($sources['attendance']) || $sources['attendance']['status'] !== 'active') throw new MerdWorkforceException('attendance_source_missing','Configure the attendance Google Sheet before migration.');
    if ($mode === 'final' && (!isset($sources['financial']) || $sources['financial']['status'] !== 'active')) throw new MerdWorkforceException('financial_source_missing','Configure the financial Google Sheet before final cutover.');

    @set_time_limit(240);
    $public = merd_uuid_v4();
    $stmt = $pdo->prepare("INSERT INTO legacy_migration_batches (public_id,client_id,mode,status,started_by_employee_id) VALUES (?,?,?,'running',?)");
    $stmt->execute([$public,$clientId,$mode,(int)$actor['id']]);
    $batchId = (int)$pdo->lastInsertId();

    try {
        $fetched = legacy_fetch_sources($sources);
        if ($mode !== 'preview') {
            $preview = legacy_preview_snapshot($pdo,$state);
            if (!$preview || (string)$preview['status'] !== 'staged' || !hash_equals((string)$preview['source_snapshot_hash'],(string)$fetched['snapshot_hash'])) {
                throw new MerdWorkforceException('preview_required','The Google source changed or has not been previewed. Run Preview changes again before Sync.');
            }
        }

        $validated = legacy_validate_and_stage($pdo,$batchId,$clientId,$fetched);
        legacy_supersede_old_conflicts($pdo,$clientId,$batchId,(int)$actor['id'],$public);
        $c = $validated['counts'];
        $apply = ['inserted'=>0,'updated'=>0,'unchanged'=>0,'conflict'=>0,'rejected'=>$c['rejected'],'warning'=>$c['warning']];
        if ($mode !== 'preview') legacy_apply_items($pdo,$actor,$clientId,$batchId,$validated['items'],$apply);

        $summary = legacy_batch_summary($pdo,$batchId);
        $stageConflicts = (int)$summary['open_conflicts'];
        $conflicts = $stageConflicts + (int)$apply['conflict'];
        $status = $mode === 'preview' ? 'staged' : (($conflicts > 0 || (int)$c['rejected'] > 0) ? 'completed_with_conflicts' : 'completed');

        $update=$pdo->prepare('UPDATE legacy_migration_batches SET status=?,source_snapshot_hash=?,attendance_rows=?,financial_rows=?,inserted_rows=?,updated_rows=?,unchanged_rows=?,conflict_rows=?,rejected_rows=?,warning_rows=?,summary_json=?,finished_at=UTC_TIMESTAMP() WHERE id=?');
        $update->execute([$status,$fetched['snapshot_hash'],$c['attendance_rows'],$c['financial_rows'],$apply['inserted'],$apply['updated'],$apply['unchanged'],$conflicts,$c['rejected'],$c['warning'],json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$batchId]);

        if ($mode === 'preview') $pdo->prepare('UPDATE client_migration_state SET last_preview_batch_id=? WHERE client_id=?')->execute([$batchId,$clientId]);
        else $pdo->prepare('UPDATE client_migration_state SET last_sync_batch_id=? WHERE client_id=?')->execute([$batchId,$clientId]);

        if ($mode === 'final') {
            if ($status !== 'completed') throw new MerdWorkforceException('final_sync_not_clean','Final cutover requires zero rejected rows and zero conflicts in the final batch. Review the migration report first.');
            $pdo->prepare(
                "UPDATE client_migration_state SET attendance_authority='merdpos_sql',financial_authority='merdpos_sql',"
                . 'attendance_cutover_at=UTC_TIMESTAMP(),financial_cutover_at=UTC_TIMESTAMP(),cutover_by_employee_id=? WHERE client_id=?'
            )->execute([(int)$actor['id'],$clientId]);
        }

        return [
            'batch_id'=>$public,'mode'=>$mode,'status'=>$status,'source_snapshot_hash'=>$fetched['snapshot_hash'],
            'attendance_rows'=>(int)$c['attendance_rows'],'financial_rows'=>(int)$c['financial_rows'],
            'inserted'=>(int)$apply['inserted'],'updated'=>(int)$apply['updated'],'unchanged'=>(int)$apply['unchanged'],
            'conflicts'=>$conflicts,'rejected'=>(int)$c['rejected'],'warnings'=>(int)$c['warning'],'summary'=>$summary,
        ];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE legacy_migration_batches SET status='failed',error_message=?,finished_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([substr($e->getMessage(),0,1000),$batchId]);
        throw $e;
    }
}
