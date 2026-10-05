<?php
declare(strict_types=1);

/**
 * Applies 040_roster_planning.sql.
 *
 * Runs on every deploy, so it must stay idempotent: the SQL is CREATE TABLE IF
 * NOT EXISTS and this script seeds nothing. Rostering starts empty by design -
 * a manager creates the first week through the roster endpoint rather than the
 * deploy inventing staff assignments.
 */

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $sqlPath = dirname(__DIR__) . '/sql/040_roster_planning.sql';
    $sql = file_get_contents($sqlPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('040 migration SQL could not be read.');
    }
    $pdo->exec($sql);

    $required = ['roster_weeks', 'roster_shifts', 'roster_assignments'];
    $present = [];
    foreach ($required as $table) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException("Expected table {$table} is missing after applying 040.");
        }
        $present[] = $table;
    }

    // Confirm the cross-midnight representation survived: the late shift column in
    // the source roster ends after midnight, so the column must exist AND still be
    // a boolean-typed flag. Existence alone would let a drifted pre-existing table
    // pass this gate.
    $column = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.columns '
        . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $column->execute(['roster_shifts', 'ends_next_day']);
    $type = (string)$column->fetchColumn();
    if ($type === '') {
        throw new RuntimeException('roster_shifts.ends_next_day is missing after applying 040.');
    }
    if (stripos($type, 'tinyint') === false) {
        throw new RuntimeException("roster_shifts.ends_next_day has type {$type}; a boolean flag is required.");
    }

    // The uniqueness the endpoint relies on for "replace the week" semantics and
    // for serialising concurrent saves must exist, not merely be assumed.
    $uniqueKeys = [
        'roster_weeks' => 'uq_roster_weeks_store_week',
        'roster_shifts' => 'uq_roster_shifts_slot',
        'roster_assignments' => 'uq_roster_assignments_shift_employee',
    ];
    $indexStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics '
        . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? AND non_unique = 0'
    );
    foreach ($uniqueKeys as $table => $indexName) {
        $indexStmt->execute([$table, $indexName]);
        if ((int)$indexStmt->fetchColumn() < 1) {
            throw new RuntimeException("Unique key {$indexName} is missing on {$table} after applying 040.");
        }
    }

    $weeks = (int)$pdo->query('SELECT COUNT(*) FROM roster_weeks')->fetchColumn();
    $shifts = (int)$pdo->query('SELECT COUNT(*) FROM roster_shifts')->fetchColumn();
    $assignments = (int)$pdo->query('SELECT COUNT(*) FROM roster_assignments')->fetchColumn();

    echo "040 roster planning applied; tables=" . implode(',', $present)
        . "; weeks={$weeks} shifts={$shifts} assignments={$assignments}."
        . " ends_next_day={$type}.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '040 roster planning failed: ' . $e->getMessage() . "\n");
    exit(1);
}
