<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';
require_once dirname(__DIR__) . '/api/includes/portal_permissions.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $catalog = merd_portal_permission_catalog();
    $rule = $catalog['roles.manage'] ?? null;
    if (!is_array($rule) || !empty($rule['dev_only']) || (int)($rule['min_loa'] ?? 0) !== 50) {
        throw new RuntimeException('roles.manage catalog must be delegable at LOA 50.');
    }
    $stmt = $pdo->prepare(
        'UPDATE client_permission_levels SET min_authority_level=50,updated_by_employee_id=NULL '
        . "WHERE permission_key='roles.manage' AND min_authority_level<>50"
    );
    $stmt->execute();
    $changed = $stmt->rowCount();
    $check = $pdo->query(
        "SELECT COUNT(*) FROM client_permission_levels WHERE permission_key='roles.manage' AND min_authority_level<>50"
    );
    $remaining = (int)$check->fetchColumn();
    if ($remaining !== 0) throw new RuntimeException('roles.manage permission rows were not normalized.');
    echo "037 Admin role delegation applied; {$changed} permission rows normalized to LOA 50.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '037 Admin role delegation failed: ' . $e->getMessage() . "\n");
    exit(1);
}
