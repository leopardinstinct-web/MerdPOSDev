<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/includes/workforce_beta.php';

merd_test('workforce beta creates RFC 4122 UUIDs', function (): void {
    merd_assert((bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', merd_uuid_v4()));
});

merd_test('workforce beta base64url decoder is strict', function (): void {
    merd_assert_same('hello', merd_b64url_decode('aGVsbG8'));
    merd_assert_same(false, merd_b64url_decode('not valid!'));
});

merd_test('workforce beta recognises only explicit SUPER roles', function (): void {
    merd_assert(merd_employee_is_super(['employee_type' => 'SUPER']));
    merd_assert(!merd_employee_is_super(['employee_type' => 'USER', 'role_name' => 'Manager']));
});

merd_test('workforce beta migration contains durable idempotency guards', function (): void {
    $sql = file_get_contents(__DIR__ . '/../sql/021_workforce_financial_beta.sql');
    merd_assert(is_string($sql));
    foreach (['uq_attendance_one_open_shift', 'uq_attendance_qr_employee', 'uq_financial_submission_public', 'uq_financial_day_account', 'uq_financial_submission_line', 'uq_sheet_outbox_event'] as $guard) {
        merd_assert(str_contains($sql, $guard), 'Missing migration guard: ' . $guard);
    }
});

merd_test('financial money conversion uses exact cents at the validation boundary', function (): void {
    merd_assert_same(0, merd_money_cents('0'));
    merd_assert_same(1, merd_money_cents('0.01'));
    merd_assert_same(12346, merd_money_cents('123.456'));
    merd_assert_same('123.46', merd_money(12346));
});
