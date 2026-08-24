<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $tablesStmt = $pdo->query(
        "SELECT TABLE_NAME,TABLE_TYPE,ENGINE "
        . "FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME"
    );

    $columnsStmt = $pdo->prepare(
        "SELECT COLUMN_NAME,ORDINAL_POSITION,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA "
        . "FROM information_schema.COLUMNS "
        . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION"
    );

    $indexesStmt = $pdo->prepare(
        "SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE "
        . "FROM information_schema.STATISTICS "
        . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? "
        . "ORDER BY INDEX_NAME,SEQ_IN_INDEX"
    );

    $fkStmt = $pdo->prepare(
        "SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,"
        . "r.UPDATE_RULE,r.DELETE_RULE "
        . "FROM information_schema.KEY_COLUMN_USAGE k "
        . "LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r "
        . "ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME "
        . "WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? "
        . "AND k.REFERENCED_TABLE_NAME IS NOT NULL "
        . "ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION"
    );

    $tables = [];
    foreach ($tablesStmt->fetchAll(PDO::FETCH_ASSOC) as $tableRow) {
        $tableName = (string)$tableRow['TABLE_NAME'];

        $columnsStmt->execute([$tableName]);
        $columns = [];
        foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = [
                'name' => (string)$row['COLUMN_NAME'],
                'position' => (int)$row['ORDINAL_POSITION'],
                'data_type' => (string)$row['DATA_TYPE'],
                'column_type' => (string)$row['COLUMN_TYPE'],
                'nullable' => strtoupper((string)$row['IS_NULLABLE']) === 'YES',
                'default' => $row['COLUMN_DEFAULT'],
                'extra' => (string)$row['EXTRA'],
            ];
        }

        $indexesStmt->execute([$tableName]);
        $indexes = [];
        foreach ($indexesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)$row['INDEX_NAME'];
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'name' => $name,
                    'unique' => !(bool)$row['NON_UNIQUE'],
                    'type' => (string)$row['INDEX_TYPE'],
                    'columns' => [],
                ];
            }
            $indexes[$name]['columns'][] = [
                'name' => (string)$row['COLUMN_NAME'],
                'prefix_length' => $row['SUB_PART'] === null ? null : (int)$row['SUB_PART'],
            ];
        }

        $fkStmt->execute([$tableName]);
        $foreignKeys = [];
        foreach ($fkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $foreignKeys[] = [
                'constraint' => (string)$row['CONSTRAINT_NAME'],
                'column' => (string)$row['COLUMN_NAME'],
                'references_table' => (string)$row['REFERENCED_TABLE_NAME'],
                'references_column' => (string)$row['REFERENCED_COLUMN_NAME'],
                'update_rule' => (string)($row['UPDATE_RULE'] ?? ''),
                'delete_rule' => (string)($row['DELETE_RULE'] ?? ''),
            ];
        }

        $tables[] = [
            'name' => $tableName,
            'type' => (string)$tableRow['TABLE_TYPE'],
            'engine' => $tableRow['ENGINE'],
            'columns' => $columns,
            'indexes' => array_values($indexes),
            'foreign_keys' => $foreignKeys,
        ];
    }

    $payload = [
        'snapshot_kind' => 'schema_metadata_only',
        'server_version' => (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        'table_count' => count($tables),
        'tables' => $tables,
    ];

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Schema export failed: " . $e->getMessage() . "\n");
    exit(1);
}
