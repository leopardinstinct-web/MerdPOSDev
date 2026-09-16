<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class DisputesController extends ControllerBase {

  private const QUERY_CSRF_CONTEXT = 'merdpos_queries_v1';
  private const TYPES = ['missing_out','wrong_in','wrong_out','wrong_store','delete_shift','new_shift','other'];

  public function __construct(
    private readonly PortalGatewayClientInterface $gateway,
    private readonly RequestStack $requestStack,
    private readonly CsrfTokenGenerator $csrf,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('merdpos_core.portal_gateway'),
      $container->get('request_stack'),
      $container->get('csrf_token'),
    );
  }

  public function queries(): JsonResponse|RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request || !$request->isMethod('POST')) throw new AccessDeniedHttpException();
    $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
    $accept = strtolower((string) $request->headers->get('Accept', ''));
    if (str_contains($contentType, 'application/json') || str_contains($accept, 'application/json')) {
      return $this->handleJsonCreate($request);
    }
    return $this->handlePost($request);
  }

  public function disputes(): JsonResponse|RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request) throw new AccessDeniedHttpException();
    if (!$request->isMethod('POST')) {
      return new RedirectResponse(Url::fromRoute('merdpos_core.reports', [], ['fragment'=>'merdpos-shift-detail'])->toString(), 302);
    }
    return $this->queries();
  }

  private function handleJsonCreate(Request $request): JsonResponse {
    $input = json_decode((string) $request->getContent(), true);
    if (!is_array($input)) return new JsonResponse(['success'=>false,'retryable'=>false,'error'=>'Invalid Query request.'], 400);
    $token = trim((string) $request->headers->get('X-MERDPOS-CSRF', $input['form_token'] ?? ''));
    if (!$this->csrf->validate($token, self::QUERY_CSRF_CONTEXT)) {
      return new JsonResponse(['success'=>false,'retryable'=>false,'error'=>'This Query form expired. Refresh Timesheets and try again.'], 403);
    }
    if (strtolower(trim((string) ($input['dispute_action'] ?? 'create'))) !== 'create') {
      return new JsonResponse(['success'=>false,'retryable'=>false,'error'=>'Invalid Query action.'], 422);
    }
    $result = $this->createQuery($input);
    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    if (($result['status'] ?? '') === 'ok' && !empty($payload['success'])) {
      $inner = is_array($payload['result'] ?? NULL) ? $payload['result'] : [];
      return new JsonResponse([
        'success'=>true,
        'retryable'=>false,
        'result'=>$inner,
        'message'=>$this->successMessage('create', strtoupper((string) ($inner['status'] ?? 'saved')), !empty($inner['duplicate'])),
      ]);
    }
    if (($result['status'] ?? '') === 'unavailable') {
      return new JsonResponse(['success'=>false,'retryable'=>true,'error'=>'MERDPOS is temporarily unreachable. The Query remains saved on this device.'], 503);
    }
    $error = trim((string) ($payload['error'] ?? $result['message'] ?? 'The Query could not be submitted.'));
    $code = (string) ($payload['error_code'] ?? 'query_failed');
    $http = $code === 'query_context_changed' ? 409 : (($result['status'] ?? '') === 'forbidden' ? 403 : 422);
    return new JsonResponse(['success'=>false,'retryable'=>false,'error_code'=>$code,'error'=>$error], $http);
  }

  private function handlePost(Request $request): RedirectResponse {
    $token = (string) $request->request->get('form_token', '');
    if (!$this->csrf->validate($token, self::QUERY_CSRF_CONTEXT)) {
      $this->messenger()->addError('This Query form expired. Refresh Timesheets and try again.');
      return $this->redirectBack();
    }
    $action = strtolower(trim((string) $request->request->get('dispute_action', '')));
    $result = match ($action) {
      'create' => $this->createQuery($request->request->all()),
      'decide' => $this->decideQuery($request),
      'cancel' => $this->simpleQueryAction($request, 'cancel'),
      'confirm_handover' => $this->simpleQueryAction($request, 'confirm_handover'),
      'reject_handover' => $this->simpleQueryAction($request, 'reject_handover'),
      'resolve_flag' => $this->resolveFlag($request),
      default => ['status'=>'invalid','payload'=>['success'=>false,'error'=>'Invalid Query action.']],
    };
    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    if (($result['status'] ?? '') === 'ok' && !empty($payload['success'])) {
      $inner = is_array($payload['result'] ?? NULL) ? $payload['result'] : [];
      $this->messenger()->addStatus($this->successMessage($action, strtoupper((string) ($inner['status'] ?? 'saved')), !empty($inner['duplicate'])));
    } else {
      $error = trim((string) ($payload['error'] ?? $result['message'] ?? 'The Query action could not be completed.'));
      $this->messenger()->addError($error ?: 'The Query action could not be completed.');
    }
    return $this->redirectBack();
  }

  private function createQuery(array $input): array {
    $type = strtolower(trim((string) ($input['dispute_type'] ?? 'other')));
    $reason = trim((string) ($input['reason'] ?? ''));
    if (!in_array($type, self::TYPES, true) || strlen($reason) < 5 || strlen($reason) > 1000) {
      return ['status'=>'invalid','payload'=>['success'=>false,'error'=>'Choose a valid issue and provide a clear reason.']];
    }
    $shiftId = $type === 'new_shift' ? '' : trim((string) ($input['shift_id'] ?? ''));
    if ($type !== 'new_shift' && !$this->validPublicId($shiftId)) {
      return ['status'=>'invalid','payload'=>['success'=>false,'error'=>'Choose a valid attendance shift.']];
    }
    $submissionId = trim((string) ($input['submission_id'] ?? ''));
    if ($submissionId !== '' && !$this->validUuidV4($submissionId)) {
      return ['status'=>'invalid','payload'=>['success'=>false,'error'=>'Invalid Query submission reference.']];
    }
    $clientId = filter_var($input['expected_client_id'] ?? NULL, FILTER_VALIDATE_INT);
    $employeeId = filter_var($input['expected_employee_id'] ?? NULL, FILTER_VALIDATE_INT);
    if ($clientId === false || $clientId <= 0 || $employeeId === false || $employeeId <= 0) {
      return ['status'=>'invalid','payload'=>['success'=>false,'error'=>'Reload Timesheets before submitting this Query.']];
    }
    $requestedIn = $this->dateTimeLocal($input['requested_clock_in'] ?? NULL);
    $requestedOut = $this->dateTimeLocal($input['requested_clock_out'] ?? NULL);
    $storeRaw = $input['proposed_store_id'] ?? NULL;
    $storeId = filter_var($storeRaw, FILTER_VALIDATE_INT);
    $storeId = $storeId !== false && $storeId > 0 ? (int) $storeId : NULL;

    if (in_array($type, ['wrong_in','new_shift'], true) && $requestedIn === NULL) {
      return ['status'=>'invalid','payload'=>['success'=>false,'error'=>'Enter the clock-in time.']];
    }
    if (in_array($type, ['missing_out','wrong_out','new_shift'], true) && $requestedOut === NULL) {
      return ['status'=>'invalid','payload'=>['success'=>false,'error'=>'Enter the clock-out time.']];
    }
    if ($type === 'new_shift' && $storeId === NULL) {
      return ['status'=>'invalid','payload'=>['success'=>false,'error'=>'Choose the store for the missing shift.']];
    }
    return $this->gateway->call('disputes', 'POST', [], [
      'action'=>'create',
      'shift_id'=>$shiftId,
      'dispute_type'=>$type,
      'requested_clock_in'=>$requestedIn ?? '',
      'requested_clock_out'=>$requestedOut ?? '',
      'proposed_store_id'=>$storeId ?? '',
      'reason'=>$reason,
      'submission_id'=>$submissionId,
      'expected_client_id'=>(int) $clientId,
      'expected_employee_id'=>(int) $employeeId,
    ]);
  }

  private function decideQuery(Request $request): array {
    $id = trim((string) $request->request->get('dispute_id', ''));
    $decision = strtolower(trim((string) $request->request->get('decision', '')));
    $note = trim((string) $request->request->get('note', ''));

    if (!$this->validPublicId($id) || !in_array($decision, ['approved','rejected'], true) || strlen($note) > 1000) {
      return ['status'=>'invalid','payload'=>['success'=>false,'error'=>'Invalid Query decision.']];
    }
    return $this->gateway->call('disputes', 'POST', [], [
      'action'=>'decide','dispute_id'=>$id,'decision'=>$decision,'note'=>$note,
    ]);
  }

  private function simpleQueryAction(Request $request, string $action): array {
    $id = trim((string) $request->request->get('dispute_id', ''));
    if (!$this->validPublicId($id)) {
      return ['status'=>'invalid','payload'=>['success'=>false,'error'=>'Invalid Query reference.']];
    }
    return $this->gateway->call('disputes', 'POST', [], ['action'=>$action,'dispute_id'=>$id]);
  }

  private function resolveFlag(Request $request): array {
    $id = trim((string) $request->request->get('flag_id', ''));
    $note = trim((string) $request->request->get('note', ''));
    if (!$this->validPublicId($id) || strlen($note) > 1000) {
      return ['status'=>'invalid','payload'=>['success'=>false,'error'=>'Invalid attendance flag resolution.']];
    }
    return $this->gateway->call('disputes', 'POST', [], ['action'=>'resolve_flag','flag_id'=>$id,'note'=>$note]);
  }

  private function redirectBack(): RedirectResponse {
    $query = [];
    $raw = trim((string) ($this->requestStack->getCurrentRequest()?->request->get('return_query', '') ?? ''));
    if ($raw !== '') {
      parse_str(ltrim($raw, '?'), $parsed);
      if (isset($parsed['week_start']) && is_scalar($parsed['week_start'])) $query['week_start'] = (string) $parsed['week_start'];
    }
    return new RedirectResponse(Url::fromRoute('merdpos_core.reports', [], ['query'=>$query,'fragment'=>'merdpos-shift-detail'])->toString());
  }

  private function dateTimeLocal(mixed $value): ?string {
    $text = trim((string) $value);
    if ($text === '') return NULL;
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $text) ? $text : NULL;
  }

  private function validPublicId(string $value): bool {
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
  }

  private function validUuidV4(string $value): bool {
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
  }

  private function successMessage(string $action, string $status, bool $duplicate): string {
    if ($duplicate) return 'This Query is already recorded. No duplicate was created.';
    return match ($action) {
      'create' => 'Query submitted.',
      'decide' => $status === 'APPROVED' ? 'Query approved and the authoritative attendance record was updated.' : 'Query rejected.',
      'cancel' => 'Query cancelled.',
      'confirm_handover' => 'Handover correction confirmed and sent for review.',
      'reject_handover' => 'Handover correction marked as incorrect.',
      'resolve_flag' => 'Attendance security flag resolved and the account reactivated.',
      default => 'Query action completed.',
    };
  }

}
