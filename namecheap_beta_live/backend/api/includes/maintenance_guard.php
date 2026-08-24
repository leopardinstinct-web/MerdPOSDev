<?php
declare(strict_types=1);

require_once __DIR__ . '/api_response.php';

function merd_maintenance_allowed(array $configuration = []): bool
{
    return isset($configuration['enabled']) && $configuration['enabled'] === true
        && isset($configuration['administratively_authorized'])
        && $configuration['administratively_authorized'] === true;
}

function merd_maintenance_guard(array $configuration = []): void
{
    if (!merd_maintenance_allowed($configuration)) {
        merd_api_fail('not_found', 'Not found.', 404);
    }
}
