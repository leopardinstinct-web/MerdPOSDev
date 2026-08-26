<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/beta_api.php';

try {
    $user = beta_require_active_user();
    json_response([
        'success' => true,
        'user' => [
            'id' => (int)$user['id'],
            'name' => (string)$user['name'],
            'user_id' => (string)$user['user_id'],
            'role_key' => (string)($user['role_key'] ?? $user['role'] ?? 'USER'),
            'role_label' => (string)($user['role_label'] ?? $user['role_name'] ?? 'User'),
            'authority_level' => (int)($user['authority_level'] ?? 0),
            'client_role_id' => isset($user['client_role_id']) ? (int)$user['client_role_id'] : null,
            'client_id' => (int)$user['client_id'],
            'auth_client_id' => (int)($user['auth_client_id'] ?? $user['client_id']),
            'permissions' => (array)($user['permissions'] ?? []),
            'permission_levels' => (array)($user['permission_levels'] ?? []),
        ],
    ]);
} catch (Throwable $e) {
    beta_api_error($e);
}
