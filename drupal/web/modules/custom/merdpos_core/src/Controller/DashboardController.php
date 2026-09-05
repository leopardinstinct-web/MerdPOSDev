<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\merdpos_core\Integration\ParityDataProviderInterface;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Drupal\merdpos_core\Presentation\DashboardChartBuilder;
use JsonException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class DashboardController extends ControllerBase {

  private const ATTENDANCE_TOKEN_ID = 'merdpos-attendance-scan-v1';

  public function __construct(
    private readonly ParityDataProviderInterface $parity,
    private readonly DashboardChartBuilder $chartBuilder,
    private readonly RequestStack $requestStack,
    private readonly PortalGatewayClientInterface $gateway,
    private readonly CsrfTokenGenerator $csrf,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('merdpos_core.parity_provider'),
      $container->get('merdpos_core.dashboard_chart_builder'),
      $container->get('request_stack'),
      $container->get('merdpos_core.portal_gateway'),
      $container->get('csrf_token'),
    );
  }

  public function dashboard(): array {
    $request = $this->requestStack->getCurrentRequest();
    $query = $request ? $request->query->all() : [];
    $surface = $this->parity->home($query);
    if (!empty($surface['can_scan_attendance'])) {
      $surface['attendance_scan'] = [
        'endpoint' => Url::fromRoute('merdpos_core.attendance_scan')->toString(),
        'csrf' => $this->csrf->get(self::ATTENDANCE_TOKEN_ID),
      ];
    }
    return [
      '#theme' => 'merdpos_dashboard',
      '#surface' => $surface,
      '#charts' => $this->chartBuilder->build($surface['chart_specs'] ?? []),
      '#attached' => ['library' => ['merdpos_core/dashboard']],
      '#cache' => [
        'contexts' => ['user', 'url.query_args:store_id', 'url.query_args:period'],
        'max-age' => 0,
      ],
    ];
  }

  public function attendanceScan(): JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request || !$request->isMethod('POST')) {
      return new JsonResponse(['success'=>false, 'error'=>'POST required.'], 405);
    }
    $csrf = trim((string) $request->headers->get('X-MERDPOS-CSRF', ''));
    if (!$this->csrf->validate($csrf, self::ATTENDANCE_TOKEN_ID)) {
      return new JsonResponse(['success'=>false, 'error'=>'Your scan session expired. Refresh Home and try again.'], 403);
    }
    try {
      $input = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
    }
    catch (JsonException) {
      return new JsonResponse(['success'=>false, 'error'=>'Invalid scan request.'], 400);
    }
    if (!is_array($input)) return new JsonResponse(['success'=>false, 'error'=>'Invalid scan request.'], 400);
    $token = $this->extractAttendanceToken(trim((string) ($input['qr'] ?? $input['token'] ?? '')));
    if ($token === NULL) {
      return new JsonResponse(['success'=>false, 'error'=>'This is not a valid MERDPOS attendance QR.'], 422);
    }

    $result = $this->gateway->call('attendance_scan', 'POST', [], ['token'=>$token]);
    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    $attendance = is_array($payload['result'] ?? NULL) ? $payload['result'] : [];
    if (($result['status'] ?? '') === 'ok' && !empty($payload['success']) && $attendance) {
      return new JsonResponse(['success'=>true, 'result'=>[
        'action'=>(string) ($attendance['action'] ?? ''),
        'store_name'=>(string) ($attendance['store_name'] ?? ''),
        'occurred_at'=>(string) ($attendance['occurred_at'] ?? ''),
        'duplicate'=>!empty($attendance['duplicate']),
      ]]);
    }

    $message = trim((string) ($payload['error'] ?? $payload['message'] ?? $result['message'] ?? 'Attendance scan failed.'));
    $http = (int) ($result['http_status'] ?? 503);
    if ($http < 400 || $http > 599) $http = 503;
    return new JsonResponse(['success'=>false, 'error'=>$message ?: 'Attendance scan failed.'], $http);
  }

  private function extractAttendanceToken(string $value): ?string {
    if ($value === '' || strlen($value) > 1800) return NULL;
    $token = $value;
    $parts = parse_url($value);
    if (is_array($parts) && isset($parts['query'])) {
      parse_str((string) $parts['query'], $query);
      $candidate = $query['q'] ?? $query['token'] ?? NULL;
      if (is_string($candidate) && $candidate !== '') $token = $candidate;
    }
    $token = trim($token);
    if (strlen($token) > 1400 || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token)) return NULL;
    return $token;
  }

}
