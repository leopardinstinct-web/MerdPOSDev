<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() === 1;
}
function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() === 1;
}
function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}
function fk_columns(PDO $pdo, string $constraint): array
{
    $stmt = $pdo->prepare(
        'SELECT column_name FROM information_schema.key_column_usage '
        . 'WHERE table_schema=DATABASE() AND constraint_name=? AND referenced_table_name IS NOT NULL ORDER BY ordinal_position'
    );
    $stmt->execute([$constraint]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
function ensure_composite_fk(PDO $pdo, string $table, string $constraint, string $sql, array $columns): void
{
    $current = fk_columns($pdo, $constraint);
    if ($current === $columns) return;
    if ($current) $pdo->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
    $pdo->exec($sql);
}

try {
    $sqlPath = dirname(__DIR__) . '/sql/031_client_roles_dashboard_templates.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') throw new RuntimeException('031 migration SQL could not be read.');
    $pdo->exec($sql);

    if (!index_exists($pdo, 'client_roles', 'uq_client_roles_client_id')) {
        $pdo->exec('ALTER TABLE client_roles ADD UNIQUE KEY uq_client_roles_client_id (client_id,id)');
    }

    if (!column_exists($pdo, 'employees', 'client_role_id')) {
        $pdo->exec('ALTER TABLE employees ADD COLUMN client_role_id INT NULL AFTER role_name');
    }
    if (!index_exists($pdo, 'employees', 'idx_employees_client_role')) {
        $pdo->exec('ALTER TABLE employees ADD KEY idx_employees_client_role (client_id,client_role_id)');
    }

    // Map before enforcing the composite FK so any pre-existing rows are brought
    // onto a role belonging to the same client.
    $pdo->exec(
        "UPDATE employees e INNER JOIN client_roles r ON r.client_id=e.client_id AND r.role_key=UPPER(TRIM(COALESCE(e.employee_type,'USER'))) "
        . 'SET e.client_role_id=r.id,e.role_name=r.role_label WHERE e.client_role_id IS NULL'
    );

    ensure_composite_fk(
        $pdo,
        'employees',
        'fk_employees_client_role',
        'ALTER TABLE employees ADD CONSTRAINT fk_employees_client_role FOREIGN KEY (client_id,client_role_id) REFERENCES client_roles(client_id,id) ON UPDATE RESTRICT ON DELETE RESTRICT',
        ['client_id','client_role_id']
    );
    ensure_composite_fk(
        $pdo,
        'dashboard_role_layouts',
        'fk_dashboard_role_role',
        'ALTER TABLE dashboard_role_layouts ADD CONSTRAINT fk_dashboard_role_role FOREIGN KEY (client_id,role_id) REFERENCES client_roles(client_id,id) ON UPDATE RESTRICT ON DELETE CASCADE',
        ['client_id','role_id']
    );

    if (!table_exists($pdo, 'client_roles') || !table_exists($pdo, 'dashboard_role_layouts')) {
        throw new RuntimeException('Role/dashboard tables are missing after migration.');
    }

    $crossEmployee = (int)$pdo->query(
        'SELECT COUNT(*) FROM employees e LEFT JOIN client_roles r ON r.id=e.client_role_id AND r.client_id=e.client_id '
        . 'WHERE e.client_role_id IS NOT NULL AND r.id IS NULL'
    )->fetchColumn();
    $crossDashboard = (int)$pdo->query(
        'SELECT COUNT(*) FROM dashboard_role_layouts d LEFT JOIN client_roles r ON r.id=d.role_id AND r.client_id=d.client_id WHERE r.id IS NULL'
    )->fetchColumn();
    if ($crossEmployee !== 0 || $crossDashboard !== 0) throw new RuntimeException('Cross-client role references detected.');

    $clientCount = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    $roleCount = (int)$pdo->query('SELECT COUNT(*) FROM client_roles')->fetchColumn();
    $employeeCount = (int)$pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn();
    $mappedEmployees = (int)$pdo->query('SELECT COUNT(*) FROM employees WHERE client_role_id IS NOT NULL')->fetchColumn();
    $missingSystem = (int)$pdo->query(
        "SELECT COUNT(*) FROM clients c CROSS JOIN (SELECT 'USER' role_key UNION ALL SELECT 'ADMIN' UNION ALL SELECT 'SUPER' UNION ALL SELECT 'DEV') x "
        . 'LEFT JOIN client_roles r ON r.client_id=c.id AND r.role_key=x.role_key WHERE r.id IS NULL'
    )->fetchColumn();
    if ($missingSystem !== 0) throw new RuntimeException('One or more system roles were not seeded.');

    echo "031 client roles/dashboard templates applied; {$clientCount} clients, {$roleCount} roles, {$mappedEmployees}/{$employeeCount} employees mapped; tenant FKs verified.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '031 client roles/dashboard templates failed: ' . $e->getMessage() . "\n");
    exit(1);
}
