<?php
declare(strict_types=1);

namespace Drupal\merdpos_core\Integration;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserDataInterface;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

final class PortalGatewayClient implements PortalGatewayClientInterface {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ?AccountProxyInterface $currentUser = NULL,
    private readonly ?UserDataInterface $userData = NULL,
  ) {}

  public function call(string $route, string $method = 'GET', array $query = [], array $body = [], ?int $contextClientId = NULL): array {
    $config = $this->environmentConfig();
    if ($config === null) return $this->result('unconfigured', null, null, 'MERDPOS gateway is not configured.');

    $route = strtolower(trim($route));
    $method = strtoupper(trim($method));
    if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $route) || !in_array($method, ['GET','POST'], true)) {
      return $this->result('invalid', null, null, 'Invalid MERDPOS gateway request.');
    }
    if ($contextClientId !== NULL && $contextClientId <= 0) return $this->result('invalid', null, null, 'Invalid MERDPOS client context.');
    if (count($query) > 100 || count($body) > 100) {
      return $this->result('invalid', null, null, 'MERDPOS gateway request is too large.');
    }

    $envelope = ['route'=>$route, 'method'=>$method, 'query'=>(object) $query, 'body'=>(object) $body];
    if ($contextClientId !== NULL) $envelope['context_client_id'] = $contextClientId;
    try {
      $raw = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
      if (strlen($raw) > 1024 * 1024) return $this->result('invalid', null, null, 'MERDPOS gateway request is too large.');

      $timestamp = time();
      $context = 'sha256:' . hash('sha256', $raw);
      $signature = hash_hmac(
        'sha256',
        $this->signaturePayload($timestamp, $config['client_id'], $config['actor_user_id'], $context),
        $config['secret'],
      );

      $response = $this->httpClient->request('POST', $config['url'], [
        'headers' => $this->headers($timestamp, $config, $signature),
        'body' => $raw,
        'connect_timeout' => 3.0,
        'timeout' => 12.0,
        'allow_redirects' => false,
        'http_errors' => false,
      ]);
      $statusCode = $response->getStatusCode();
      $responseRaw = (string) $response->getBody();
      if ($responseRaw === '' || strlen($responseRaw) > 2 * 1024 * 1024) throw new RuntimeException('Invalid gateway response size');
      $payload = json_decode($responseRaw, true, 64, JSON_THROW_ON_ERROR);
      if (!is_array($payload)) throw new RuntimeException('Invalid gateway response');
      if ($statusCode === 401 || $statusCode === 403) {
        return $this->result('forbidden', $statusCode, $payload, 'MERDPOS gateway authorization was rejected.');
      }
      if ($statusCode !== 200) {
        return $this->result('unavailable', $statusCode, $payload, 'MERDPOS gateway is temporarily unavailable.');
      }
      return $this->result('ok', $statusCode, $payload, 'Canonical MERDPOS response received.');
    } catch (Throwable) {
      return $this->result('unavailable', null, null, 'MERDPOS gateway is temporarily unavailable.');
    }
  }

  private function environmentConfig(): ?array {
    $url = trim((string) getenv('MERDPOS_DRUPAL_GATEWAY_URL'));
    if ($url === '') {
      $workingUrl = trim((string) getenv('MERDPOS_DRUPAL_SERVICE_URL'));
      $url = preg_replace('/working_now\.php$/', 'portal_gateway.php', $workingUrl) ?: '';
    }
    $secret = (string) getenv('MERDPOS_DRUPAL_SERVICE_SECRET');
    $clientRaw = trim((string) getenv('MERDPOS_DRUPAL_CLIENT_ID'));
    $actorUserId = trim((string) getenv('MERDPOS_DRUPAL_ACTOR_USER_ID'));
    if ($url === '' || strlen($secret) < 32 || !preg_match('/^[1-9]\d*$/', $clientRaw) || !preg_match('/^\d{1,20}$/', $actorUserId)) return null;
    $clientId = (int) $clientRaw;
    if ($this->currentUser?->isAuthenticated() && $this->userData !== NULL) {
      $profile = $this->userData->get('merdpos_core', (int) $this->currentUser->id(), 'identity');
      if (is_array($profile) && (int) ($profile['client_id'] ?? 0) > 0 && preg_match('/^\d{1,20}$/', (string) ($profile['user_id'] ?? ''))) {
        $clientId = (int) $profile['client_id'];
        $actorUserId = (string) $profile['user_id'];
      }
    }

    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return null;
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    if (!in_array($scheme, ['http','https'], true)) return null;
    if ($scheme !== 'https' && !in_array($host, ['127.0.0.1','localhost'], true)) return null;

    return [
      'url'=>$url,
      'secret'=>$secret,
      'client_id'=>$clientId,
      'actor_user_id'=>$actorUserId,
    ];
  }

  private function signaturePayload(int $timestamp, int $clientId, string $actorUserId, string $context): string {
    return implode("\n", [
      'merdpos-service-v1',
      'portal_gateway',
      'POST',
      (string) $timestamp,
      (string) $clientId,
      $actorUserId,
      $context,
    ]);
  }

  private function headers(int $timestamp, array $config, string $signature): array {
    return [
      'Accept'=>'application/json',
      'Content-Type'=>'application/json',
      'X-MERDPOS-Service'=>'drupal-web',
      'X-MERDPOS-Timestamp'=>(string) $timestamp,
      'X-MERDPOS-Client-Id'=>(string) $config['client_id'],
      'X-MERDPOS-Actor-User-Id'=>$config['actor_user_id'],
      'X-MERDPOS-Signature'=>$signature,
    ];
  }

  private function result(string $status, ?int $httpStatus, ?array $payload, string $message): array {
    return [
      'status'=>$status,
      'http_status'=>$httpStatus,
      'payload'=>$payload,
      'message'=>$message,
    ];
  }

}
