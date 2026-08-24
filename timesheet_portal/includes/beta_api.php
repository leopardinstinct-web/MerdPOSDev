<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../../backend/api/includes/workforce_beta.php';

function beta_api_error(Throwable $error): never
{
    if ($error instanceof MerdWorkforceException) {
        json_response(['success' => false, 'error_code' => $error->errorCode, 'error' => $error->getMessage()], 200);
    }
    error_log('timesheet beta API failure: ' . get_class($error));
    json_response(['success' => false, 'error_code' => 'internal_error', 'error' => 'The request could not be completed.'], 500);
}

function beta_require_active_user(): array
{
    $user=require_login();
    $stmt=portal_db()->prepare("SELECT status FROM employees WHERE id=? AND client_id=? LIMIT 1");
    $stmt->execute([(int)$user['id'],(int)$user['client_id']]);
    if($stmt->fetchColumn()!=='active') throw new MerdWorkforceException('account_inactive','Your account is inactive. Contact a SUPER user.');
    return $user;
}

function parse_utc_datetime(mixed $value, bool $optional = true): ?string
{
    if (($value === null || $value === '') && $optional) return null;
    $text = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text, new DateTimeZone(APP_TIMEZONE));
    if (!$date) throw new MerdWorkforceException('invalid_datetime', 'Enter a valid date and time.');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
