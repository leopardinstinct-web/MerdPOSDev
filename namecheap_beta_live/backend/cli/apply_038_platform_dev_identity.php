<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $sqlPath = dirname(__DIR__) . '/sql/038_platform_dev_identity.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') throw new RuntimeException('038 migration SQL could not be read.');
    $pdo->exec($sql);

    $dupes = $pdo->query(
        "SELECT user_id,COUNT(*) c FROM employees WHERE status='active' AND UPPER(TRIM(employee_type))='DEV' "
        . "GROUP BY user_id HAVING COUNT(*)>1"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($dupes) throw new RuntimeException('Active DEV employee identities contain duplicate User IDs; migration stopped.');

    $rows = $pdo->query(
        "SELECT id,client_id,full_name,user_id,login_password,pin_code FROM employees "
        . "WHERE status='active' AND UPPER(TRIM(employee_type))='DEV' ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare(
        "INSERT INTO platform_identities (user_id,full_name,login_password,pin_code,role_key,status,legacy_employee_id) "
        . "VALUES (?,?,?,?,'DEV','active',?) ON DUPLICATE KEY UPDATE "
        . "full_name=VALUES(full_name),role_key='DEV',status='active',legacy_employee_id=COALESCE(legacy_employee_id,VALUES(legacy_employee_id))"
    );
    $prefOld = $pdo->prepare(
        "SELECT p.selected_client_id FROM dev_client_preferences p JOIN clients c ON c.id=p.selected_client_id AND c.status='active' "
        . "WHERE p.employee_id=? LIMIT 1"
    );
    $identity = $pdo->prepare("SELECT id FROM platform_identities WHERE user_id=? AND role_key='DEV' AND status='active' LIMIT 1");
    $pref = $pdo->prepare(
        'INSERT INTO platform_identity_preferences (platform_identity_id,selected_client_id) VALUES (?,?) '
        . 'ON DUPLICATE KEY UPDATE selected_client_id=VALUES(selected_client_id),updated_at=CURRENT_TIMESTAMP'
    );
    foreach ($rows as $row) {
        if (!preg_match('/^\d{1,20}$/', (string)$row['user_id'])) throw new RuntimeException('DEV User ID is not a valid numeric portal identity.');
        if (trim((string)$row['login_password']) === '' && trim((string)$row['pin_code']) === '') throw new RuntimeException('DEV employee credential is empty.');
        $insert->execute([(string)$row['user_id'],(string)$row['full_name'],(string)$row['login_password'],$row['pin_code'],(int)$row['id']]);
        $identity->execute([(string)$row['user_id']]);
        $platformId = (int)($identity->fetchColumn() ?: 0);
        if ($platformId <= 0) throw new RuntimeException('Platform DEV identity could not be staged.');
        $prefOld->execute([(int)$row['id']]);
        $selected = (int)($prefOld->fetchColumn() ?: 0);
        if ($selected <= 0) $selected = (int)$row['client_id'];
        $active = $pdo->prepare("SELECT id FROM clients WHERE id=? AND status='active' LIMIT 1");
        $active->execute([$selected]);
        if (!$active->fetchColumn()) $selected = (int)($pdo->query("SELECT id FROM clients WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($selected <= 0) throw new RuntimeException('No active client exists for the DEV Working Client preference.');
        $pref->execute([$platformId,$selected]);
    }

    $missing = (int)$pdo->query(
        "SELECT COUNT(*) FROM employees e LEFT JOIN platform_identities p ON p.user_id=e.user_id AND p.role_key='DEV' AND p.status='active' "
        . "WHERE e.status='active' AND UPPER(TRIM(e.employee_type))='DEV' AND p.id IS NULL"
    )->fetchColumn();
    if ($missing !== 0) throw new RuntimeException('Not every active DEV employee was staged as a platform identity.');
    $platformCount = (int)$pdo->query("SELECT COUNT(*) FROM platform_identities WHERE role_key='DEV' AND status='active'")->fetchColumn();
    if ($platformCount < count($rows)) throw new RuntimeException('Platform DEV identity verification failed.');
    echo "038 platform DEV identity staged; " . count($rows) . " active legacy DEV row(s), {$platformCount} active platform identity row(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, '038 platform DEV identity migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
