<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function portal_db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) return $connection;
    if (!is_file(BACKEND_CONFIG_PATH)) {
        throw new RuntimeException('Portal database configuration is unavailable.');
    }
    require BACKEND_CONFIG_PATH;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Portal database connection is unavailable.');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $connection = $pdo;
    return $connection;
}
