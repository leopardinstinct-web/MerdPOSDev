<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\merdpos_core\Integration\PortalGatewayClientInterface;
use JsonException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Roster planner: the plan side of attendance, for one store and one week.
 *
 * The surface is built here rather than in ParityDataProvider because it is an
 * editable grid, not the metric/group/filter shape that provider returns for the
 * read-only sections. Nothing in this controller owns operational SQL: every read
 * and write goes through the portal gateway, which remains authoritative.
 *
 * Offline-first mirrors the Financials contract deliberately, because a planner
 * works on a shop floor with unreliable connectivity:
 *   - the week is RENDERED server-side, so it stays readable for as long as the
 *     tab is open without any further read; there is no service worker and no
 *     persisted copy of the week, and this surface does not claim one;
 *   - edits are QUEUED in localStorage under a versioned key with a UUID v4
 *     idempotency id per submission, so a retry after a dropped connection cannot
 *     double-apply a week;
 *   - the queue flushes on `online` and on page load, and the portal's own
 *     validation stays authoritative - the browser only decides what to retry.
 *
 * Two permissions matter and they are not interchangeable: roster.view sees the
 * whole store (and may edit with roster.manage), while roster.view_own sees only
 * the actor's own shifts, read-only.
 */
final class RosterController extends ControllerBase {

  private const TOKEN_ID = 'merdpos_roster_write_v1';

  private const QUEUE_KEY = 'merdpos_roster_queue_v1';

