<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';
require_once __DIR__ . '/../includes/ui_studio_history.php';

function studio_history_require_dev(array $user): void
{
    if (!beta_actual_user_is_dev($user)) {
        throw new MerdWorkforceException('forbidden', 'Developer access is required.');
    }
}

function studio_history_decode(string $json, array $fallback = []): array
{
    if ($json === '') return $fallback;
    try {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : $fallback;
    } catch (Throwable) {
        return $fallback;
    }
}

function studio_history_ensure_state(PDO $pdo, int $clientId): void
{
    $stmt = $pdo->prepare("INSERT IGNORE INTO ui_studio_state (client_id,revision,patches_json) VALUES (?,0,'[]')");
    $stmt->execute([$clientId]);
}
function studio_history_rows(PDO $pdo, int $clientId): array
{
    $stmt = $pdo->prepare(
        "SELECT public_id,revision,actor_label,role_scope,action,summary,selector,runtime_key,page_path,panel_id,"
        . "nav_group,dialog_id,popover_id,mobile_tools,legacy_only,created_at "
        . "FROM ui_studio_history WHERE client_id=? AND deleted_at IS NULL AND is_system=0 ORDER BY id DESC LIMIT 500"
    );
    $stmt->execute([$clientId]);
    return array_map(static function(array $row): array {
        return [
            'id'=>(string)$row['public_id'], 'revision'=>(int)$row['revision'],
            'actor'=>(string)$row['actor_label'], 'roleScope'=>(string)$row['role_scope'],
            'action'=>(string)$row['action'], 'summary'=>(string)$row['summary'],
            'selector'=>(string)$row['selector'], 'runtimeKey'=>(string)$row['runtime_key'],
            'page'=>(string)$row['page_path'], 'panel'=>(string)$row['panel_id'],
            'navGroup'=>(string)$row['nav_group'], 'dialogId'=>(string)$row['dialog_id'],
            'popoverId'=>(string)$row['popover_id'], 'mobileTools'=>!empty($row['mobile_tools']),
            'legacy'=>!empty($row['legacy_only']), 'at'=>(string)$row['created_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
function studio_history_payload(PDO $pdo, int $clientId): array
{
    studio_history_ensure_state($pdo, $clientId);
    $stmt = $pdo->prepare('SELECT revision,patches_json,updated_at FROM ui_studio_state WHERE client_id=? LIMIT 1');
    $stmt->execute([$clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['revision'=>0,'patches_json'=>'[]','updated_at'=>null];
    return [
        'success'=>true,
        'csrf'=>csrf_token(),
        'revision'=>(int)$row['revision'],
        'patches'=>merd_ui_studio_normalize_patches(studio_history_decode((string)$row['patches_json'])),
        'audit_retained'=>true,
        'updated_at'=>$row['updated_at'],
        'generated_at'=>(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    ];
}

function studio_history_text(mixed $value, int $max): string
{
    $text = trim((string)$value);
    if (mb_strlen($text) > $max) $text = mb_substr($text, 0, $max);
    return $text;
}
function studio_history_insert(PDO $pdo, array $user, int $clientId, int $revision, array $entry, array $mutation, bool $legacy=false, bool $system=false, ?string $createdAt=null): string
{
    $publicId = merd_ui_studio_public_id();
    $created = $createdAt ?: (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $stmt = $pdo->prepare(
        'INSERT INTO ui_studio_history '
        . '(public_id,client_id,revision,actor_employee_id,actor_label,role_scope,action,summary,selector,runtime_key,page_path,panel_id,nav_group,dialog_id,popover_id,mobile_tools,mutation_json,legacy_only,is_system,created_at) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $publicId, $clientId, $revision, (int)$user['id'],
        studio_history_text($user['name'] ?? $user['full_name'] ?? 'Developer', 120),
        studio_history_text(strtoupper((string)($entry['roleScope'] ?? 'DEV')), 32) ?: 'DEV',
        studio_history_text($entry['action'] ?? 'change', 48) ?: 'change',
        studio_history_text($entry['summary'] ?? 'Studio change', 500) ?: 'Studio change',
        studio_history_text($entry['selector'] ?? '', 1024), studio_history_text($entry['runtimeKey'] ?? '', 128),
        studio_history_text($entry['page'] ?? '', 255), studio_history_text($entry['panel'] ?? '', 96),
        studio_history_text($entry['navGroup'] ?? '', 96), studio_history_text($entry['dialogId'] ?? '', 96),
        studio_history_text($entry['popoverId'] ?? '', 96), !empty($entry['mobileTools']) ? 1 : 0,
        json_encode($mutation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $legacy ? 1 : 0, $system ? 1 : 0, $created,
    ]);
    return $publicId;
}
try {
    $user = beta_require_active_user();
    studio_history_require_dev($user);
    $pdo = portal_db();
    $clientId = (int)$user['client_id'];
    studio_history_ensure_state($pdo, $clientId);

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(studio_history_payload($pdo, $clientId));
    }

    $input = request_input();
    require_csrf($input);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $baseRevision = max(0, (int)($input['base_revision'] ?? 0));

    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT revision,patches_json FROM ui_studio_state WHERE client_id=? FOR UPDATE');
    $lock->execute([$clientId]);
    $current = $lock->fetch(PDO::FETCH_ASSOC) ?: ['revision'=>0,'patches_json'=>'[]'];
    $revision = (int)$current['revision'];
    $currentPatches = merd_ui_studio_normalize_patches(studio_history_decode((string)$current['patches_json']));
    if ($action !== 'bootstrap' && $baseRevision !== $revision) {
        $pdo->rollBack();
        $payload = studio_history_payload($pdo, $clientId);
        $payload['success'] = false;
        $payload['error_code'] = 'revision_conflict';
        $payload['error'] = 'Studio changed in another Developer session. The latest global state was loaded.';
        json_response($payload, 409);
    }

    if ($action === 'bootstrap') {
        $count = $pdo->prepare('SELECT COUNT(*) FROM ui_studio_history WHERE client_id=?');
        $count->execute([$clientId]);
        if ($revision !== 0 || (int)$count->fetchColumn() > 0) {
            $pdo->rollBack();
            json_response(studio_history_payload($pdo, $clientId));
        }
        $patches = merd_ui_studio_normalize_patches($input['patches'] ?? []);
        $legacyHistory = is_array($input['history'] ?? null) ? array_slice($input['history'], -300) : [];
        foreach ($legacyHistory as $legacyEntry) {
            if (!is_array($legacyEntry)) continue;
            $at = null;
            try {
                if (!empty($legacyEntry['at'])) {
                    $at = (new DateTimeImmutable((string)$legacyEntry['at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
                }
            } catch (Throwable) { $at = null; }
            studio_history_insert($pdo, $user, $clientId, 0, $legacyEntry, ['remove'=>[],'set'=>[]], true, false, $at);
        }
        $nextRevision = 1;
        $bootstrapMutation = merd_ui_studio_patch_mutation([], $patches);
        studio_history_insert($pdo, $user, $clientId, $nextRevision, [
            'action'=>'bootstrap','summary'=>'Imported existing local Studio draft','roleScope'=>'DEV'
        ], $bootstrapMutation, false, true);
        $update = $pdo->prepare('UPDATE ui_studio_state SET revision=?,patches_json=?,updated_by_employee_id=? WHERE client_id=?');
        $update->execute([$nextRevision, json_encode($patches, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), (int)$user['id'], $clientId]);
        $pdo->commit();
        json_response(studio_history_payload($pdo, $clientId));
    }
    if ($action === 'mutate') {
        $patches = merd_ui_studio_normalize_patches($input['patches'] ?? []);
        $entry = is_array($input['entry'] ?? null) ? $input['entry'] : [];
        $mutation = merd_ui_studio_patch_mutation($currentPatches, $patches);
        $nextRevision = $revision + 1;
        studio_history_insert($pdo, $user, $clientId, $nextRevision, $entry, $mutation);
        $update = $pdo->prepare('UPDATE ui_studio_state SET revision=?,patches_json=?,updated_by_employee_id=? WHERE client_id=?');
        $update->execute([
            $nextRevision,
            json_encode($patches, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            (int)$user['id'], $clientId,
        ]);
        $pdo->commit();
        json_response(studio_history_payload($pdo, $clientId));
    }

    if ($action === 'receipt') {
        $receipt = is_array($input['receipt'] ?? null) ? $input['receipt'] : [];
        if ((int)($receipt['merdposDevStudioReceipt'] ?? 0) !== 1) {
            throw new MerdWorkforceException('invalid_receipt', 'DevStudio Receipt version 1 is required.');
        }
        $sourceRevision = max(0, (int)($receipt['sourceRevision'] ?? 0));
        if ($sourceRevision > $revision) {
            throw new MerdWorkforceException('invalid_receipt_revision', 'Receipt source revision is newer than the current Studio revision.');
        }
        $updates = is_array($receipt['updates'] ?? null) ? array_slice($receipt['updates'], 0, 100) : [];
        if (!$updates) throw new MerdWorkforceException('empty_receipt', 'Receipt contains no patch updates.');
        $byId = [];
        foreach ($currentPatches as $index => $patch) $byId[(string)($patch['patchId'] ?? '')] = $index;
        $nextPatches = $currentPatches; $safeUpdates = []; $confirmed = 0;
        foreach ($updates as $update) {
            if (!is_array($update)) continue;
            $patchId = studio_history_text($update['patchId'] ?? '', 96);
            if ($patchId === '' || !array_key_exists($patchId, $byId)) throw new MerdWorkforceException('patch_not_found', "Unresolved patch {$patchId} is no longer active.");
            $rawStatus = strtolower(trim((string)($update['status'] ?? '')));
            if (!in_array($rawStatus, ['pending','implementing','implemented','blocked','confirmed_applied'], true)) {
                throw new MerdWorkforceException('invalid_patch_status', "Unsupported patch status for {$patchId}.");
            }
            $status = merd_ui_studio_patch_status($rawStatus);
            $safe = ['patchId'=>$patchId,'status'=>$status,'commit'=>studio_history_text($update['commit'] ?? '', 80),'verification'=>studio_history_text($update['verification'] ?? '', 32),'note'=>studio_history_text($update['note'] ?? '', 500)];
            $safeUpdates[] = $safe;
            if ($status === 'confirmed_applied') { unset($nextPatches[$byId[$patchId]]); $confirmed++; }
            else $nextPatches[$byId[$patchId]]['status'] = $status;
        }
        $nextPatches = merd_ui_studio_normalize_patches(array_values($nextPatches));
        $mutation = merd_ui_studio_patch_mutation($currentPatches, $nextPatches);
        $mutation['receipt'] = ['version'=>1,'sourceRevision'=>$sourceRevision,'updates'=>$safeUpdates];
        $nextRevision = $revision + 1;
        studio_history_insert($pdo, $user, $clientId, $nextRevision, ['action'=>'llm_receipt','summary'=>"LLM receipt applied: " . count($safeUpdates) . " updates; {$confirmed} confirmed applied",'roleScope'=>'DEV'], $mutation);
        $updateState = $pdo->prepare('UPDATE ui_studio_state SET revision=?,patches_json=?,updated_by_employee_id=? WHERE client_id=?');
        $updateState->execute([$nextRevision, json_encode($nextPatches, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), (int)$user['id'], $clientId]);
        $pdo->commit();
        $payload = studio_history_payload($pdo, $clientId); $payload['receipt_summary'] = "Receipt applied: " . count($safeUpdates) . " updates; {$confirmed} confirmed.";
        json_response($payload);
    }

    throw new MerdWorkforceException('invalid_action', 'Unknown Studio history action.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    beta_api_error($e);
}
