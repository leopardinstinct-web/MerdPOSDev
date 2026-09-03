<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/request.php';
require_once __DIR__ . '/../includes/portal_permissions.php';
require_once __DIR__ . '/../includes/service_auth.php';
require_once __DIR__ . '/../includes/service_actor.php';
require_once __DIR__ . '/../includes/workforce_beta.php';

$requestId = merd_request_id();
try {
    merd_request_require_method($_SERVER, 'GET');
    $service = merd_service_authenticate($_SERVER, 'working_now');
    $actor = merd_service_actor(
        $pdo,
        (int)$service['client_id'],
        (string)$service['actor_user_id']
    );
    // This is a dashboard-scoped data dependency, matching dashboard_data.php:
    // the widget permission grants only this roster, not the Workforce area.
    merd_service_require_permissions($pdo, (int)$service['client_id'], $actor['role'], [
        'dashboard.view',
        'dashboard.widget.working_now',
    ]);

    $people = array_map(static function (array $row): array {
        return [
            'shift_id' => (string)($row['shift_id'] ?? ''),
            'full_name' => (string)($row['full_name'] ?? ''),
            'user_id' => (string)($row['user_id'] ?? ''),
            'store_id' => (int)($row['store_id'] ?? 0),
            'store_name' => (string)($row['store_name'] ?? ''),
            'clock_in_at' => (string)($row['clock_in_at'] ?? ''),
            'working_minutes' => (int)($row['working_minutes'] ?? 0),
        ];
    }, merd_working_now($pdo, (int)$service['client_id']));

    merd_api_send(merd_api_success([
        'api' => 'integrations/working_now.php',
        'version' => 'drupal-working-now-v1',
        'generated_at' => gmdate('c'),
        'scope' => [
            'client_id' => (int)$service['client_id'],
            'actor_user_id' => (string)$service['actor_user_id'],
        ],
        'count' => count($people),
        'people' => $people,
    ]));
} catch (MerdRequestException $e) {
    merd_api_fail($e->errorCode, $e->getMessage(), $e->status, $requestId);
} catch (Throwable $e) {
    error_log('Drupal Working Now integration request failed: ' . get_class($e));
    merd_api_fail('internal_error', 'Could not load Working Now.', 500, $requestId);
}
