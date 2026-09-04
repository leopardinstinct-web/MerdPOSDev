<?php
declare(strict_types=1);

namespace Drupal\merdpos_core\Integration;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserDataInterface;
use GuzzleHttp\ClientInterface;
use Throwable;

final class WorkingNowProvider implements WorkingNowProviderInterface {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ?AccountProxyInterface $currentUser = NULL,
    private readonly ?UserDataInterface $userData = NULL,
  ) {}

  public function load(): array {
    $config = $this->environmentConfig();
    if ($config === null) {
      return $this->result('unconfigured', null, [], 'Read-only MERDPOS service is not configured for this environment.');
    }

    $timestamp = time();
    $signature = hash_hmac(
      'sha256',
      $this->signaturePayload($timestamp, $config['client_id'], $config['actor_user_id']),
      $config['secret'],
    );

    try {
      $response = $this->httpClient->request('GET', $config['url'], [
        'headers' => $this->headers($timestamp, $config, $signature),
        'connect_timeout' => 2.0,
        'timeout' => 3.0,
        'allow_redirects' => false,
        'http_errors' => false,
      ]);

      $statusCode = $response->getStatusCode();
      $payload = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
      if (!is_array($payload)) throw new \RuntimeException('Invalid upstream payload');
      if ($statusCode === 403 || (($payload['error_code'] ?? '') === 'service_forbidden')) {
        return $this->result('forbidden', null, [], 'MERDPOS permission policy does not permit this roster.');
      }
      if ($statusCode !== 200 || ($payload['success'] ?? false) !== true) {
        return $this->result('unavailable', null, [], 'Working Now is temporarily unavailable.');
      }

      $peopleRaw = $payload['people'] ?? null;
      if (!is_array($peopleRaw) || !array_is_list($peopleRaw) || count($peopleRaw) > 500) {
        throw new \RuntimeException('Invalid upstream people list');
      }
      $people = [];
      foreach ($peopleRaw as $row) {
        if (!is_array($row)) continue;
        $people[] = $this->normalizePerson($row);
      }
      return $this->result(
        'ok',
        count($people),
        $people,
        'Live read-only data from MERDPOS.',
        is_string($payload['generated_at'] ?? null) ? $payload['generated_at'] : null,
      );
    } catch (Throwable) {
      return $this->result('unavailable', null, [], 'Working Now is temporarily unavailable.');
    }
  }

  private function environmentConfig(): ?array {
    $url = trim((string)getenv('MERDPOS_DRUPAL_SERVICE_URL'));
    $secret = (string)getenv('MERDPOS_DRUPAL_SERVICE_SECRET');
    $clientRaw = trim((string)getenv('MERDPOS_DRUPAL_CLIENT_ID'));
    $actorUserId = trim((string)getenv('MERDPOS_DRUPAL_ACTOR_USER_ID'));
    if ($url === '' || strlen($secret) < 32
      || !preg_match('/^[1-9]\d*$/', $clientRaw)
      || !preg_match('/^\d{1,20}$/', $actorUserId)) {
      return null;
    }
    $clientId = (int)$clientRaw;
    if ($this->currentUser?->isAuthenticated() && $this->userData !== NULL) {
      $profile = $this->userData->get('merdpos_core', (int)$this->currentUser->id(), 'identity');
      if (is_array($profile) && (int)($profile['client_id'] ?? 0) > 0 && preg_match('/^\d{1,20}$/', (string)($profile['user_id'] ?? ''))) {
        $clientId = (int)$profile['client_id'];
        $actorUserId = (string)$profile['user_id'];
      }
    }

    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return null;
    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);
    if (!in_array($scheme, ['http', 'https'], true)) return null;
    if ($scheme !== 'https' && !in_array($host, ['127.0.0.1', 'localhost'], true)) return null;

    return [
      'url' => $url,
      'secret' => $secret,
      'client_id' => $clientId,
      'actor_user_id' => $actorUserId,
    ];
  }

  private function signaturePayload(int $timestamp, int $clientId, string $actorUserId): string {
    return implode("\n", ['merdpos-service-v1', 'working_now', 'GET', (string)$timestamp, (string)$clientId, $actorUserId]);
  }

  private function headers(int $timestamp, array $config, string $signature): array {
    return [
      'Accept' => 'application/json',
      'X-MERDPOS-Service' => 'drupal-web',
      'X-MERDPOS-Timestamp' => (string)$timestamp,
      'X-MERDPOS-Client-Id' => (string)$config['client_id'],
      'X-MERDPOS-Actor-User-Id' => (string)$config['actor_user_id'],
      'X-MERDPOS-Signature' => $signature,
    ];
  }

  private function normalizePerson(array $row): array {
    return [
      'shift_id' => (string)($row['shift_id'] ?? ''),
      'full_name' => (string)($row['full_name'] ?? ''),
      'user_id' => (string)($row['user_id'] ?? ''),
      'store_id' => max(0, (int)($row['store_id'] ?? 0)),
      'store_name' => (string)($row['store_name'] ?? ''),
      'clock_in_at' => (string)($row['clock_in_at'] ?? ''),
      'working_minutes' => max(0, (int)($row['working_minutes'] ?? 0)),
    ];
  }

  private function result(string $status, ?int $count, array $people, string $message, ?string $generatedAt = null): array {
    return [
      'status' => $status,
      'count' => $count,
      'people' => $people,
      'message' => $message,
      'generated_at' => $generatedAt,
    ];
  }

}
