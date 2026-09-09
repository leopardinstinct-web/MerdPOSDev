<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $legacy = $pdo->query(
        "SELECT e.id,e.user_id FROM employees e WHERE e.status='active' AND UPPER(TRIM(e.employee_type))='DEV' ORDER BY e.id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $check = $pdo->prepare(
        "SELECT p.id FROM platform_identities p JOIN platform_identity_preferences pref ON pref.platform_identity_id=p.id "
        . "JOIN clients c ON c.id=pref.selected_client_id AND c.status='active' "
        . "WHERE p.user_id=? AND p.role_key='DEV' AND p.status='active' AND p.login_password<>'' LIMIT 1"
    );
    foreach ($legacy as $row) {
        $check->execute([(string)$row['user_id']]);
        if (!$check->fetchColumn()) throw new RuntimeException('DEV cutover blocked: a legacy DEV row has no usable platform identity and Working Client.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE employees SET status='inactive' WHERE status='active' AND UPPER(TRIM(employee_type))='DEV'");
        $pdo->exec("UPDATE client_roles SET status='inactive' WHERE UPPER(TRIM(role_key))='DEV'");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $remainingEmployees = (int)$pdo->query(
        "SELECT COUNT(*) FROM employees WHERE status='active' AND UPPER(TRIM(employee_type))='DEV'"
    )->fetchColumn();
    $remainingRoles = (int)$pdo->query(
        "SELECT COUNT(*) FROM client_roles WHERE status='active' AND UPPER(TRIM(role_key))='DEV'"
    )->fetchColumn();
    if ($remainingEmployees !== 0 || $remainingRoles !== 0) throw new RuntimeException('DEV cutover verification failed.');
    echo "038 platform DEV identity finalized; " . count($legacy) . " legacy DEV employee row(s) retired, zero active client DEV roles remain.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '038 platform DEV identity finalization failed: ' . $e->getMessage() . "\n");
    exit(1);
}
