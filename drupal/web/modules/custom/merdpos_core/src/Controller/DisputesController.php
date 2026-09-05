<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class DisputesController extends ControllerBase {

  private const TOKEN_ID = 'merdpos_disputes_v1';
  private const TYPES = ['missing_out','wrong_in','wrong_out','delete_shift','new_shift','other'];

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

  public function disputes(): array|RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request) throw new AccessDeniedHttpException();

    if ($request->isMethod('POST')) return $this->handlePost($request);

    $stateResult = $this->gateway->call('beta_state', 'GET');
    $listResult = $this->gateway->call('disputes', 'GET');
    $state = is_array($stateResult['payload'] ?? NULL) ? $stateResult['payload'] : [];
    $permissions = $this->permissionKeys($state['permissions'] ?? []);
    $canSubmit = in_array('disputes.submit_own', $permissions, true);
    $canReview = in_array('disputes.review', $permissions, true);
    $canResolveFlags = in_array('attendance_flags.resolve', $permissions, true);
    $canView = in_array('disputes.view_own', $permissions, true) || $canReview;
    if (!$canView) throw new AccessDeniedHttpException('MERDPOS dispute permission is required.');

    $rows = is_array($listResult['payload']['disputes'] ?? NULL) ? $listResult['payload']['disputes'] : [];
    $timezone = trim((string) ($state['client_defaults']['timezone'] ?? 'Australia/Sydney')) ?: 'Australia/Sydney';
    $disputes = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $disputes[] = $this->presentDispute($row, $timezone);
    }

    $currentUserId = (string) ($state['current_user_id'] ?? '');
    $ownShifts = [];
    foreach (($state['recent_shifts'] ?? []) as $row) {
      if (!is_array($row) || (string) ($row['user_id'] ?? '') !== $currentUserId) continue;
      $shiftId = trim((string) ($row['shift_id'] ?? ''));
      if ($shiftId === '') continue;
      $ownShifts[] = [
        'id' => $shiftId,
        'label' => $this->shiftLabel($row, $timezone),
        'store' => (string) ($row['store_name'] ?? ''),
        'status' => strtoupper((string) ($row['status'] ?? '')),
      ];
    }

    $stores = [];
    foreach (($state['stores'] ?? []) as $row) {
      if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) continue;
      $stores[] = ['id' => (int) $row['id'], 'name' => (string) ($row['store_name'] ?? '')];
    }

    $flags = [];
    if ($canResolveFlags) {
      foreach (($state['attendance_flags'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $flags[] = [
          'id' => (string) ($row['flag_id'] ?? ''),
          'employee' => (string) ($row['full_name'] ?? ''),
          'store' => (string) ($row['attempted_store'] ?? ''),
          'reason' => ucwords(str_replace('_', ' ', (string) ($row['reason'] ?? ''))),
          'status' => strtolower((string) ($row['status'] ?? '')),
          'created' => $this->localDateTime((string) ($row['created_at'] ?? ''), $timezone),
        ];
      }
    }

    $openCount = 0;
    $pendingCount = 0;
    $awaitingCount = 0;
    foreach ($disputes as $row) {
      if (in_array($row['status'], ['pending','awaiting_employee'], true)) $openCount++;
      if ($row['status'] === 'pending') $pendingCount++;
      if ($row['status'] === 'awaiting_employee') $awaitingCount++;
    }

    return [
      '#theme' => 'merdpos_disputes',
      '#disputes' => $disputes,
      '#flags' => $flags,
      '#own_shifts' => $ownShifts,
      '#stores' => $stores,
      '#form_token' => $this->csrf->get(self::TOKEN_ID),
      '#post_url' => Url::fromRoute('merdpos_core.disputes')->toString(),
      '#can_submit' => $canSubmit,
      '#can_review' => $canReview,
      '#can_resolve_flags' => $canResolveFlags,
      '#role' => [
        'key' => (string) ($state['role_key'] ?? $state['role'] ?? 'USER'),
        'label' => (string) ($state['role_label'] ?? $state['role'] ?? 'MERDPOS'),
        'loa' => (int) ($state['authority_level'] ?? 0),
      ],
      '#counts' => ['open'=>$openCount, 'pending'=>$pendingCount, 'awaiting'=>$awaitingCount, 'flags'=>count(array_filter($flags, static fn(array $f): bool => $f['status'] === 'open'))],
      '#gateway_status' => (($stateResult['status'] ?? '') === 'ok' && ($listResult['status'] ?? '') === 'ok') ? 'ok' : 'unavailable',
      '#attached' => ['library' => ['merdpos_core/disputes']],
      '#cache' => ['contexts' => ['user'], 'max-age' => 0],
    ];
  }

  private function handlePost(Request $request): RedirectResponse {
    $token = (string) $request->request->get('form_token', '');
    if (!$this->csrf->validate($token, self::TOKEN_ID)) {
      $this->messenger()->addError('This dispute form expired. Refresh the page and try again.');
      return $this->redirectBack();
    }

    $action = strtolower(trim((string) $request->request->get('dispute_action', '')));
    $result = match ($action) {
      'create' => $this->createDispute($request),
      'decide' => $this->decideDispute($request),
      'cancel' => $this->simpleDisputeAction($request, 'cancel'),
      'confirm_handover' => $this->simpleDisputeAction($request, 'confirm_handover'),
      'reject_handover' => $this->simpleDisputeAction($request, 'reject_handover'),
      'resolve_flag' => $this->resolveFlag($request),
      default => ['status'=>'invalid', 'payload'=>['success'=>false, 'error'=>'Invalid dispute action.']],
    };

    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    if (($result['status'] ?? '') === 'ok' && !empty($payload['success'])) {
      $inner = is_array($payload['result'] ?? NULL) ? $payload['result'] : [];
      $status = strtoupper((string) ($inner['status'] ?? 'saved'));
      $this->messenger()->addStatus($this->successMessage($action, $status, !empty($inner['duplicate'])));
    }
    else {
      $error = trim((string) ($payload['error'] ?? $result['message'] ?? 'The dispute action could not be completed.'));
      $this->messenger()->addError($error ?: 'The dispute action could not be completed.');
    }
    return $this->redirectBack();
  }

  private function createDispute(Request $request): array {
    $type = strtolower(trim((string) $request->request->get('dispute_type', 'other')));
    $reason = trim((string) $request->request->get('reason', ''));
    if (!in_array($type, self::TYPES, true) || strlen($reason) < 5 || strlen($reason) > 1000) {
      return ['status'=>'invalid', 'payload'=>['success'=>false, 'error'=>'Choose a valid issue and provide a clear reason.']];
    }

    $shiftId = $type === 'new_shift' ? '' : trim((string) $request->request->get('shift_id', ''));
    if ($type !== 'new_shift' && !$this->validPublicId($shiftId)) {
      return ['status'=>'invalid', 'payload'=>['success'=>false, 'error'=>'Choose a valid attendance shift.']];
    }

    $requestedIn = $this->dateTimeLocal($request->request->get('requested_clock_in'));
    $requestedOut = $this->dateTimeLocal($request->request->get('requested_clock_out'));
    $storeRaw = $request->request->get('proposed_store_id');
    $storeId = filter_var($storeRaw, FILTER_VALIDATE_INT);
    $storeId = $storeId !== false && $storeId > 0 ? (int) $storeId : NULL;

    if (in_array($type, ['wrong_in','new_shift'], true) && $requestedIn === NULL) {
      return ['status'=>'invalid', 'payload'=>['success'=>false, 'error'=>'Enter the requested clock-in time.']];
    }
    if (in_array($type, ['missing_out','wrong_out','new_shift'], true) && $requestedOut === NULL) {
      return ['status'=>'invalid', 'payload'=>['success'=>false, 'error'=>'Enter the requested clock-out time.']];
    }
    if ($type === 'new_shift' && $storeId === NULL) {
      return ['status'=>'invalid', 'payload'=>['success'=>false, 'error'=>'Choose the store for the missing shift.']];
    }

    return $this->gateway->call('disputes', 'POST', [], [
      'action' => 'create',
      'shift_id' => $shiftId,
      'dispute_type' => $type,
      'requested_clock_in' => $requestedIn ?? '',
      'requested_clock_out' => $requestedOut ?? '',
      'proposed_store_id' => $storeId ?? '',
      'reason' => $reason,
    ]);
  }

  private function decideDispute(Request $request): array {
    $id = trim((string) $request->request->get('dispute_id', ''));
    $decision = strtolower(trim((string) $request->request->get('decision', '')));
    $note = trim((string) $request->request->get('note', ''));
    if (!$this->validPublicId($id) || !in_array($decision, ['approved','rejected'], true) || strlen($note) > 1000) {
      return ['status'=>'invalid', 'payload'=>['success'=>false, 'error'=>'Invalid dispute decision.']];
    }
    return $this->gateway->call('disputes', 'POST', [], [
      'action'=>'decide', 'dispute_id'=>$id, 'decision'=>$decision, 'note'=>$note,
    ]);
  }

  private function simpleDisputeAction(Request $request, string $action): array {
    $id = trim((string) $request->request->get('dispute_id', ''));
    if (!$this->validPublicId($id)) {
      return ['status'=>'invalid', 'payload'=>['success'=>false, 'error'=>'Invalid dispute reference.']];
    }
    return $this->gateway->call('disputes', 'POST', [], ['action'=>$action, 'dispute_id'=>$id]);
  }

  private function resolveFlag(Request $request): array {
    $id = trim((string) $request->request->get('flag_id', ''));
    $note = trim((string) $request->request->get('note', ''));
    if (!$this->validPublicId($id) || strlen($note) > 1000) {
      return ['status'=>'invalid', 'payload'=>['success'=>false, 'error'=>'Invalid attendance flag resolution.']];
    }
    return $this->gateway->call('disputes', 'POST', [], [
      'action'=>'resolve_flag', 'flag_id'=>$id, 'note'=>$note,
    ]);
  }

  private function redirectBack(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('merdpos_core.disputes')->toString());
  }

  private function dateTimeLocal(mixed $value): ?string {
    $text = trim((string) $value);
    if ($text === '') return NULL;
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $text) ? $text : NULL;
  }

  private function validPublicId(string $value): bool {
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
  }

  private function permissionKeys(mixed $value): array {
    if (!is_array($value)) return [];
    if (array_is_list($value)) return array_values(array_filter(array_map('strval', $value)));
    $keys = [];
    foreach ($value as $key => $enabled) if (is_string($key) && $enabled) $keys[] = $key;
    return array_values(array_unique($keys));
  }

  private function presentDispute(array $row, string $timezone): array {
    $status = strtolower((string) ($row['status'] ?? ''));
    $requested = [];
    if (!empty($row['requested_clock_in_at'])) $requested[] = 'IN ' . $this->localDateTime((string) $row['requested_clock_in_at'], $timezone);
    if (!empty($row['requested_clock_out_at'])) $requested[] = 'OUT ' . $this->localDateTime((string) $row['requested_clock_out_at'], $timezone);
    return [
      'id' => (string) ($row['dispute_id'] ?? ''),
      'shift_id' => (string) ($row['shift_id'] ?? ''),
      'employee' => (string) ($row['full_name'] ?? ''),
      'store' => (string) ($row['store_name'] ?? ''),
      'type' => ucwords(str_replace('_', ' ', (string) ($row['dispute_type'] ?? 'other'))),
      'type_key' => (string) ($row['dispute_type'] ?? 'other'),
      'origin' => (string) ($row['origin'] ?? 'employee'),
      'requested' => $requested ? implode(' · ', $requested) : 'No time change',
      'reason' => trim((string) ($row['reason'] ?? '')),
      'status' => $status,
      'submitted' => $this->localDateTime((string) ($row['submitted_at'] ?? ''), $timezone),
      'decision_note' => trim((string) ($row['decision_note'] ?? '')),
    ];
  }

  private function shiftLabel(array $row, string $timezone): string {
    $in = $this->localDateTime((string) ($row['clock_in_at'] ?? ''), $timezone);
    $out = $this->localDateTime((string) ($row['clock_out_at'] ?? ''), $timezone);
    $store = trim((string) ($row['store_name'] ?? ''));
    return trim($store . ' · ' . $in . ($out !== '—' ? ' → ' . $out : ' · open'));
  }

  private function localDateTime(string $value, string $timezone): string {
    $value = trim($value);
    if ($value === '') return '—';
    try {
      $utc = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
      $zone = new \DateTimeZone($timezone);
      return $utc->setTimezone($zone)->format('d M Y, g:i a');
    }
    catch (\Throwable) {
      return $value;
    }
  }

  private function successMessage(string $action, string $status, bool $duplicate): string {
    if ($duplicate) return 'MERDPOS already had this dispute action recorded. No duplicate change was made.';
    return match ($action) {
      'create' => 'Dispute submitted for approval.',
      'decide' => $status === 'APPROVED' ? 'Dispute approved and the authoritative attendance record was updated.' : 'Dispute rejected.',
      'cancel' => 'Dispute cancelled.',
      'confirm_handover' => 'Handover correction confirmed and sent for review.',
      'reject_handover' => 'Handover correction marked as incorrect.',
      'resolve_flag' => 'Attendance security flag resolved and the account reactivated.',
      default => 'Dispute action completed.',
    };
  }

}
