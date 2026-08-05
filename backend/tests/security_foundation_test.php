<?php
declare(strict_types=1);

merd_test('security logging redacts secrets, ignores forwarded IP, and truncates values', function (): void {
    $store = new MerdMemorySecurityLogStore();
    merd_security_log_event(
        $store,
        [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.99',
            'HTTP_USER_AGENT' => str_repeat('a', 400),
        ],
        'login_failure',
        'denied',
        ['client_id' => 1],
        [
            'activation_token' => 'secret',
            'pin' => '1234',
            'setup_key' => 'secret',
            'message' => str_repeat('b', 400),
            'reason_code' => str_repeat('c', 400),
        ]
    );
    $event = $store->events[0];
    merd_assert_same('192.0.2.10', $event['ip_address']);
    merd_assert_same(255, strlen($event['user_agent']));
    merd_assert(strpos($event['metadata'], 'secret') === false);
    merd_assert(strpos($event['metadata'], '1234') === false);
    $metadata = json_decode($event['metadata'], true);
    merd_assert_same(255, strlen($metadata['reason_code']));
    merd_assert(!array_key_exists('message', $metadata));
});

merd_test('security logging failures do not escape or expose internal errors', function (): void {
    $store = new MerdMemorySecurityLogStore();
    $store->fail = true;
    merd_security_log_event($store, ['REMOTE_ADDR' => '192.0.2.10'], 'test', 'failed');
    merd_assert(true);
});

merd_test('maintenance guard defaults to denied', function (): void {
    merd_assert(!merd_maintenance_allowed());
    merd_assert(!merd_maintenance_allowed(['enabled' => true]));
    merd_assert(merd_maintenance_allowed(['enabled' => true, 'administratively_authorized' => true]));
});

merd_test('API errors provide stable codes and generic messages', function (): void {
    $payload = merd_api_error_payload('device_unauthorized', 'Device authorization failed.', 'request-id');
    merd_assert_same(false, $payload['success']);
    merd_assert_same('device_unauthorized', $payload['error_code']);
    merd_assert_same('Device authorization failed.', $payload['error']);
});