  /**
   * Slots the note always has; a planner may rename them but not lose them.
   *
   * `next_day` is the slot's own derivation of ends_next_day: the late shift runs
   * 16:00 to 00:00, which is midnight of the following day, so the grid renders
   * its "+1d" indicator rather than asking a planner to tick a box for it.
   */
  private const DEFAULT_SLOTS = [
    'early' => ['label' => 'Early', 'start' => '07:00', 'end' => '16:00', 'next_day' => FALSE],
    'late' => ['label' => 'Late', 'start' => '16:00', 'end' => '00:00', 'next_day' => TRUE],
  ];

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly PortalGatewayClientInterface $gateway,
    private readonly CsrfTokenGenerator $csrf,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('request_stack'),
      $container->get('merdpos_core.portal_gateway'),
      $container->get('csrf_token'),
    );
  }

  public function roster(): array {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request) throw new AccessDeniedHttpException();

    $state = $this->state();
    $permissions = $state['permissions'];
    if (($state['status'] ?? '') !== 'ok') {
      // An unavailable permission check must not render an editable grid.
      return $this->build([
        'status' => 'unavailable',
        'title' => 'Roster',
        'description' => 'MERDPOS could not confirm your roster permissions. This surface is read-only until it can.',
      ], []);
    }
    $canViewStore = in_array('roster.view', $permissions, true);
    $canViewOwn = in_array('roster.view_own', $permissions, true);
    if (!$canViewStore && !$canViewOwn) {
      throw new AccessDeniedHttpException('MERDPOS roster.view permission is required.');
    }
    // roster.view_own is self-service: it must never be served the whole-store
    // grid. The portal refuses scope=store without roster.view, so asking for it
    // would only render a forbidden page for a permission the actor never held.
    $scope = $canViewStore ? 'store' : 'own';
    $canManage = $canViewStore && in_array('roster.manage', $permissions, true);

    $stores = $scope === 'store' ? $this->stores() : [];
    $storeIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $stores);
    $requested = filter_var($request->query->get('store_id'), FILTER_VALIDATE_INT);
    $selectedStore = $requested !== false && in_array((int) $requested, $storeIds, true)
      ? (int) $requested
      : (int) ($storeIds[0] ?? 0);

    // A corrected week must be visible, never a silent substitution.
    $requestedWeek = trim((string) $request->query->get('week_start', ''));
    $weekStart = $this->weekStart($requestedWeek);
    $weekNotice = $requestedWeek !== '' && $requestedWeek !== $weekStart && $scope === 'store'
      ? sprintf('"%s" is not a Monday in YYYY-MM-DD form, so this is the week starting %s.', $requestedWeek, $weekStart)
      : '';

    if ($scope === 'store' && $selectedStore <= 0) {
      $week = ['status' => 'unavailable', 'payload' => [], 'message' => ''];
    }
    else {
      $week = $scope === 'store'
        ? $this->call('roster', ['scope' => 'store', 'store_id' => (string) $selectedStore, 'week_start' => $weekStart])
        : $this->call('roster', ['scope' => 'own', 'week_start' => $weekStart]);
    }

    // The assignable-employee list is planning data and is gated on roster.manage
    // server-side; asking for it without that permission would only produce a 403.
    $employees = [];
    $employeeScope = 'unavailable';
    if ($canManage && $selectedStore > 0) {
      $list = $this->call('roster', ['scope' => 'employees', 'store_id' => (string) $selectedStore]);
      $employeeScope = (string) ($list['status'] ?? 'unavailable');
      foreach (($list['payload']['employees'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $employees[] = ['id' => (int) ($row['id'] ?? 0), 'name' => (string) ($row['full_name'] ?? '')];
      }
    }

    $payload = is_array($week['payload'] ?? NULL) ? $week['payload'] : [];
    $rawShifts = $payload['shifts'] ?? [];
    $status = (string) ($week['status'] ?? 'unavailable');
    $surface = [
      'status' => $status === 'ok' ? 'ok' : ($status === 'forbidden' ? 'forbidden' : 'unavailable'),
      'status_label' => $status === 'ok' ? 'LIVE' : ($status === 'forbidden' ? 'FORBIDDEN' : 'UNAVAILABLE'),
      'scope' => $scope,
      'title' => $scope === 'own' ? 'My roster' : 'Roster planner',
      'description' => $scope === 'own'
        ? 'Your own published shifts for this week. This view is read-only.'
        : 'Plan the week the way the hand-written note does: an early and a late shift per day, with the employees expected on each.',
      'week_notice' => $weekNotice,
      'store' => [
        'id' => $selectedStore,
        'name' => (string) ($payload['store']['store_name'] ?? ($scope === 'own' ? 'Your shifts' : $this->storeName($stores, $selectedStore))),
        'status' => (string) ($payload['store']['status'] ?? ''),
      ],
      'stores' => array_map(static fn(array $row): array => [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['store_name'] ?? ''),
        'selected' => (int) ($row['id'] ?? 0) === $selectedStore,
      ], $stores),
      'week_start' => $weekStart,
      'week_label' => $this->weekLabel($weekStart),
      'week_days' => $this->weekDays($weekStart),
      'week' => [
        'status' => (string) ($payload['week']['status'] ?? 'none'),
        'note' => (string) ($payload['week']['note'] ?? ''),
      ],
      'week_options' => $this->weekOptions($weekStart),
      'shifts' => $scope === 'store' ? $this->shiftRows($rawShifts, $weekStart) : [],
      'own_shifts' => $scope === 'own' ? $this->ownShiftRows($rawShifts, $weekStart) : [],
      'slots' => self::DEFAULT_SLOTS,
      'can_view' => TRUE,
      'can_manage' => $canManage,
      'employees' => $employees,
      'employee_scope' => $employeeScope,
      'permission_message' => $canManage
        ? 'You can plan and publish this roster.'
        : ($scope === 'own'
          ? 'You can read your own shifts. Planning a store roster needs the roster.view permission.'
          : 'You can read this roster. Planning it needs the roster.manage permission.'),
    ];

    return $this->build($surface, $permissions);
  }

  /**
   * Queued week submission. Same contract as the Financials offline write:
   * the browser decides what to RETRY, the portal decides what is TRUE.
   */
  public function submitJson(): JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request || !$request->isMethod('POST')) throw new AccessDeniedHttpException();

    $token = trim((string) $request->headers->get('X-MERDPOS-CSRF', ''));
    if (!$this->csrf->validate($token, self::TOKEN_ID)) {
      return new JsonResponse(['success' => FALSE, 'error' => 'This roster session expired. Refresh the page and try again.'], 403);
    }
    try {
      $input = json_decode($request->getContent(), TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (JsonException) {
      $input = NULL;
    }
    if (!is_array($input)) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Invalid roster submission.'], 400);
    }

    $state = $this->state();
    if (($state['status'] ?? '') !== 'ok') {
      $http = (int) ($state['http_status'] ?? 0);
      $retryable = $http === 0 && ($state['status'] ?? '') === 'unavailable';
      if ($http < 400 || $http > 599) $http = ($state['status'] ?? '') === 'forbidden' ? 403 : 503;
      return new JsonResponse([
        'success' => FALSE,
        'error' => (string) ($state['message'] ?? 'MERDPOS roster permission check is unavailable.'),
        'retryable' => $retryable,
      ], $http);
    }
    if (!in_array('roster.manage', $state['permissions'], TRUE)) {
      return new JsonResponse(['success' => FALSE, 'error' => 'MERDPOS does not allow you to plan this roster.'], 403);
    }

    $week = $this->normalizeQueuedWeek($input);
    if (isset($week['error'])) {
      return new JsonResponse(['success' => FALSE, 'error' => (string) $week['error']], 422);
    }

    $result = $this->gateway->call('roster', 'POST', [], $week + ['action' => 'save_week']);
    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    if (($result['status'] ?? '') === 'ok' && !empty($payload['success'])) {
      return new JsonResponse([
        'success' => TRUE,
        'submission_id' => (string) $week['submission_id'],
        'week' => is_array($payload['week'] ?? NULL) ? $payload['week'] : [],
        'message' => 'Roster saved.',
      ]);
    }

    $error = trim((string) ($payload['error'] ?? $payload['message'] ?? $result['message'] ?? 'The roster could not be saved.'));
    $http = (int) ($result['http_status'] ?? 0);
    $gatewayStatus = (string) ($result['status'] ?? '');
    $retryable = $http === 0 && $gatewayStatus === 'unavailable';
    if ($gatewayStatus === 'ok') $http = 422;
    elseif ($http < 400 || $http > 599) $http = $gatewayStatus === 'forbidden' ? 403 : 503;
    return new JsonResponse(['success' => FALSE, 'error' => $error ?: 'The roster could not be saved.', 'retryable' => $retryable], $http);
  }

  /**
   * Validate a queued week enough to fail fast, and never more than that.
   *
   * Every rule here is enforced again by the portal, which stays authoritative:
   * this only prevents a malformed queue entry from being retried forever.
   */
  private function normalizeQueuedWeek(array $input): array {
    $submissionId = strtolower(trim((string) ($input['submission_id'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $submissionId)) {
      return ['error' => 'Invalid roster submission ID.'];
    }
    $storeId = filter_var($input['store_id'] ?? NULL, FILTER_VALIDATE_INT);
    $weekStart = trim((string) ($input['week_start'] ?? ''));
    $weekDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart, new \DateTimeZone('UTC'));
    if ($storeId === FALSE || $storeId <= 0 || !$weekDate || $weekDate->format('Y-m-d') !== $weekStart || (int) $weekDate->format('N') !== 1) {
      return ['error' => 'Choose a valid store and a week that starts on Monday.'];
    }
    $status = strtolower(trim((string) ($input['status'] ?? 'draft')));
    if (!in_array($status, ['draft', 'published'], TRUE)) {
      return ['error' => 'Choose draft or published.'];
    }
    // A long note is truncated, never rejected. The portal truncates too
    // (roster_save_week: mb_substr($note, 0, 255)), and rejecting here made the
    // browser drop the WHOLE queued week over a note - a wildly disproportionate
    // failure for an optional field. Truncation keeps the failure mode local.
    $note = mb_substr(trim((string) ($input['note'] ?? '')), 0, 255);
    $rawShifts = $input['shifts'] ?? NULL;
    if (!is_array($rawShifts) || !array_is_list($rawShifts)) {
      return ['error' => 'A list of shifts is required.'];
    }
    if (count($rawShifts) > 70) {
      return ['error' => 'A single week cannot hold more than 70 shifts.'];
    }
    $weekEnd = $weekDate->modify('+6 days')->format('Y-m-d');
    $shifts = [];
    $seen = [];
    foreach ($rawShifts as $position => $raw) {
      if (!is_array($raw)) return ['error' => 'Each shift must be an object.'];
      $slot = strtolower(trim((string) ($raw['slot_key'] ?? '')));
      if (!preg_match('/^[a-z0-9_]{1,24}$/', $slot)) return ['error' => 'Each shift needs a slot key such as early or late.'];
      $date = trim((string) ($raw['shift_date'] ?? ''));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $weekStart || $date > $weekEnd) {
        return ['error' => 'Every shift date must fall inside the roster week.'];
      }
      if (isset($seen[$date . '|' . $slot])) return ['error' => 'Two shifts share the same day and slot.'];
      $seen[$date . '|' . $slot] = TRUE;
      $start = $this->clock((string) ($raw['start_time'] ?? ''));
      $end = $this->clock((string) ($raw['end_time'] ?? ''));
      if ($start === NULL || $end === NULL) return ['error' => 'Use a valid 24-hour shift time (HH:MM).'];
      if ($start === $end) return ['error' => 'A shift cannot start and end at the same time.'];
      // ends_next_day is DERIVED from the times and never taken from the client.
      // An end at or before the start necessarily lands on the next day - that is
      // what the default late 16:00-00:00 shift is - and an end after the start
      // does not. The browser renders the same rule; it is not the authority here.
      if (array_key_exists('ends_next_day', $raw)
        && filter_var($raw['ends_next_day'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === NULL) {
        return ['error' => 'ends_next_day must be true or false.'];
      }
      $endsNextDay = $this->endsNextDay($start, $end);
      $label = trim((string) ($raw['label'] ?? ''));
      if (strlen($label) > 48) return ['error' => 'Keep each shift label under 48 characters.'];
      $assignmentIds = [];
      foreach (($raw['assignments'] ?? []) as $rawEmployee) {
        $employeeId = is_array($rawEmployee) ? (int) ($rawEmployee['employee_id'] ?? 0) : (int) $rawEmployee;
        if ($employeeId <= 0) return ['error' => 'Choose valid employees for each shift.'];
        if (!in_array($employeeId, $assignmentIds, TRUE)) $assignmentIds[] = $employeeId;
      }
      if (count($assignmentIds) > 12) return ['error' => 'A shift cannot hold more than 12 employees.'];
      $shifts[] = [
        'shift_date' => $date,
        'slot_key' => $slot,
        'label' => $label === '' ? NULL : $label,
        'start_time' => $start,
        'end_time' => $end,
        'ends_next_day' => $endsNextDay ? 1 : 0,
        'position' => (int) $position,
        'assignments' => array_map(static fn(int $id): array => ['employee_id' => $id], $assignmentIds),
      ];
    }
    return [
      'submission_id' => $submissionId,
      'store_id' => (int) $storeId,
      'week_start' => $weekStart,
      'status' => $status,
      'note' => $note,
      'shifts' => $shifts,
    ];
  }

  private function clock(string $value): ?string {
    $value = trim($value);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) return NULL;
    return strlen($value) === 5 ? $value . ':00' : $value;
  }

  /**
   * The one rule for a shift crossing midnight, applied wherever times are read.
   *
   * Both times are zero-padded 24-hour clocks, so a string comparison is the same
   * as a time comparison.
   */
  private function endsNextDay(string $start, string $end): bool {
    return $end <= $start;
  }

  private function state(): array {
    $result = $this->gateway->call('beta_state', 'GET');
    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    $permissions = [];
    $raw = $payload['permissions'] ?? [];
    if (is_array($raw)) {
      if (array_is_list($raw)) {
        $permissions = array_values(array_filter(array_map('strval', $raw)));
      }
      else {
        foreach ($raw as $key => $enabled) if (is_string($key) && $enabled) $permissions[] = $key;
        $permissions = array_values(array_unique($permissions));
      }
    }
    return [
      'status' => (string) ($result['status'] ?? 'unavailable'),
      'message' => (string) ($result['message'] ?? ''),
      'http_status' => (int) ($result['http_status'] ?? 0),
      'permissions' => $permissions,
    ];
  }

  private function call(string $route, array $query = []): array {
    $result = $this->gateway->call($route, 'GET', $query);
    $payload = is_array($result['payload'] ?? NULL) ? $result['payload'] : [];
    if (($result['status'] ?? '') === 'ok' && ($payload['success'] ?? FALSE) !== TRUE) {
      return ['status' => 'unavailable', 'payload' => [], 'message' => 'MERDPOS returned an unsuccessful response.'];
    }
    return [
      'status' => (string) ($result['status'] ?? 'unavailable'),
      'payload' => $payload,
      'message' => (string) ($result['message'] ?? ''),
    ];
  }

  /** Store scope comes from the portal, so the picker cannot offer more than the actor holds. */
  private function stores(): array {
    $identity = $this->call('store_identity');
    $rows = $identity['payload']['stores'] ?? [];
    if (!is_array($rows)) return [];
    $stores = [];
    foreach ($rows as $row) {
      if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) continue;
      $stores[] = ['id' => (int) $row['id'], 'store_name' => (string) ($row['store_name'] ?? 'Store')];
    }
    return $stores;
  }

  private function storeName(array $stores, int $storeId): string {
    foreach ($stores as $store) if ((int) $store['id'] === $storeId) return (string) $store['store_name'];
    return 'Store';
  }

  /** A roster week is a Monday, in the same UTC terms the portal validates. */
  private function weekStart(string $value): string {
    $value = trim($value);
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
    if ($date && $date->format('Y-m-d') === $value && (int) $date->format('N') === 1) return $value;
    $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    return $today->modify('monday this week')->format('Y-m-d');
  }

  private function weekLabel(string $weekStart): string {
    $start = new \DateTimeImmutable($weekStart, new \DateTimeZone('UTC'));
    $end = $start->modify('+6 days');
    return $start->format('D j M') . ' - ' . $end->format('D j M Y');
  }

  private function weekDays(string $weekStart): array {
    $start = new \DateTimeImmutable($weekStart, new \DateTimeZone('UTC'));
    $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
    $days = [];
    for ($offset = 0; $offset < 7; $offset++) {
      $day = $start->modify("+{$offset} days");
      $days[] = [
        'date' => $day->format('Y-m-d'),
        'weekday' => $day->format('D'),
        'day' => $day->format('j M'),
        'is_today' => $day->format('Y-m-d') === $today,
      ];
    }
    return $days;
  }

  private function weekOptions(string $weekStart): array {
    $selected = new \DateTimeImmutable($weekStart, new \DateTimeZone('UTC'));
    $options = [];
    for ($offset = -4; $offset <= 4; $offset++) {
      $week = $selected->modify(($offset >= 0 ? '+' : '') . $offset . ' weeks');
      $value = $week->format('Y-m-d');
      $options[] = ['value' => $value, 'label' => $this->weekLabel($value), 'selected' => $value === $weekStart];
    }
    return $options;
  }

  /**
   * Shift rows keyed by "date|slot" so the template can render a fixed grid and
   * leave the empty cells explicit rather than implied.
   */
  private function shiftRows(mixed $rows, string $weekStart): array {
    $shifts = [];
    if (!is_array($rows)) return $shifts;
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $date = (string) ($row['shift_date'] ?? '');
      $slot = strtolower((string) ($row['slot_key'] ?? ''));
      if ($date === '' || $slot === '') continue;
      $assignments = [];
      foreach (($row['assignments'] ?? []) as $assignment) {
        if (!is_array($assignment)) continue;
        $assignments[] = [
          'employee_id' => (int) ($assignment['employee_id'] ?? 0),
          'name' => (string) ($assignment['full_name'] ?? ''),
        ];
      }
      $shifts[$date . '|' . $slot] = [
        'key' => $date . '|' . $slot,
        'date' => $date,
        'slot_key' => $slot,
        'label' => (string) ($row['label'] ?? ''),
        'start_time' => substr((string) ($row['start_time'] ?? ''), 0, 5),
        'end_time' => substr((string) ($row['end_time'] ?? ''), 0, 5),
        'ends_next_day' => (int) ($row['ends_next_day'] ?? 0) === 1,
        'assignments' => $assignments,
      ];
    }
    return $shifts;
  }

  /**
   * The scope=own view: the actor's own shifts for the week, read-only.
   *
   * There is no grid and no queue here. Someone who holds only roster.view_own
   * reads their week; they do not plan a store's.
   */
  private function ownShiftRows(mixed $rows, string $weekStart): array {
    if (!is_array($rows)) return [];
    $days = [];
    foreach ($this->weekDays($weekStart) as $day) $days[(string) $day['date']] = $day;
    $shifts = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $date = trim((string) ($row['shift_date'] ?? ''));
      if ($date === '') continue;
      $slot = strtolower(trim((string) ($row['slot_key'] ?? '')));
      $start = substr((string) ($row['start_time'] ?? ''), 0, 5);
      $end = substr((string) ($row['end_time'] ?? ''), 0, 5);
      $label = trim((string) ($row['label'] ?? ''));
      if ($label === '') $label = (string) (self::DEFAULT_SLOTS[$slot]['label'] ?? ($slot === '' ? 'Shift' : ucfirst($slot)));
      $day = $days[$date] ?? ['weekday' => '', 'day' => $date];
      $shifts[] = [
        'date' => $date,
        'weekday' => (string) $day['weekday'],
        'day' => (string) $day['day'],
        'slot_key' => $slot,
        'label' => $label,
        'start_time' => $start,
        'end_time' => $end,
        'ends_next_day' => $start !== '' && $end !== ''
          ? $this->endsNextDay($start, $end)
          : (int) ($row['ends_next_day'] ?? 0) === 1,
        'store_name' => (string) ($row['store_name'] ?? ''),
      ];
    }
    usort($shifts, static fn(array $a, array $b): int => [$a['date'], $a['start_time']] <=> [$b['date'], $b['start_time']]);
    return $shifts;
  }

  private function build(array $surface, array $permissions): array {
    $surface += [
      'status' => 'unavailable',
      'status_label' => 'UNAVAILABLE',
      'scope' => 'store',
      'title' => 'Roster',
      'description' => '',
      'week_notice' => '',
      'store' => ['id' => 0, 'name' => 'Store', 'status' => ''],
      'stores' => [],
      'week_start' => '',
      'week_label' => '',
      'week_days' => [],
      'week' => ['status' => 'none', 'note' => ''],
      'week_options' => [],
      'shifts' => [],
      'own_shifts' => [],
      'slots' => self::DEFAULT_SLOTS,
      'can_view' => FALSE,
      'can_manage' => FALSE,
      'employees' => [],
      'employee_scope' => 'unavailable',
      'permission_message' => '',
    ];
    $surface['write'] = [
      'form_token' => $this->csrf->get(self::TOKEN_ID),
      'submit_url' => Url::fromRoute('merdpos_core.roster_submit')->toString(),
      'queue_key' => self::QUEUE_KEY,
    ];
    return [
      '#theme' => 'merdpos_roster',
      '#surface' => $surface,
      '#attached' => ['library' => ['merdpos_core/roster']],
      '#cache' => [
        'contexts' => ['user', 'url.query_args:store_id', 'url.query_args:week_start'],
        'max-age' => 0,
      ],
    ];
  }

}
