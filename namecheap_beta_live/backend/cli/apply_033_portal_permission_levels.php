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
    $sqlPath = dirname(__DIR__) . '/sql/033_portal_permission_levels.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') throw new RuntimeException('033 migration SQL could not be read.');
    $pdo->exec($sql);

    $clients = $pdo->query('SELECT id FROM clients ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $catalog = merd_portal_permission_catalog();
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO client_permission_levels (client_id,permission_key,min_authority_level) VALUES (?,?,?)'
    );
    $forceDev = $pdo->prepare(
        'UPDATE client_permission_levels SET min_authority_level=1000,updated_by_employee_id=NULL WHERE client_id=? AND permission_key=?'
    );
    $added = 0;
    $forced = 0;

    $pdo->beginTransaction();
    try {
        foreach ($clients as $rawClientId) {
            $clientId = (int)$rawClientId;
            foreach ($catalog as $key => $rule) {
                $level = !empty($rule['dev_only']) ? 1000 : max(1, min(1000, (int)$rule['min_loa']));
                $insert->execute([$clientId, $key, $level]);
                $added += $insert->rowCount();
                if (!empty($rule['dev_only'])) {
                    $forceDev->execute([$clientId, $key]);
                    $forced += $forceDev->rowCount();
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $total = (int)$pdo->query('SELECT COUNT(*) FROM client_permission_levels')->fetchColumn();
    echo "033 portal permission levels applied; " . count($clients) . " clients, {$added} rows added, {$forced} DEV-only rows normalized, {$total} permission rows ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '033 portal permission migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
