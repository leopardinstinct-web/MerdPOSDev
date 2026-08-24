<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/beta_api.php';

try {
    $user = beta_require_active_user();
    $role = strtoupper((string)($user['role'] ?? $user['actual_employee_type'] ?? $user['employee_type'] ?? 'USER'));
    if ($role !== 'DEV') {
        json_response(['success' => false, 'error' => 'DEV access required.'], 403);
    }

    $pdo = portal_db();
    $clientId = (int)$user['client_id'];

    $clientStmt = $pdo->prepare(
        'SELECT id,name,client_code,status,created_at FROM clients WHERE id=? LIMIT 1'
    );
    $clientStmt->execute([$clientId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) {
        throw new MerdWorkforceException('client_not_found', 'Client record not found.');
    }

    $storesStmt = $pdo->prepare(
        'SELECT id,store_name,store_code,status FROM stores WHERE client_id=? ORDER BY id ASC'
    );
    $storesStmt->execute([$clientId]);
    $stores = $storesStmt->fetchAll(PDO::FETCH_ASSOC);

    $employeeCountStmt = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE client_id=?');
    $employeeCountStmt->execute([$clientId]);

    $activeEmployeeCountStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE client_id=? AND status='active'");
    $activeEmployeeCountStmt->execute([$clientId]);

    $deviceCountStmt = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE client_id=?');
    $deviceCountStmt->execute([$clientId]);

    json_response([
        'success' => true,
        'client' => $client,
        'stores' => $stores,
        'counts' => [
            'stores' => count($stores),
            'employees' => (int)$employeeCountStmt->fetchColumn(),
            'active_employees' => (int)$activeEmployeeCountStmt->fetchColumn(),
            'devices' => (int)$deviceCountStmt->fetchColumn(),
        ],
        'scope' => 'current_client',
    ]);
} catch (Throwable $e) {
    beta_api_error($e);
}
