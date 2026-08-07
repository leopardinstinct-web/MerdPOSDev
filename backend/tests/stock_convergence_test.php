<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_ledger_balance_migration_test.php';
require_once dirname(__DIR__) . '/api/includes/request.php';

if (!function_exists('clean_text')) {
    function clean_text(mixed $value, int $max): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
        }
        return mb_substr(trim((string)$value), 0, $max);
    }
}

if (!function_exists('clean_timestamp')) {
    function clean_timestamp(mixed $value): string
    {
        $text = clean_text($value, 40);
        try {
            return (new DateTimeImmutable($text))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new MerdRequestException('invalid_request', 400, 'Invalid request.');
        }
    }
}

require_once dirname(__DIR__) . '/api/includes/stock_convergence.php';

function m27_auth(string $device = 'device-a'): array
{
    return ['client_id' => 1, 'store_id' => 11, 'device_uuid' => $device];
}

function m27_move(int $id, string $quantity, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'server_product_id' => 1001,
        'movement_type' => 'sale',
        'quantity_decimal' => $quantity,
        'reference' => 'offline-sale-' . $id,
        'note' => 'Synthetic offline sale',
        'created_at' => '2026-08-06T10:00:00Z',
    ], $overrides);
}

function m27_stock_convergence_test(): void
{
    $pdo = m23_reset_fixture();
    $first = merd_stock_apply_device_movement($pdo, m27_auth(), m27_move(1, '-2.125'));
    m2_assert_same('accepted', $first['outcome'], 'First device movement was not accepted.');
    m2_assert_same('-2.125', $first['balance']['quantity'], 'Exact accepted balance was not returned.');
    m2_assert_same('1', (string)$first['balance']['revision'], 'Accepted balance revision was not returned.');
    m2_assert($first['balance']['negative_stock'] === true, 'Completed offline sale did not create a visible negative-stock exception.');

    $replay = merd_stock_apply_device_movement($pdo, m27_auth(), m27_move(1, '-2.125'));
    m2_assert_same('duplicate', $replay['outcome'], 'Retry was not acknowledged as a duplicate.');
    m2_assert_same((string)$first['server_movement_id'], (string)$replay['server_movement_id'], 'Duplicate did not resolve to the accepted server movement.');
    m2_assert_same('1', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_ledger_movements'), 'Retry duplicated stock.');

    try {
        merd_stock_apply_device_movement($pdo, m27_auth(), m27_move(1, '-3.000'));
        m2_fail('Conflicting replay was accepted.');
    } catch (MerdRequestException $exception) {
        m2_assert_same('stock_replay_conflict', $exception->errorCode, 'Conflicting replay returned the wrong error.');
    }

    $secondDevice = merd_stock_apply_device_movement(
        $pdo,
        m27_auth('device-b'),
        m27_move(1, '-1.000', ['created_at' => '2026-08-05T10:00:00Z'])
    );
    m2_assert_same('accepted', $secondDevice['outcome'], 'Independent device movement was not accepted.');
    m2_assert_same('-3.125', $secondDevice['balance']['quantity'], 'Late-arriving movement was not applied in server acceptance order.');
    m2_assert_same('2', (string)$secondDevice['balance']['revision'], 'Multi-device revision did not converge.');
    m2_assert_same('2', m2_scalar($pdo, 'SELECT COUNT(*) FROM retail_stock_ledger_movements'), 'Multi-device convergence wrote the wrong movement count.');

    try {
        merd_stock_apply_device_movement($pdo, m27_auth(), m27_move(2, '-1.000', ['server_product_id' => 999999]));
        m2_fail('Unknown product movement was accepted.');
    } catch (MerdRequestException $exception) {
        m2_assert_same('stock_product_unavailable', $exception->errorCode, 'Unknown product returned the wrong error.');
    }

    try {
        merd_stock_apply_device_movement($pdo, m27_auth(), m27_move(3, '1.000'));
        m2_fail('Positive sale movement was accepted.');
    } catch (MerdRequestException $exception) {
        m2_assert_same('invalid_stock_direction', $exception->errorCode, 'Invalid sale direction returned the wrong error.');
    }

    foreach (['0', '0.000', '1.2345', '01.000', 1.25] as $invalid) {
        try {
            merd_stock_decimal($invalid);
            m2_fail('Invalid exact stock quantity was accepted.');
        } catch (MerdRequestException $exception) {
            m2_assert_same('invalid_stock_quantity', $exception->errorCode, 'Invalid quantity returned the wrong error.');
        }
    }
}
