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

    /*
     * IMPORTANT PAGE/API BOUNDARY
     * ---------------------------
     * The secure backend config is shared with JSON API code and is deliberately
     * not stored in Git. A browser-rendered portal page began loading this file
     * directly when the centralized role/LOA authorization was introduced.
     *
     * A shared config must never be allowed to change the response media type of
     * the caller. In particular, dashboard.php is HTML even though it now needs
     * a database connection to resolve the live role/permission snapshot.
     *
     * We therefore restore the page response contract immediately after loading
     * the backend config. API callers are left untouched and continue to return
     * JSON through json_response().
     */
    $restoreHtmlResponse = PHP_SAPI !== 'cli'
        && function_exists('portal_request_is_api')
        && !portal_request_is_api()
        && function_exists('portal_html_response_headers');

    require BACKEND_CONFIG_PATH;

    if ($restoreHtmlResponse) {
        portal_html_response_headers();
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Portal database connection is unavailable.');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $connection = $pdo;
    return $connection;
}
