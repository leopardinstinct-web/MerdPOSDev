<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Auth;

use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

final class MerdposAuthenticator implements MerdposAuthenticatorInterface {

  public function __construct(private readonly ClientInterface $httpClient) {}

  public function authenticate(string $userId, string $password): array {
    $url = $this->loginUrl();
    if ($url === null) {
      return ['status' => 'unconfigured', 'message' => 'MERDPOS login is not configured.'];
    }
    if (!preg_match('/^\d{1,20}$/', $userId) || !preg_match('/^\d{1,20}$/', $password)) {
      return ['status' => 'invalid', 'message' => 'Enter numeric User ID and Password.'];
    }

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => ['user_id' => $userId, 'password' => $password],
        'connect_timeout' => 4.0,
        'timeout' => 10.0,
        'allow_redirects' => false,
        'http_errors' => false,
      ]);
      $statusCode = $response->getStatusCode();
      $payload = $this->decode((string) $response->getBody());

      if ($statusCode === 429) {
        return ['status' => 'locked', 'message' => 'Too many attempts. Try again later.'];
      }
      if ($statusCode !== 200) {
        return ['status' => 'unavailable', 'message' => 'MERDPOS login is temporarily unavailable.'];
      }
      if (($payload['success'] ?? false) !== true) {
        return ['status' => 'invalid', 'message' => $this->safeFailureMessage($payload)];
      }
      $identity = $payload['user'] ?? null;
      if (!is_array($identity) || !$this->validIdentity($identity)) {
        throw new RuntimeException('Invalid MERDPOS identity payload.');
      }
      return ['status' => 'ok', 'identity' => $identity];
    }
    catch (Throwable) {
      return ['status' => 'unavailable', 'message' => 'MERDPOS login is temporarily unavailable.'];
    }
  }

  public function health(): array {
    $url = $this->loginUrl();
    if ($url === null) {
      return ['status' => 'unconfigured', 'message' => 'MERDPOS login is not configured.'];
    }
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['Accept' => 'application/json'],
        'connect_timeout' => 4.0,
        'timeout' => 8.0,
        'allow_redirects' => false,
        'http_errors' => false,
      ]);
      $statusCode = $response->getStatusCode();
      $payload = $this->decode((string) $response->getBody());
      $message = (string) ($payload['error'] ?? '');
      $known = str_contains(strtolower($message), 'login endpoint is working');
      if ($statusCode === 200 && ($payload['success'] ?? true) === false && $known) {
        return ['status' => 'ok', 'http_status' => 200, 'message' => 'Authoritative MERDPOS login endpoint is reachable.'];
      }
      return ['status' => 'unavailable', 'http_status' => $statusCode, 'message' => 'Unexpected MERDPOS login health response.'];
    }
    catch (Throwable) {
      return ['status' => 'unavailable', 'message' => 'MERDPOS login is temporarily unavailable.'];
    }
  }

  private function loginUrl(): ?string {
    $url = trim((string) getenv('MERDPOS_DRUPAL_LOGIN_URL'));
    if ($url === '') return null;
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return null;
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true))) return null;
    return $url;
  }

  /** @return array<string,mixed> */
  private function decode(string $raw): array {
    if ($raw === '' || strlen($raw) > 256 * 1024) throw new RuntimeException('Invalid MERDPOS login response size.');
    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new RuntimeException('Invalid MERDPOS login response.');
    return $payload;
  }

  /** @param array<string,mixed> $identity */
  private function validIdentity(array $identity): bool {
    $id = (int) ($identity['id'] ?? 0);
    $client = (int) ($identity['client_id'] ?? 0);
    $userId = (string) ($identity['user_id'] ?? '');
    $role = strtoupper((string) ($identity['role'] ?? $identity['actual_employee_type'] ?? ''));
    return $id > 0 && $client > 0 && preg_match('/^\d{1,20}$/', $userId) === 1 && in_array($role, ['USER','ADMIN','SUPER','DEV'], true);
  }

  /** @param array<string,mixed> $payload */
  private function safeFailureMessage(array $payload): string {
    $message = trim((string) ($payload['error'] ?? ''));
    if ($message === '') return 'Invalid User ID or Password.';
    if (str_contains(strtolower($message), 'too many attempts')) return 'Too many attempts. Try again later.';
    if (str_contains(strtolower($message), 'temporarily unavailable')) return 'MERDPOS login is temporarily unavailable.';
    return 'Invalid User ID or Password.';
  }

}
