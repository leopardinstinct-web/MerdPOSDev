<?php
declare(strict_types=1);
/**
 * Controller-side proof for the roster write contract (M1, M2, m5).
 *
 * The Drupal container is not available here, so ControllerBase is stubbed and the
 * real RosterController.php source is loaded and reflected on with
 * newInstanceWithoutConstructor(). normalizeQueuedWeek()/clock()/endsNextDay() use
 * nothing from the base class, so this executes the shipped implementation, not a
 * copy of it. The gateway and CSRF dependencies are never touched.
 *
 * Run: php84 drupal/tools/verify_roster_controller.php <RosterController.php>
 *
 * LIMIT, learned from a failed deploy: this harness STUBS ControllerBase so the
 * method can run without Drupal. A stubbed parent cannot reveal an inheritance
 * conflict - a private method named after a protected ControllerBase member is a
 * fatal error at class load, and this harness passed while the real class could not
 * even be loaded. drupal/tools/validate_roster_planner_v1.php owns that check.
 */

namespace Drupal\Core\Controller {
  class ControllerBase {}
}

namespace {

  $controllerPath = $argv[1] ?? '';
  if (!is_file($controllerPath)) { fwrite(STDERR, "unreadable controller: {$controllerPath}\n"); exit(2); }
  require $controllerPath;

  $reflection = new \ReflectionClass(\Drupal\merdpos_core\Controller\RosterController::class);
  $controller = $reflection->newInstanceWithoutConstructor();
  $normalize = $reflection->getMethod('normalizeQueuedWeek');
  $normalize->setAccessible(TRUE);

  $results = [];
  $check = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
  };

  $weekStart = '2026-10-05';
  $monday = (new \DateTimeImmutable($weekStart, new \DateTimeZone('UTC')))->format('N') === '1';
  $check('fixture week start is a Monday', $monday, $weekStart);

  $week = static function (array $shifts, string $note = '', string $status = 'draft') use ($weekStart): array {
    return [
      'submission_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
      'store_id' => 4,
      'week_start' => $weekStart,
      'status' => $status,
      'note' => $note,
      'shifts' => $shifts,
    ];
  };
  $shift = static function (array $overrides) use ($weekStart): array {
    return $overrides + [
      'shift_date' => $weekStart,
      'slot_key' => 'late',
      'label' => '',
      'start_time' => '16:00',
      'end_time' => '00:00',
      'assignments' => [],
    ];
  };
  $normalizeShift = static function (array $shift) use ($normalize, $controller, $week): array {
    return $normalize->invoke($controller, $week([$shift]));
  };

  // M1: the default late 16:00-00:00 shift, with the old client flag false.
  $result = $normalizeShift($shift(['ends_next_day' => FALSE]));
  $check('M1 a 16:00-00:00 shift with ends_next_day=false is accepted', !isset($result['error']), (string) ($result['error'] ?? ''));
  $check('M1 the controller derives ends_next_day=1 for 16:00-00:00', ($result['shifts'][0]['ends_next_day'] ?? NULL) === 1, var_export($result['shifts'][0]['ends_next_day'] ?? NULL, TRUE));

  // M1: the browser may omit the flag entirely; the server still knows the truth.
  $result = $normalizeShift($shift([]));
  $check('M1 a 16:00-00:00 shift with no flag at all is accepted', !isset($result['error']), (string) ($result['error'] ?? ''));
  $check('M1 the derived flag is 1 when the flag is absent', ($result['shifts'][0]['ends_next_day'] ?? NULL) === 1, var_export($result['shifts'][0]['ends_next_day'] ?? NULL, TRUE));

  // M1: a client flag cannot override the derivation in either direction.
  $result = $normalizeShift($shift(['end_time' => '16:00', 'start_time' => '07:00', 'slot_key' => 'early', 'ends_next_day' => TRUE]));
  $check('M1 a client saying 07:00-16:00 ends next day is ignored', ($result['shifts'][0]['ends_next_day'] ?? NULL) === 0, var_export($result['shifts'][0]['ends_next_day'] ?? NULL, TRUE));
  $result = $normalizeShift($shift(['start_time' => '23:30', 'end_time' => '00:15', 'ends_next_day' => FALSE]));
  $check('M1 23:30-00:15 derives ends_next_day=1', ($result['shifts'][0]['ends_next_day'] ?? NULL) === 1, var_export($result['shifts'][0]['ends_next_day'] ?? NULL, TRUE));
  $result = $normalizeShift($shift(['start_time' => '16:00', 'end_time' => '16:30']));
  $check('M1 16:00-16:30 does not end the next day', ($result['shifts'][0]['ends_next_day'] ?? NULL) === 0, var_export($result['shifts'][0]['ends_next_day'] ?? NULL, TRUE));

  // The rule that must NOT be weakened: an impossible same-time shift is refused.
  $result = $normalizeShift($shift(['start_time' => '08:00', 'end_time' => '08:00']));
  $check('the same start and end is still refused', isset($result['error']), (string) ($result['error'] ?? ''));

  // M2 (server half): a shift with zero assignments is a legitimate shift.
  $result = $normalizeShift($shift(['start_time' => '09:00', 'end_time' => '17:00', 'assignments' => []]));
  $check('M2 an emptied shift is accepted with zero assignments', !isset($result['error']) && ($result['shifts'][0]['assignments'] ?? NULL) === [], json_encode($result['shifts'][0]['assignments'] ?? NULL));

  // m5: the note limit is bytes, so the client must truncate by bytes.
  $check('m5 a 255-byte ASCII note is accepted', !isset($normalize->invoke($controller, $week([], str_repeat('a', 255)))['error']));
  // An over-long note is TRUNCATED, not refused: refusing it dropped the entire
  // queued week, and the portal truncates too (mb_substr($note, 0, 255)).
  $longAscii = $normalize->invoke($controller, $week([], str_repeat('a', 300)));
  $check('m5 an over-long ASCII note is truncated to 255 characters, not refused', !isset($longAscii['error']) && mb_strlen((string) ($longAscii['note'] ?? '')) === 255, 'chars=' . mb_strlen((string) ($longAscii['note'] ?? '')));
  $check('m5 a 255-byte multi-byte note is accepted', !isset($normalize->invoke($controller, $week([], str_repeat('é', 127) . 'a'))['error']));
  $longMulti = $normalize->invoke($controller, $week([], str_repeat('é', 300)));
  $check('m5 an over-long multi-byte note is truncated on a character boundary', !isset($longMulti['error']) && mb_strlen((string) ($longMulti['note'] ?? '')) === 255, 'chars=' . mb_strlen((string) ($longMulti['note'] ?? '')));

  $failed = array_filter($results, static fn(array $row): bool => !$row['ok']);
  foreach ($results as $row) {
    echo ($row['ok'] ? 'PASS' : 'FAIL') . '  ' . $row['name'] . ($row['ok'] || $row['detail'] === '' ? '' : '  [' . $row['detail'] . ']') . "\n";
  }
  echo "\n" . (count($results) - count($failed)) . '/' . count($results) . " assertions passed\n";
  exit($failed === [] ? 0 : 1);
}
