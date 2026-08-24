<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

try {
    $user = beta_require_active_user();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        json_response(['success' => true, 'disputes' => merd_list_disputes(portal_db(), $user)]);
    }
    $input = request_input();
    require_csrf($input);
    $action = (string)($input['action'] ?? 'create');
    if ($action === 'create') {
        if (!empty($user['is_super'])) {
            throw new MerdWorkforceException('forbidden', 'SUPER users review disputes raised by USER accounts.');
        }
        $result = merd_create_dispute(
            portal_db(), $user, trim((string)($input['shift_id'] ?? '')),
            trim((string)($input['dispute_type'] ?? 'other')),
            parse_utc_datetime($input['requested_clock_in'] ?? null),
            parse_utc_datetime($input['requested_clock_out'] ?? null),
            isset($input['proposed_store_id']) && $input['proposed_store_id']!=='' ? (int)$input['proposed_store_id'] : null,
            trim((string)($input['reason'] ?? ''))
        );
    } elseif ($action === 'decide') {
        $result = merd_decide_dispute(
            portal_db(), $user, trim((string)($input['dispute_id'] ?? '')),
            trim((string)($input['decision'] ?? '')), trim((string)($input['note'] ?? ''))
        );
    } elseif ($action === 'resolve_flag') {
        $result=merd_resolve_attendance_flag(portal_db(),$user,trim((string)($input['flag_id']??'')),trim((string)($input['note']??'')));
    } elseif ($action === 'cancel') {
        $result=merd_cancel_dispute(portal_db(),$user,trim((string)($input['dispute_id']??'')));
    } elseif ($action === 'confirm_handover') {
        $result=merd_confirm_handover_dispute(portal_db(),$user,trim((string)($input['dispute_id']??'')),true);
    } elseif ($action === 'reject_handover') {
        $result=merd_confirm_handover_dispute(portal_db(),$user,trim((string)($input['dispute_id']??'')),false);
    } else {
        throw new MerdWorkforceException('invalid_action', 'Invalid dispute action.');
    }
    json_response(['success' => true, 'result' => $result]);
} catch (Throwable $e) { beta_api_error($e); }
