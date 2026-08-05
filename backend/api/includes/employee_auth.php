<?php
declare(strict_types=1);

function merd_employee_secret_is_hash(mixed $value): bool
{
    if (!is_string($value) || $value === '') {
        return false;
    }
    $info = password_get_info($value);
    return (int)($info['algo'] ?? 0) !== 0;
}

function merd_employee_secret_matches(mixed $stored, string $entered): bool
{
    $value = is_string($stored) ? $stored : '';
    if ($value === '') {
        return false;
    }
    return merd_employee_secret_is_hash($value)
        ? password_verify($entered, $value)
        : hash_equals($value, $entered);
}

function merd_employee_authenticates(array $employee, string $entered): bool
{
    return merd_employee_secret_matches($employee['login_password'] ?? null, $entered)
        || merd_employee_secret_matches($employee['pin_code'] ?? null, $entered);
}

function merd_employee_needs_hash_upgrade(array $employee): bool
{
    return !merd_employee_secret_is_hash($employee['login_password'] ?? null)
        || !merd_employee_secret_is_hash($employee['pin_code'] ?? null);
}
