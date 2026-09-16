<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

try {
    $user = beta_require_active_user();
    $pdo = portal_db();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $review = beta_has_permission($user, 'disputes.review', $pdo);
        $helperUser = $user;
        $helperUser['employee_type'] = $review ? 'SUPER' : 'USER';
        $helperUser['role_name'] = $review ? 'SUPER' : 'USER';
        json_response(['success' => true, 'disputes' => merd_list_disputes($pdo, $helperUser)]);
    }

    $input = request_input();
    require_csrf($input);
    $action = (string)($input['action'] ?? 'create');
    $helperUser = $user;

    if ($action === 'create') {
        beta_require_permission($user, 'disputes.submit_own', $pdo);
        $expectedClient = filter_var($input['expected_client_id'] ?? null, FILTER_VALIDATE_INT);
        $expectedEmployee = filter_var($input['expected_employee_id'] ?? null, FILTER_VALIDATE_INT);
        if (array_key_exists('expected_client_id', $input) && ($expectedClient === false || $expectedClient <= 0)) {
            throw new MerdWorkforceException('invalid_query_context', 'Invalid Query client context.');
        }
        if (array_key_exists('expected_employee_id', $input) && ($expectedEmployee === false || $expectedEmployee <= 0)) {
            throw new MerdWorkforceException('invalid_query_context', 'Invalid Query user context.');
        }
        if (($expectedClient !== false && $expectedClient !== null && (int)$expectedClient !== (int)$user['client_id'])
            || ($expectedEmployee !== false && $expectedEmployee !== null && (int)$expectedEmployee !== (int)$user['id'])) {
            throw new MerdWorkforceException('query_context_changed', 'Working Client or Working User changed. Reload Timesheets before submitting this Query.');
        }
        $helperUser['employee_type'] = 'USER';
        $helperUser['role_name'] = 'USER';
        $result = merd_create_dispute(
            $pdo, $helperUser, trim((string)($input['shift_id'] ?? '')),
            trim((string)($input['dispute_type'] ?? 'other')),
            parse_utc_datetime($input['requested_clock_in'] ?? null),
            parse_utc_datetime($input['requested_clock_out'] ?? null),
            isset($input['proposed_store_id']) && $input['proposed_store_id']!=='' ? (int)$input['proposed_store_id'] : null,
            trim((string)($input['reason'] ?? '')), 'pending', 'employee',
            trim((string)($input['submission_id'] ?? '')) ?: null
        );
    } elseif ($action === 'decide') {
        beta_require_permission($user, 'disputes.review', $pdo);
        $helperUser['employee_type'] = 'SUPER';
        $helperUser['role_name'] = 'SUPER';
        $result = merd_decide_dispute(
            $pdo, $helperUser, trim((string)($input['dispute_id'] ?? '')),
            trim((string)($input['decision'] ?? '')), trim((string)($input['note'] ?? ''))
        );
    } elseif ($action === 'resolve_flag') {
        beta_require_permission($user, 'attendance_flags.resolve', $pdo);
        $helperUser['employee_type'] = 'SUPER';
        $helperUser['role_name'] = 'SUPER';
        $result=merd_resolve_attendance_flag($pdo,$helperUser,trim((string)($input['flag_id']??'')),trim((string)($input['note']??'')));
    } elseif ($action === 'cancel') {
        beta_require_permission($user, 'disputes.submit_own', $pdo);
        $helperUser['employee_type'] = 'USER';
        $helperUser['role_name'] = 'USER';
        $result=merd_cancel_dispute($pdo,$helperUser,trim((string)($input['dispute_id']??'')));
    } elseif ($action === 'confirm_handover') {
        beta_require_permission($user, 'disputes.submit_own', $pdo);
        $helperUser['employee_type'] = 'USER';
        $helperUser['role_name'] = 'USER';
        $result=merd_confirm_handover_dispute($pdo,$helperUser,trim((string)($input['dispute_id']??'')),true);
    } elseif ($action === 'reject_handover') {
        beta_require_permission($user, 'disputes.submit_own', $pdo);
        $helperUser['employee_type'] = 'USER';
        $helperUser['role_name'] = 'USER';
        $result=merd_confirm_handover_dispute($pdo,$helperUser,trim((string)($input['dispute_id']??'')),false);
    } else {
        throw new MerdWorkforceException('invalid_action', 'Invalid Query action.');
    }
    if (beta_actor_is_platform_dev($user) && !empty($user['is_user_impersonation'])) {
        $auditId=(string)($result['dispute_id'] ?? $input['dispute_id'] ?? '');
        beta_admin_audit($pdo,$user,'dev.impersonation.dispute.' . $action,'attendance_dispute',$auditId !== '' ? $auditId : null,['result_status'=>(string)($result['status'] ?? '')]);
    }
    json_response(['success' => true, 'result' => $result]);
} catch (Throwable $e) { beta_api_error($e); }
