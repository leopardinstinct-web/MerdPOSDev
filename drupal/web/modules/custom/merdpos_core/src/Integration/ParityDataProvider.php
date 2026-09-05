<?php
declare(strict_types=1);

namespace Drupal\merdpos_core\Integration;

final class ParityDataProvider implements ParityDataProviderInterface {

  public function __construct(
    private readonly PortalGatewayClientInterface $gateway,
    private readonly WorkingNowProviderInterface $workingNow,
  ) {}

  public function home(array $query = []): array {
    $dashboardQuery = $this->dashboardQuery($query);
    $dashboard = $this->call('dashboard_data', $dashboardQuery);
    $payload = $dashboard['payload'];
    $allowedKeys = $this->strings($payload['allowed_widgets'] ?? []);
    $allowed = array_fill_keys($allowedKeys, true);
    $role = $this->map($payload['role'] ?? []);
    $management = $this->map($payload['management'] ?? []);
    $analytics = $this->map($management['analytics'] ?? []);
    $currency = (string)($management['currency_code'] ?? $payload['client_defaults']['currency_code'] ?? 'AUD');
    $businessDate = (string)($management['business_date'] ?? 'Current business date');
    $working = $this->rows($payload['working'] ?? []);
    $salesRows = $this->rows($management['sales_by_store'] ?? []);
    $cashRows = $this->rows($management['financial_by_store'] ?? []);
    $stores = $this->rows($payload['stores'] ?? []);
    $timezone = (string)($management['timezone'] ?? $payload['client_defaults']['timezone'] ?? 'Australia/Sydney');

    $kpis = [];
    if (isset($allowed['working_now_count'])) {
      $kpis[] = $this->dashboardKpi('working_now_count', 'Working now', (string)(int)($payload['working_count'] ?? 0), 'Live QR attendance', 'info', 'users');
    }
    if (isset($allowed['pending_disputes'])) {
      $kpis[] = $this->dashboardKpi('pending_disputes', 'Pending disputes', (string)(int)($payload['pending_disputes_count'] ?? 0), 'Waiting for review', 'warning', 'message');
    }
    if (isset($allowed['active_employees'])) {
      $kpis[] = $this->dashboardKpi('active_employees', 'Active employees', (string)(int)($management['active_employees'] ?? 0), 'Working client', 'success', 'users');
    }
    if (isset($allowed['sync_attention'])) {
      $kpis[] = $this->dashboardKpi('sync_attention', 'Sync attention', (string)(int)($management['sync_attention'] ?? 0), 'Pending / failed outbox', 'danger', 'sync');
    }    if (isset($allowed['my_shift'])) {
      $mine = $this->rows($payload['my_working'] ?? []);
      $shift = $mine[0] ?? NULL;
      $kpis[] = $this->dashboardKpi(
        'my_shift', 'My current shift', $shift ? 'Clocked in' : 'Off shift',
        $shift ? ((string)($shift['store_name'] ?? '') . ' · since ' . $this->localDateTime($shift['clock_in_at'] ?? '', (string)($shift['timezone'] ?? $timezone))) : 'Not clocked in',
        $shift ? 'success' : 'info', 'clock'
      );
    }
    if (isset($allowed['my_disputes'])) {
      $open = count(array_filter($this->rows($payload['disputes'] ?? []), static fn(array $row): bool => in_array((string)($row['status'] ?? ''), ['pending','awaiting_employee'], true)));
      $kpis[] = $this->dashboardKpi('my_disputes', 'My open disputes', (string)$open, 'Attendance corrections', 'warning', 'message');
    }
    if (isset($allowed['sales_change'])) {
      $kpis[] = $this->dashboardChange('sales_change', 'Sales change', $this->rows($analytics['sales_period'] ?? []), $currency, true, 'Completed sales', 'chart');
    }
    if (isset($allowed['attendance_change'])) {
      $kpis[] = $this->dashboardChange('attendance_change', 'Attendance change', $this->rows($analytics['attendance_period'] ?? []), '', false, 'Clock-ins', 'clock');
    }

    $widgets = [];
    $chartSpecs = [];
    if (isset($allowed['working_now'])) {
      $items = [];
      foreach (array_slice($working, 0, 30) as $row) {
        $items[] = [
          'title'=>(string)($row['full_name'] ?? ''),
          'meta'=>(string)($row['store_name'] ?? ''),
          'value'=>$this->localDateTime($row['clock_in_at'] ?? '', (string)($row['timezone'] ?? $timezone)),
        ];
      }
      $widgets[] = $this->dashboardWidget('working_now', 'list', 'Who is working now', 'Live employee and store attendance.', 'users', ['items'=>$items, 'empty'=>'Nobody is currently clocked in.']);
    }    if (isset($allowed['sales_trend_7d'])) {
      $series = $this->rows($analytics['sales_period'] ?? []);
      $chartSpecs[] = $this->chartSpec('sales_trend_7d', 'line', $series, 'Completed sales', '#1c4587');
      $widgets[] = $this->dashboardWidget('sales_trend_7d', 'chart', 'Sales trend', 'Completed sales across the selected period.', 'chart', ['chart_key'=>'sales_trend_7d']);
    }
    if (isset($allowed['attendance_trend_7d'])) {
      $series = $this->rows($analytics['attendance_period'] ?? []);
      $chartSpecs[] = $this->chartSpec('attendance_trend_7d', 'line', $series, 'Clock-ins', '#23a6a8');
      $widgets[] = $this->dashboardWidget('attendance_trend_7d', 'chart', 'Attendance trend', 'Clock-ins across the selected period.', 'clock', ['chart_key'=>'attendance_trend_7d']);
    }
    if (isset($allowed['today_sales_by_store'])) {
      $labels = array_map(static fn(array $row): string => (string)($row['store_name'] ?? ''), $salesRows);
      $values = array_map(static fn(array $row): float => (float)($row['today_sales'] ?? 0), $salesRows);
      $chartSpecs[] = $this->chartSpecValues('today_sales_by_store', 'column', $labels, $values, 'Sales', '#6f42c1');
      $widgets[] = $this->dashboardWidget('today_sales_by_store', 'chart', "Today's sales by store", 'Completed retail sales grouped by store.', 'store', ['chart_key'=>'today_sales_by_store']);
    }
    if (isset($allowed['workforce_by_store'])) {
      $counts = [];
      foreach ($stores as $store) $counts[(string)($store['store_name'] ?? '')] = 0;
      foreach ($working as $row) {
        $name = (string)($row['store_name'] ?? '');
        $counts[$name] = ($counts[$name] ?? 0) + 1;
      }
      $labels = array_keys($counts);
      $values = array_values($counts);
      $chartSpecs[] = $this->chartSpecValues('workforce_by_store', 'column', $labels, $values, 'People', '#00a6c7');
      $widgets[] = $this->dashboardWidget('workforce_by_store', 'chart', 'Workforce by store', 'Open shifts grouped by store.', 'users', ['chart_key'=>'workforce_by_store']);
    }    if (isset($allowed['store_cash_position'])) {
      $labels = array_map(static fn(array $row): string => (string)($row['store_name'] ?? ''), $cashRows);
      $values = array_map(static fn(array $row): float => (float)($row['register_balance'] ?? 0) + (float)($row['petty_balance'] ?? 0), $cashRows);
      $chartSpecs[] = $this->chartSpecValues('store_cash_position', 'column', $labels, $values, 'Cash position', '#1c4587');
      $widgets[] = $this->dashboardWidget('store_cash_position', 'chart', 'Store cash position', 'Register plus petty cash by store.', 'wallet', ['chart_key'=>'store_cash_position']);
    }
    if (isset($allowed['cash_mix'])) {
      $register = $this->sum($cashRows, 'register_balance');
      $petty = $this->sum($cashRows, 'petty_balance');
      $chartSpecs[] = [
        'key'=>'cash_mix', 'type'=>'donut', 'labels'=>['Register','Petty Cash'],
        'values'=>[$register,$petty], 'series_label'=>'Balance',
        'color'=>'#1c4587', 'colors'=>['#1c4587','#23a6a8'], 'height'=>280,
      ];
      $widgets[] = $this->dashboardWidget('cash_mix', 'chart', 'Register vs Petty Cash', 'Current cash mix for the working client.', 'wallet', ['chart_key'=>'cash_mix']);
    }
    if (isset($allowed['top_stores_sales'])) {
      $ranked = $salesRows;
      usort($ranked, static fn(array $a, array $b): int => ((float)($b['today_sales'] ?? 0) <=> (float)($a['today_sales'] ?? 0)) ?: ((int)($a['store_id'] ?? 0) <=> (int)($b['store_id'] ?? 0)));
      $items = [];
      foreach (array_slice($ranked, 0, 5) as $index => $row) {
        $items[] = [
          'rank'=>$index + 1,
          'title'=>(string)($row['store_name'] ?? ''),
          'value'=>$this->money($row['today_sales'] ?? 0, (string)($row['currency_code'] ?? $currency)),
        ];
      }
      $widgets[] = $this->dashboardWidget('top_stores_sales', 'ranking', 'Top stores by sales', "Stores ranked by today's completed sales.", 'trophy', ['items'=>$items]);
    }    if (isset($allowed['recent_attendance'])) {
      $rows = [];
      foreach (array_slice($this->rows($payload['recent_shifts'] ?? []), 0, 30) as $row) {
        $rowTz = (string)($row['timezone'] ?? $timezone);
        $rows[] = [
          'employee'=>(string)($row['full_name'] ?? ''),
          'store'=>(string)($row['store_name'] ?? ''),
          'in'=>$this->localDateTime($row['clock_in_at'] ?? '', $rowTz),
          'out'=>$this->localDateTime($row['clock_out_at'] ?? '', $rowTz),
        ];
      }
      $widgets[] = $this->dashboardWidget('recent_attendance', 'table', 'Recent attendance', 'Latest attendance visible to this role.', 'clock', [
        'columns'=>[['key'=>'employee','label'=>'Employee'],['key'=>'store','label'=>'Store'],['key'=>'in','label'=>'In'],['key'=>'out','label'=>'Out']],
        'rows'=>$rows, 'empty'=>'No recent attendance.',
      ]);
    }
    if (isset($allowed['sync_status_table'])) {
      $statusRows = [];
      foreach ($this->rows($analytics['sync_statuses'] ?? []) as $row) {
        $statusRows[] = [
          'status'=>ucfirst((string)($row['status'] ?? '')),
          'count'=>(string)(int)($row['count'] ?? 0),
          'tone'=>match((string)($row['status'] ?? '')) {'failed'=>'danger','processing'=>'warning',default=>'info'},
        ];
      }
      $widgets[] = $this->dashboardWidget('sync_status_table', 'status', 'Sync status', 'Outbox exceptions grouped by current status.', 'sync', ['items'=>$statusRows]);
    }

    $filterOptions = $this->map($payload['filter_options'] ?? []);
    $filterState = $this->map($payload['filters'] ?? []);
    $filters = [];
    $filterable = array_intersect($allowedKeys, ['working_now_count','working_now','workforce_by_store','store_cash_position','cash_mix','today_sales_by_store','recent_attendance','sales_change','attendance_change','sales_trend_7d','attendance_trend_7d','top_stores_sales']);
    if ($filterable) {
      $storeOptions = [['value'=>'0','label'=>'All stores']];
      foreach ($this->rows($filterOptions['stores'] ?? []) as $store) {
        $storeOptions[] = ['value'=>(string)($store['id'] ?? ''),'label'=>(string)($store['store_name'] ?? '')];
      }
      $filters[] = ['name'=>'store_id','label'=>'Store','type'=>'select','value'=>(string)($filterState['store_id'] ?? 0),'options'=>$storeOptions];
    }    if (array_intersect($allowedKeys, ['sales_trend_7d','attendance_trend_7d'])) {
      $filters[] = [
        'name'=>'period', 'label'=>'Period', 'type'=>'select',
        'value'=>(string)($filterState['period'] ?? '7'),
        'options'=>[
          ['value'=>'current_week','label'=>'Current week'],
          ['value'=>'7','label'=>'7 days'],
          ['value'=>'14','label'=>'14 days'],
          ['value'=>'30','label'=>'30 days'],
        ],
      ];
    }

    $surface = $this->surface(
      'home', 'Home', 'Management workspace',
      'A role-aware operational command centre using only widgets authorized by MERDPOS LOA policy.',
      $dashboard['status'], $kpis, [],
      ['source'=>'dashboard_data', 'business_date'=>$businessDate, 'currency_code'=>$currency],
      $filters,
    );
    $surface['role'] = [
      'key'=>(string)($role['role_key'] ?? $role['base_role'] ?? 'USER'),
      'label'=>(string)($role['role_label'] ?? $role['base_role'] ?? 'MERDPOS'),
      'loa'=>(int)($role['authority_level'] ?? 0),
    ];
    $surface['allowed_widgets'] = $allowedKeys;
    $surface['dashboard_widgets'] = $widgets;
    $surface['chart_specs'] = $chartSpecs;
    $surface['visible_widget_count'] = count($kpis) + count($widgets);
    $surface['period_label'] = (string)($filterState['period_label'] ?? 'Current period');
    return $surface;
  }

  public function section(string $section, array $query = []): array {
    return match ($section) {
      'operations' => $this->operations($query),
      'reports' => $this->reports($query),
      'finance' => $this->finance($query),
      'dev' => $this->dev(),
      default => $this->unavailableSurface($section),
    };
  }

  private function operations(array $query = []): array {
    $state = $this->call('beta_state');
    $dashboard = $this->call('dashboard_data', $this->dashboardQuery($query));
    $disputes = $this->call('disputes');
    $weeks = $this->call('weeks');
    $statePayload = $this->map($state['payload'] ?? []);
    $dashboardPayload = $this->map($dashboard['payload'] ?? []);
    $disputeRows = $this->rows($disputes['payload']['disputes'] ?? $statePayload['disputes'] ?? []);
    $weekRows = $this->rows($weeks['payload']['weeks'] ?? []);
    $currentWeek = (string) ($weeks['payload']['current_week'] ?? '');
    $timesheet = $currentWeek !== '' ? $this->call('timesheet', ['week_start' => $currentWeek]) : $this->emptyCall('unavailable');
    $report = $this->map($timesheet['payload']['report'] ?? []);

    // These management APIs remain optional. A forbidden response is treated as
    // an authoritative instruction to omit that privileged slice, not as a page failure.
    $directory = $this->call('admin_directory');
    $identity = $this->call('store_identity');
    $timings = $this->call('store_timings');
    $directoryAllowed = ($directory['status'] ?? '') === 'ok';
    $identityAllowed = ($identity['status'] ?? '') === 'ok';
    $timingsAllowed = ($timings['status'] ?? '') === 'ok';

    $role = [
      'key' => (string) ($statePayload['role_key'] ?? $dashboardPayload['role']['role_key'] ?? 'USER'),
      'label' => (string) ($statePayload['role_label'] ?? $dashboardPayload['role']['role_label'] ?? 'MERDPOS'),
      'loa' => (int) ($statePayload['authority_level'] ?? $dashboardPayload['role']['authority_level'] ?? 0),
    ];
    $permissions = $this->strings($statePayload['permissions'] ?? []);
    $canViewWorkforce = in_array('workforce.view', $permissions, true);
    $canReviewDisputes = in_array('disputes.review', $permissions, true);
    $canResolveFlags = in_array('attendance_flags.resolve', $permissions, true);
    $timezone = (string) ($dashboardPayload['client_defaults']['timezone'] ?? $statePayload['client_defaults']['timezone'] ?? 'Australia/Sydney');
    $filterState = $this->map($dashboardPayload['filters'] ?? []);
    $filterOptions = $this->map($dashboardPayload['filter_options'] ?? []);
    $selectedStoreId = (int) ($filterState['store_id'] ?? 0);
    $selectedStoreName = '';
    foreach ($this->rows($filterOptions['stores'] ?? []) as $store) {
      if ((int) ($store['id'] ?? 0) === $selectedStoreId) {
        $selectedStoreName = (string) ($store['store_name'] ?? '');
        break;
      }
    }

    $working = $this->rows($dashboardPayload['working'] ?? []);
    if (!$working) {
      $working = $this->rows($statePayload['working'] ?? []);
      if ($selectedStoreId > 0) {
        $working = array_values(array_filter($working, static fn(array $row): bool => (int) ($row['store_id'] ?? 0) === $selectedStoreId));
      }
    }
    $workingPeople = [];
    foreach (array_slice($working, 0, 40) as $row) {
      $minutes = max(0, (int) ($row['working_minutes'] ?? 0));
      $duration = intdiv($minutes, 60) . 'h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm';
      $workingPeople[] = [
        'name' => (string) ($row['full_name'] ?? ''),
        'store' => (string) ($row['store_name'] ?? ''),
        'clock_in' => $this->localDateTime($row['clock_in_at'] ?? '', $timezone),
        'duration' => $duration,
      ];
    }

    $pendingDisputes = array_values(array_filter($disputeRows, static fn(array $row): bool => in_array(strtolower((string) ($row['status'] ?? '')), ['pending', 'awaiting_employee'], true)));
    $disputeItems = [];
    foreach (array_slice($pendingDisputes, 0, 12) as $row) {
      $disputeItems[] = [
        'employee' => (string) ($row['full_name'] ?? ''),
        'store' => (string) ($row['store_name'] ?? ''),
        'type' => ucwords(str_replace('_', ' ', (string) ($row['dispute_type'] ?? 'other'))),
        'status' => strtoupper((string) ($row['status'] ?? '')),
        'submitted' => (string) ($row['submitted_at'] ?? ''),
        'reason' => trim((string) ($row['reason'] ?? '')),
      ];
    }

    $flagRows = $this->rows($statePayload['attendance_flags'] ?? []);
    $openFlags = array_values(array_filter($flagRows, static fn(array $row): bool => strtolower((string) ($row['status'] ?? '')) === 'open'));
    $flagItems = [];
    foreach (array_slice($openFlags, 0, 12) as $row) {
      $flagItems[] = [
        'employee' => (string) ($row['full_name'] ?? ''),
        'store' => (string) ($row['attempted_store'] ?? ''),
        'reason' => ucwords(str_replace('_', ' ', (string) ($row['reason'] ?? ''))),
        'created' => (string) ($row['created_at'] ?? ''),
      ];
    }

    $lateRows = [];
    $lateByStore = [];
    foreach ($this->rows($report['employees'] ?? []) as $employee) {
      foreach ($this->rows($employee['rows'] ?? []) as $row) {
        if (empty($row['is_late'])) continue;
        $storeName = (string) ($row['store_name'] ?? '');
        if ($selectedStoreName !== '' && $storeName !== $selectedStoreName) continue;
        $lateRows[] = [
          'employee' => (string) ($employee['employee_name'] ?? ''),
          'store' => $storeName,
          'date' => (string) ($row['in_date'] ?? ''),
          'actual' => $this->clock((string) ($row['actual_in_time'] ?? '')),
          'scheduled' => $this->clock((string) ($row['scheduled_start_time'] ?? '')),
        ];
        $lateByStore[$storeName] = ($lateByStore[$storeName] ?? 0) + 1;
      }
    }
    $lateRows = array_slice($lateRows, 0, 30);

    $recentRows = [];
    foreach (array_slice($this->rows($dashboardPayload['recent_shifts'] ?? $statePayload['recent_shifts'] ?? []), 0, 40) as $row) {
      $rowTimezone = (string) ($row['timezone'] ?? $timezone);
      $recentRows[] = [
        'employee' => (string) ($row['full_name'] ?? ''),
        'store' => (string) ($row['store_name'] ?? ''),
        'in' => $this->localDateTime($row['clock_in_at'] ?? '', $rowTimezone),
        'out' => $this->localDateTime($row['clock_out_at'] ?? '', $rowTimezone),
        'status' => strtoupper((string) ($row['status'] ?? '')),
      ];
    }

    $employees = [];
    if ($directoryAllowed) {
      foreach (array_slice($this->rows($directory['payload']['employees'] ?? []), 0, 160) as $row) {
        $employees[] = [
          'name' => (string) ($row['full_name'] ?? ''),
          'role' => (string) ($row['role_label'] ?? $row['role_name'] ?? $row['employee_type'] ?? ''),
          'store' => (string) ($row['store_name'] ?? '-'),
          'status' => strtoupper((string) ($row['status'] ?? '')),
        ];
      }
    }

    $stores = [];
    if ($identityAllowed) {
      foreach ($this->rows($identity['payload']['stores'] ?? []) as $row) {
        $stores[] = [
          'id' => (int) ($row['id'] ?? 0),
          'name' => (string) ($row['store_name'] ?? ''),
          'code' => (string) ($row['store_code'] ?? ''),
          'status' => strtoupper((string) ($row['status'] ?? '')),
          'timezone' => (string) ($row['timezone'] ?? ''),
        ];
      }
    }

    $storeNameById = [];
    foreach ($stores as $store) $storeNameById[(int) $store['id']] = (string) $store['name'];
    $timingRows = [];
    if ($timingsAllowed) {
      foreach ($this->rows($timings['payload']['timings'] ?? []) as $row) {
        $timingRows[] = [
          'store' => $storeNameById[(int) ($row['store_id'] ?? 0)] ?? ('Store ' . (string) ($row['store_id'] ?? '')),
          'day' => $this->dayName((int) ($row['day_of_week'] ?? 0)),
          'hours' => (int) ($row['is_closed'] ?? 0) === 1
            ? 'Closed'
            : $this->clock((string) ($row['start_time'] ?? '')) . ' - ' . $this->clock((string) ($row['end_time'] ?? '')),
        ];
      }
    }

    $staffing = [];
    foreach ($working as $row) {
      $name = (string) ($row['store_name'] ?? '');
      if ($name === '') continue;
      $staffing[$name] = ($staffing[$name] ?? 0) + 1;
    }
    if ($canViewWorkforce) {
      foreach ($this->rows($filterOptions['stores'] ?? []) as $store) {
        $name = (string) ($store['store_name'] ?? '');
        if ($name !== '' && !isset($staffing[$name])) $staffing[$name] = 0;
      }
    }
    ksort($staffing);

    $chartSpecs = [];
    if ($staffing) {
      $chartSpecs[] = $this->chartSpecValues(
        'operations_workforce_by_store', 'column', array_keys($staffing), array_values($staffing), 'Working now', '#1c4587'
      );
    }
    $analytics = $this->map($dashboardPayload['management']['analytics'] ?? []);
    $attendanceSeries = $this->rows($analytics['attendance_period'] ?? []);
    if ($attendanceSeries) {
      $chartSpecs[] = $this->chartSpec('operations_attendance_trend', 'line', $attendanceSeries, 'Clock-ins', '#23a6a8');
    }
    if ($lateByStore) {
      ksort($lateByStore);
      $chartSpecs[] = $this->chartSpecValues(
        'operations_late_by_store', 'column', array_keys($lateByStore), array_values($lateByStore), 'Late starts', '#d97706'
      );
    }

    $metrics = [
      $this->metric('Working now', (string) count($workingPeople), $canViewWorkforce ? 'Open shifts in selected scope' : 'Your current open shift', 'info'),
      $this->metric('Pending disputes', (string) count($pendingDisputes), $canReviewDisputes ? 'Awaiting management review' : 'Your open requests', 'warning'),
      $this->metric('Late starts', (string) count($lateRows), (string) ($report['week_label'] ?? $currentWeek ?: 'Current week'), count($lateRows) > 0 ? 'warning' : 'success'),
    ];
    if ($canResolveFlags || $flagRows) {
      $metrics[] = $this->metric('Attendance flags', (string) count($openFlags), 'Open security exceptions', count($openFlags) > 0 ? 'danger' : 'success');
    }
    else {
      $metrics[] = $this->metric('Recent shifts', (string) count($recentRows), 'Permission-scoped attendance history', 'success');
    }
    $activeEmployees = $dashboardPayload['management']['active_employees'] ?? $statePayload['management']['active_employees'] ?? NULL;
    if ($canViewWorkforce && $activeEmployees !== NULL) {
      $metrics[] = $this->metric('Active employees', (string) (int) $activeEmployees, 'Current client workforce', 'brand');
    }

    $filters = [];
    $storeOptions = [['value' => '0', 'label' => 'All permitted stores']];
    foreach ($this->rows($filterOptions['stores'] ?? []) as $store) {
      $storeOptions[] = ['value' => (string) ($store['id'] ?? ''), 'label' => (string) ($store['store_name'] ?? '')];
    }
    if (count($storeOptions) > 1) {
      $filters[] = ['name' => 'store_id', 'label' => 'Store', 'type' => 'select', 'value' => (string) $selectedStoreId, 'options' => $storeOptions];
    }
    $filters[] = [
      'name' => 'period', 'label' => 'Attendance period', 'type' => 'select',
      'value' => (string) ($filterState['period'] ?? '7'),
      'options' => [
        ['value' => 'current_week', 'label' => 'Current week'],
        ['value' => '7', 'label' => '7 days'],
        ['value' => '14', 'label' => '14 days'],
        ['value' => '30', 'label' => '30 days'],
      ],
    ];

    $surface = $this->surface(
      'operations', 'Operations', 'Operations & HR command centre',
      'Role-aware workforce, attendance, disputes and store operations from authoritative MERDPOS Beta services.',
      $this->status([$state['status'], $dashboard['status'], $disputes['status']]),
      $metrics, [],
      [
        'source' => 'beta_state + dashboard_data + disputes + authoritative timesheet + permission-scoped management APIs',
        'week' => (string) ($report['week_label'] ?? $currentWeek),
      ],
      $filters,
    );
    $surface['role'] = $role;
    $surface['permissions'] = $permissions;
    $surface['working_people'] = $workingPeople;
    $surface['staffing'] = $staffing;
    $surface['pending_disputes'] = $disputeItems;
    $surface['pending_dispute_count'] = count($pendingDisputes);
    $surface['attendance_flags'] = $flagItems;
    $surface['late_arrivals'] = $lateRows;
    $surface['recent_attendance'] = $recentRows;
    $surface['employees'] = $employees;
    $surface['stores'] = $stores;
    $surface['store_timings'] = $timingRows;
    $surface['directory_available'] = $directoryAllowed;
    $surface['store_admin_available'] = $identityAllowed && $timingsAllowed;
    $surface['chart_specs'] = $chartSpecs;
    $surface['week_label'] = (string) ($report['week_label'] ?? $currentWeek ?: 'Current week');
    $surface['period_label'] = (string) ($filterState['period_label'] ?? '7 days');
    return $surface;
  }

  private function reports(array $query): array {
    $dashboard = $this->call('dashboard_data');
    $weeks = $this->call('weeks');
    $weekRows = $this->rows($weeks['payload']['weeks'] ?? []);
    $currentWeek = (string)($weeks['payload']['current_week'] ?? '');
    $allowedWeeks = array_values(array_filter(array_map(static fn(array $r): string => (string)($r['value'] ?? ''), $weekRows)));
    $requestedWeek = trim((string)($query['week_start'] ?? ''));
    $selectedWeek = in_array($requestedWeek, $allowedWeeks, true) ? $requestedWeek : $currentWeek;
    $timesheet = $selectedWeek !== '' ? $this->call('timesheet', ['week_start'=>$selectedWeek]) : $this->emptyCall('unavailable');
    $disputes = $this->call('disputes');
    $report = $this->map($timesheet['payload']['report'] ?? []);
    $disputeRows = $this->rows($disputes['payload']['disputes'] ?? []);
    $payrollVisible = (bool)($report['payroll_visible'] ?? false);
    $currency = (string)($dashboard['payload']['client_defaults']['currency_code'] ?? 'AUD');
    $role = $this->map($dashboard['payload']['role'] ?? []);
    $roleKey = strtoupper((string)($role['role_key'] ?? $role['base_role'] ?? 'USER'));
    $roleLabel = (string)($role['role_label'] ?? $roleKey);
    $loa = (int)($role['authority_level'] ?? 0);

    $allShifts = [];
    foreach ($this->rows($report['employees'] ?? []) as $employee) {
      $employeeName = (string)($employee['employee_name'] ?? '');
      foreach ($this->rows($employee['rows'] ?? []) as $row) {
        $item = [
          'employee'=>$employeeName,
          'store'=>(string)($row['store_name'] ?? ''),
          'date'=>(string)($row['in_date'] ?? ''),
          'in'=>$this->clock((string)($row['actual_in_time'] ?? '')),
          'out'=>$this->clock((string)($row['actual_out_time'] ?? '')),
          'hours'=>(float)($row['total_hours'] ?? 0),
          'late'=>!empty($row['is_late']),
          'start'=>!empty($row['is_late']) ? 'Late' : 'On time',
        ];
        if ($payrollVisible && array_key_exists('wage', $row)) $item['wage'] = (float)$row['wage'];
        $allShifts[] = $item;
      }
    }

    $storeNames = array_values(array_unique(array_filter(array_map(static fn(array $r): string => (string)($r['store'] ?? ''), $allShifts))));
    $employeeNames = array_values(array_unique(array_filter(array_map(static fn(array $r): string => (string)($r['employee'] ?? ''), $allShifts))));
    sort($storeNames, SORT_NATURAL | SORT_FLAG_CASE);
    sort($employeeNames, SORT_NATURAL | SORT_FLAG_CASE);
    $requestedStore = trim((string)($query['store'] ?? ''));
    $requestedEmployee = trim((string)($query['employee'] ?? ''));
    $requestedAttendance = strtolower(trim((string)($query['attendance'] ?? 'all')));
    $selectedStore = in_array($requestedStore, $storeNames, true) ? $requestedStore : '';
    $selectedEmployee = in_array($requestedEmployee, $employeeNames, true) ? $requestedEmployee : '';
    $selectedAttendance = in_array($requestedAttendance, ['all','late','on_time'], true) ? $requestedAttendance : 'all';

    $filteredShifts = array_values(array_filter($allShifts, static function(array $row) use ($selectedStore,$selectedEmployee,$selectedAttendance): bool {
      if ($selectedStore !== '' && (string)$row['store'] !== $selectedStore) return false;
      if ($selectedEmployee !== '' && (string)$row['employee'] !== $selectedEmployee) return false;
      if ($selectedAttendance === 'late' && empty($row['late'])) return false;
      if ($selectedAttendance === 'on_time' && !empty($row['late'])) return false;
      return true;
    }));

    $filteredDisputes = array_values(array_filter($disputeRows, static function(array $row) use ($selectedStore,$selectedEmployee): bool {
      if ($selectedStore !== '' && (string)($row['store_name'] ?? '') !== $selectedStore) return false;
      if ($selectedEmployee !== '' && (string)($row['full_name'] ?? '') !== $selectedEmployee) return false;
      return true;
    }));

    $storeAgg = [];
    $employeeAgg = [];
    $lateCount = 0;
    $filteredHours = 0.0;
    $filteredWages = 0.0;
    foreach ($filteredShifts as $row) {
      $store = (string)$row['store'];
      $employee = (string)$row['employee'];
      $hours = (float)$row['hours'];
      $wage = $payrollVisible ? (float)($row['wage'] ?? 0) : 0.0;
      $filteredHours += $hours;
      $filteredWages += $wage;
      if (!empty($row['late'])) $lateCount++;
      if (!isset($storeAgg[$store])) $storeAgg[$store] = ['hours'=>0.0,'wage'=>0.0,'employees'=>[]];
      $storeAgg[$store]['hours'] += $hours;
      $storeAgg[$store]['wage'] += $wage;
      $storeAgg[$store]['employees'][$employee] = true;
      if (!isset($employeeAgg[$employee])) $employeeAgg[$employee] = ['hours'=>0.0,'wage'=>0.0,'stores'=>[]];
      $employeeAgg[$employee]['hours'] += $hours;
      $employeeAgg[$employee]['wage'] += $wage;
      $employeeAgg[$employee]['stores'][$store] = true;
    }

    $pendingDisputes = count(array_filter($filteredDisputes, static fn(array $r): bool => strtolower((string)($r['status'] ?? '')) === 'pending'));
    $openDisputes = count(array_filter($filteredDisputes, static fn(array $r): bool => in_array(strtolower((string)($r['status'] ?? '')), ['pending','awaiting_employee'], true)));
    $onTimeCount = max(0, count($filteredShifts) - $lateCount);

    $storeTable = [];
    foreach ($storeAgg as $store=>$totals) {
      $item = [
        'store'=>$store,
        'employees'=>(string)count($totals['employees']),
        'hours'=>$this->number($totals['hours']),
      ];
      if ($payrollVisible) $item['amount'] = $this->money($totals['wage'], $currency);
      $storeTable[] = $item;
    }
    usort($storeTable, static fn(array $a,array $b): int => strcasecmp((string)$a['store'], (string)$b['store']));

    $employeeTable = [];
    foreach ($employeeAgg as $employee=>$totals) {
      $item = [
        'employee'=>$employee,
        'stores'=>implode(', ', array_keys($totals['stores'])),
        'hours'=>$this->number($totals['hours']),
      ];
      if ($payrollVisible) $item['wage'] = $this->money($totals['wage'], $currency);
      $employeeTable[] = $item;
    }
    usort($employeeTable, static fn(array $a,array $b): int => ((float)$b['hours'] <=> (float)$a['hours']) ?: strcasecmp((string)$a['employee'], (string)$b['employee']));

    $shiftTable = [];
    foreach (array_slice($filteredShifts, 0, 250) as $row) {
      $item = [
        'employee'=>$row['employee'],'store'=>$row['store'],'date'=>$row['date'],'in'=>$row['in'],'out'=>$row['out'],
        'hours'=>$this->number($row['hours']),'start'=>$row['start'],
      ];
      if ($payrollVisible) $item['wage'] = $this->money($row['wage'] ?? 0, $currency);
      $shiftTable[] = $item;
    }

    $disputeTable = [];
    $disputeCounts = [];
    foreach (array_slice($filteredDisputes, 0, 150) as $row) {
      $status = strtolower((string)($row['status'] ?? 'unknown'));
      $disputeCounts[$status] = ($disputeCounts[$status] ?? 0) + 1;
      $requested = trim(implode(' → ', array_filter([
        (string)($row['requested_clock_in_at'] ?? ''),
        (string)($row['requested_clock_out_at'] ?? ''),
      ])));
      $disputeTable[] = [
        'employee'=>(string)($row['full_name'] ?? ''),
        'store'=>(string)($row['store_name'] ?? ''),
        'type'=>ucwords(str_replace('_',' ',(string)($row['dispute_type'] ?? ''))),
        'requested'=>$requested !== '' ? $requested : '-',
        'reason'=>(string)($row['reason'] ?? ''),
        'status'=>strtoupper($status),
        'submitted'=>(string)($row['submitted_at'] ?? ''),
      ];
    }

    $chartSpecs = [];
    if ($storeAgg) {
      $labels = array_keys($storeAgg);
      $values = array_map(static fn(array $v): float => round((float)$v['hours'],2), array_values($storeAgg));
      $chartSpecs[] = $this->chartSpecValues('reports_store_hours','column',$labels,$values,'Hours','#1c4587');
    }
    if ($employeeAgg) {
      $ranked = $employeeAgg;
      uasort($ranked, static fn(array $a,array $b): int => ((float)$b['hours'] <=> (float)$a['hours']));
      $names = array_slice(array_keys($ranked),0,12);
      $values = array_map(static fn(string $name): float => round((float)$ranked[$name]['hours'],2), $names);
      $chartSpecs[] = $this->chartSpecValues('reports_employee_hours','column',$names,$values,'Hours','#23a6a8');
    }

    if ($filteredShifts) {
      $chartSpecs[] = [
        'key'=>'reports_punctuality','type'=>'donut','labels'=>['On time','Late'],
        'values'=>[$onTimeCount,$lateCount],'series_label'=>'Shifts','color'=>'#1c4587',
        'colors'=>['#23a6a8','#e09b2d'],'height'=>280,
      ];
    }
    if ($disputeCounts) {
      $labels = array_map(static fn(string $s): string => ucwords(str_replace('_',' ',$s)), array_keys($disputeCounts));
      $chartSpecs[] = [
        'key'=>'reports_disputes','type'=>'donut','labels'=>$labels,'values'=>array_values($disputeCounts),
        'series_label'=>'Disputes','color'=>'#1c4587','colors'=>['#e09b2d','#23a6a8','#c94b5b','#6f42c1','#1c4587'],'height'=>280,
      ];
    }
    if ($payrollVisible && $storeAgg) {
      $labels = array_keys($storeAgg);
      $values = array_map(static fn(array $v): float => round((float)$v['wage'],2), array_values($storeAgg));
      $chartSpecs[] = $this->chartSpecValues('reports_payroll_store','column',$labels,$values,'Payroll','#6f42c1');
    }

    $storeColumns = [['key'=>'store','label'=>'Store'],['key'=>'employees','label'=>'Employees'],['key'=>'hours','label'=>'Hours']];
    $employeeColumns = [['key'=>'employee','label'=>'Employee'],['key'=>'stores','label'=>'Store(s)'],['key'=>'hours','label'=>'Hours']];
    $shiftColumns = [['key'=>'employee','label'=>'Employee'],['key'=>'store','label'=>'Store'],['key'=>'date','label'=>'Date'],['key'=>'in','label'=>'IN'],['key'=>'out','label'=>'OUT'],['key'=>'hours','label'=>'Hours'],['key'=>'start','label'=>'Start']];
    if ($payrollVisible) {
      $storeColumns[] = ['key'=>'amount','label'=>'Payroll'];
      $employeeColumns[] = ['key'=>'wage','label'=>'Wage'];
      $shiftColumns[] = ['key'=>'wage','label'=>'Wage'];
    }

    $metrics = [
      $this->metric('Week',(string)($report['week_label'] ?? $selectedWeek ?: '-'),'Selected payroll week','brand'),
      $this->metric('Total hours',$this->number($filteredHours),'Filtered authoritative shift hours','info'),
      $this->metric('Shifts',(string)count($filteredShifts),'Returned shift rows','success'),
      $this->metric('Late starts',(string)$lateCount,'Existing MERDPOS >10 minute rule','warning'),
      $this->metric('Pending disputes',(string)$pendingDisputes,'Awaiting review','warning'),
      $this->metric('Employees',(string)count($employeeAgg),'Employees in current view','success'),
    ];
    if ($payrollVisible) $metrics[] = $this->metric('Payroll',$this->money($filteredWages,$currency),'Visible by authoritative permission','brand');

    $filters = [
      ['name'=>'week_start','label'=>'Week','type'=>'select','value'=>$selectedWeek,'options'=>array_map(static fn(array $row): array => ['value'=>(string)($row['value'] ?? ''),'label'=>(string)($row['label'] ?? $row['value'] ?? '')],$weekRows)],
      ['name'=>'store','label'=>'Store','type'=>'select','value'=>$selectedStore,'options'=>array_merge([['value'=>'','label'=>'All permitted stores']],array_map(static fn(string $name): array => ['value'=>$name,'label'=>$name],$storeNames))],
      ['name'=>'employee','label'=>'Employee','type'=>'select','value'=>$selectedEmployee,'options'=>array_merge([['value'=>'','label'=>'All permitted employees']],array_map(static fn(string $name): array => ['value'=>$name,'label'=>$name],$employeeNames))],
      ['name'=>'attendance','label'=>'Attendance','type'=>'select','value'=>$selectedAttendance,'options'=>[
        ['value'=>'all','label'=>'All shifts'],['value'=>'late','label'=>'Late starts'],['value'=>'on_time','label'=>'On time'],
      ]],
    ];

    $surface = $this->surface(
      'reports','Reports','Timesheets, attendance & disputes',
      'Role-aware reporting over the existing MERDPOS reconciliation engine. Drupal filters and visualizes returned data without recalculating payroll rules.',
      $this->status([$dashboard['status'],$weeks['status'],$timesheet['status'],$disputes['status']]),
      $metrics,
      [
        $this->table('Store summary','Hours by store',$storeColumns,$storeTable),
        $this->table('Employee summary',$payrollVisible ? 'Hours and wages' : 'Hours',$employeeColumns,$employeeTable),
        $this->table('Shift detail','Filtered shifts',$shiftColumns,$shiftTable),
        $this->table('Disputes','Current dispute queue',[
          ['key'=>'employee','label'=>'Employee'],['key'=>'store','label'=>'Store'],['key'=>'type','label'=>'Issue'],['key'=>'requested','label'=>'Requested change'],['key'=>'reason','label'=>'Reason'],['key'=>'status','label'=>'Status'],['key'=>'submitted','label'=>'Submitted'],
        ],$disputeTable),
      ],
      ['source'=>'dashboard_data + weeks + authoritative timesheet + disputes','payroll_visible'=>$payrollVisible ? 'yes' : 'no','scope'=>(string)($report['scope'] ?? '')],
      $filters,
    );

    $surface['role'] = ['key'=>$roleKey,'label'=>$roleLabel,'loa'=>$loa];
    $surface['payroll_visible'] = $payrollVisible;
    $surface['chart_specs'] = $chartSpecs;
    $surface['pending_disputes'] = $pendingDisputes;
    $surface['open_disputes'] = $openDisputes;
    $surface['selected_week'] = $selectedWeek;
    $surface['selected_store'] = $selectedStore;
    $surface['selected_employee'] = $selectedEmployee;
    $surface['selected_attendance'] = $selectedAttendance;
    $surface['export_rows'] = $shiftTable;
    $surface['export_columns'] = $shiftColumns;
    $surface['filter_summary'] = implode(' · ', array_filter([
      $selectedStore !== '' ? $selectedStore : 'All permitted stores',
      $selectedEmployee !== '' ? $selectedEmployee : 'All permitted employees',
      $selectedAttendance === 'all' ? 'All shifts' : ($selectedAttendance === 'late' ? 'Late starts' : 'On time'),
    ]));
    return $surface;
  }

  private function finance(array $query): array {
    $dashboard = $this->call('dashboard_data', ['period'=>'7']);
    $identity = $this->call('store_identity');
    $payload = $this->map($dashboard['payload'] ?? []);
    $roleRow = $this->map($payload['role'] ?? []);
    $management = $this->map($payload['management'] ?? []);
    $analytics = $this->map($management['analytics'] ?? []);
    $stores = $this->rows($identity['payload']['stores'] ?? $payload['filter_options']['stores'] ?? []);
    $currency = strtoupper((string)($management['currency_code'] ?? $payload['client_defaults']['currency_code'] ?? 'AUD'));
    $timezone = (string)($management['timezone'] ?? $payload['client_defaults']['timezone'] ?? 'Australia/Sydney');
    $businessDate = trim((string)($query['business_date'] ?? $management['business_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) $businessDate = (string)($management['business_date'] ?? '');
    $storeIds = array_values(array_filter(array_map(static fn(array $r): int => (int)($r['id'] ?? 0), $stores)));
    $requestedStore = (int)($query['store_id'] ?? 0);
    $selectedStore = in_array($requestedStore, $storeIds, true) ? $requestedStore : ($storeIds[0] ?? 0);
    $financial = $selectedStore > 0 && $businessDate !== ''
      ? $this->call('financials', ['store_id'=>(string)$selectedStore, 'business_date'=>$businessDate])
      : $this->emptyCall('unavailable');
    $statement = $this->map($financial['payload']['statement'] ?? []);
    $salesRows = $this->rows($management['sales_by_store'] ?? []);
    $cashRows = $this->rows($management['financial_by_store'] ?? []);
    $salesByStore = [];
    foreach ($salesRows as $row) $salesByStore[(int)($row['store_id'] ?? 0)] = (float)($row['today_sales'] ?? 0);
    $totalSales = $this->sum($salesRows, 'today_sales');
    $register = $this->sum($cashRows, 'register_balance');
    $petty = $this->sum($cashRows, 'petty_balance');

    $storeTable = [];
    $salesLabels = $salesValues = $registerValues = $pettyValues = [];
    foreach ($cashRows as $row) {
      $id = (int)($row['store_id'] ?? 0);
      $name = (string)($row['store_name'] ?? 'Store');
      $rowCurrency = (string)($row['currency_code'] ?? $currency);
      $sales = (float)($salesByStore[$id] ?? 0);
      $reg = (float)($row['register_balance'] ?? 0);
      $pet = (float)($row['petty_balance'] ?? 0);
      $salesLabels[] = $name; $salesValues[] = $sales; $registerValues[] = $reg; $pettyValues[] = $pet;
      $storeTable[] = ['store'=>$name,'sales'=>$this->money($sales,$rowCurrency),'register'=>$this->money($reg,$rowCurrency),'petty'=>$this->money($pet,$rowCurrency)];
    }

    $accountCards = [];
    $accountTable = [];
    $exceptions = [];
    foreach ($this->rows($statement['accounts'] ?? []) as $row) {
      $status = strtolower((string)($row['status'] ?? ''));
      $closing = ($row['closing'] ?? null) === null ? '-' : $this->money($row['closing'], $currency);
      $item = [
        'account'=>(string)($row['account'] ?? ''),'opening'=>$this->money($row['opening'] ?? 0,$currency),
        'in'=>$this->money($row['cash_in'] ?? 0,$currency),'out'=>$this->money($row['cash_out'] ?? 0,$currency),
        'available'=>$this->money($row['available'] ?? 0,$currency),'closing'=>$closing,'status'=>strtoupper($status ?: 'unknown'),
      ];
      $accountTable[] = $item;
      $accountCards[] = ['title'=>$item['account'],'status'=>$item['status'],'available'=>$item['available'],'opening'=>$item['opening'],'in'=>$item['in'],'out'=>$item['out'],'closing'=>$item['closing']];
      if ($status !== '' && !in_array($status,['open','closed'],true)) $exceptions[] = $item['account'] . ' returned status ' . strtoupper($status) . '.';
    }
    $dayStatus = strtolower((string)($statement['day_status'] ?? 'not_open'));
    if ($dayStatus === 'not_open') $exceptions[] = 'The selected financial day has not been opened.';
    if (!$accountCards && $financial['status'] === 'ok') $exceptions[] = 'No Register or Petty Cash day accounts were returned.';

    $entryTable = [];
    foreach (array_slice($this->rows($statement['entries'] ?? []), 0, 80) as $row) {
      $entryTable[] = [
        'account'=>(string)($row['account'] ?? ''),'type'=>strtoupper((string)($row['entry_type'] ?? '')),
        'head'=>(string)($row['head'] ?? ''),'amount'=>$this->money($row['amount'] ?? 0,$currency),
        'by'=>(string)($row['full_name'] ?? ''),'at'=>$this->localDateTime($row['created_at'] ?? '', (string)($statement['timezone'] ?? $timezone)),
      ];
    }

    $chartSpecs = [];
    if ($salesLabels) {
      $chartSpecs[] = $this->chartSpecValues('finance_sales_by_store','column',$salesLabels,$salesValues,'Sales',$currency === 'AUD' ? '#1c4587' : '#1c4587');
      $chartSpecs[] = $this->chartSpecValues('finance_register_by_store','column',$salesLabels,$registerValues,'Register','#23a6a8');
      $chartSpecs[] = $this->chartSpecValues('finance_petty_by_store','column',$salesLabels,$pettyValues,'Petty cash','#7c5ce5');
    }
    if (($register + $petty) > 0) {
      $mix = $this->chartSpecValues('finance_cash_mix','donut',['Register','Petty Cash'],[$register,$petty],'Cash position','#1c4587');
      $mix['colors'] = ['#1c4587','#23a6a8'];
      $chartSpecs[] = $mix;
    }
    $salesTrend = $this->rows($analytics['sales_period'] ?? []);
    if ($salesTrend) $chartSpecs[] = $this->chartSpec('finance_sales_trend','line',$salesTrend,'Completed sales','#1c4587');

    $statuses = [$dashboard['status'], $identity['status']];
    if ($selectedStore > 0) $statuses[] = $financial['status'];
    $surface = $this->surface(
      'finance','Finance','Financial command centre',
      'Sales, Register, Petty Cash and ledger detail are read from authoritative MERDPOS financial services. Drupal does not submit or alter financial records in this milestone.',
      $this->status($statuses),
      [
        $this->metric('Sales today',$this->money($totalSales,$currency),$businessDate ?: 'Business date','brand'),
        $this->metric('Register',$this->money($register,$currency),'Visible-store position','info'),
        $this->metric('Petty cash',$this->money($petty,$currency),'Visible-store position','success'),
        $this->metric('Day status',strtoupper($dayStatus ?: 'UNKNOWN'),(string)($statement['store_name'] ?? 'Selected store'),'warning'),
        $this->metric('Ledger entries',(string)count($entryTable),'Selected store / date','brand'),
      ],
      [
        $this->table('Store position','Sales and cash by store',[['key'=>'store','label'=>'Store'],['key'=>'sales','label'=>'Sales'],['key'=>'register','label'=>'Register'],['key'=>'petty','label'=>'Petty cash']],$storeTable),
        $this->table('Day accounts',(string)($statement['store_name'] ?? 'Selected store'),[['key'=>'account','label'=>'Account'],['key'=>'opening','label'=>'Opening'],['key'=>'in','label'=>'Cash IN'],['key'=>'out','label'=>'Cash OUT'],['key'=>'available','label'=>'Available'],['key'=>'closing','label'=>'Closing'],['key'=>'status','label'=>'Status']],$accountTable),
        $this->table('Ledger','Latest 80 entries',[['key'=>'account','label'=>'Account'],['key'=>'type','label'=>'Type'],['key'=>'head','label'=>'Head'],['key'=>'amount','label'=>'Amount'],['key'=>'by','label'=>'By'],['key'=>'at','label'=>'Time']],$entryTable),
      ],
      ['source'=>'dashboard_data + store_identity + financials','business_date'=>$businessDate,'currency'=>$currency,'timezone'=>$timezone],
      [
        ['name'=>'store_id','label'=>'Store','type'=>'select','value'=>(string)$selectedStore,'options'=>array_map(static fn(array $row): array => ['value'=>(string)($row['id'] ?? ''),'label'=>(string)($row['store_name'] ?? '')],$stores)],
        ['name'=>'business_date','label'=>'Business date','type'=>'date','value'=>$businessDate,'options'=>[]],
      ],
    );
    $surface['role'] = ['key'=>(string)($roleRow['role_key'] ?? ''),'label'=>(string)($roleRow['role_label'] ?? ''),'loa'=>(int)($roleRow['authority_level'] ?? 0)];
    $surface['chart_specs'] = $chartSpecs;
    $surface['account_cards'] = $accountCards;
    $surface['ledger_rows'] = $entryTable;
    $surface['store_rows'] = $storeTable;
    $surface['exceptions'] = array_values(array_unique($exceptions));
    $surface['selected_store'] = ['id'=>$selectedStore,'name'=>(string)($statement['store_name'] ?? ''),'can_cross_store'=>!empty($statement['can_cross_store']),'can_open_day'=>!empty($statement['can_open_day'])];
    $surface['read_only'] = true;
    return $surface;
  }

  private function dev(): array {
    $status = $this->call('dev_status');
    $clients = $this->call('clients');
    $roles = $this->call('role_authority');
    $context = $this->call('client_context');
    $dashboard = $this->call('dashboard_data');
    $state = $this->call('beta_state');

    $statusPayload = $this->map($status['payload'] ?? []);
    $clientRows = $this->rows($clients['payload']['clients'] ?? []);
    $roleRows = $this->rows($roles['payload']['roles'] ?? []);
    $permissionRows = $this->rows($roles['payload']['permissions'] ?? []);
    $clientContext = $this->map($context['payload']['client'] ?? []);
    $contextPayload = $this->map($context['payload'] ?? []);
    $dashboardPayload = $this->map($dashboard['payload'] ?? []);
    $management = $this->map($dashboardPayload['management'] ?? []);
    $analytics = $this->map($management['analytics'] ?? []);
    $statePayload = $this->map($state['payload'] ?? []);
    $tables = $this->map($statusPayload['tables'] ?? []);

    $healthyTables = count(array_filter($tables, static fn(mixed $v): bool => $v !== null));
    $missingTables = count($tables) - $healthyTables;
    $tableRows = [];
    $tableCounts = [];
    foreach ($tables as $name => $count) {
      $tableRows[] = ['table'=>(string)$name, 'rows'=>$count === null ? 'Unavailable' : number_format((int)$count)];
      if ($count !== null) $tableCounts[(string)$name] = (int)$count;
    }
    arsort($tableCounts, SORT_NUMERIC);
    $topDb = array_slice($tableCounts, 0, 8, true);
    $topDbLabels = array_keys($topDb);
    $topDbValues = array_values($topDb);

    $clientTable = [];
    $clientLabels = [];
    $clientEmployees = [];
    $totalStores = 0;
    $totalEmployees = 0;
    foreach ($clientRows as $row) {
      $stores = (int)($row['store_count'] ?? 0);
      $employees = (int)($row['employee_count'] ?? 0);
      $totalStores += $stores;
      $totalEmployees += $employees;
      $name = (string)($row['name'] ?? '');
      $clientLabels[] = $name;
      $clientEmployees[] = $employees;
      $clientTable[] = [
        'name'=>$name, 'code'=>(string)($row['client_code'] ?? ''),
        'status'=>strtoupper((string)($row['status'] ?? '')), 'stores'=>(string)$stores, 'employees'=>(string)$employees,
      ];
    }

    $roleTable = [];
    $roleLabels = [];
    $roleLoa = [];
    foreach ($roleRows as $row) {
      $label = (string)($row['role_label'] ?? '');
      $loa = (int)($row['authority_level'] ?? 0);
      $roleLabels[] = $label;
      $roleLoa[] = $loa;
      $roleTable[] = [
        'role'=>$label, 'key'=>(string)($row['role_key'] ?? ''), 'base'=>(string)($row['base_role'] ?? ''),
        'loa'=>(string)$loa, 'employees'=>(string)($row['employee_count'] ?? 0),
        'widgets'=>(string)count($this->strings($row['allowed_widgets'] ?? [])),
        'status'=>strtoupper((string)($row['status'] ?? 'ACTIVE')),
      ];
    }

    $permissionTable = [];
    $permissionCategoryCounts = [];
    foreach ($permissionRows as $row) {
      $category = (string)($row['category'] ?? 'Other');
      $permissionCategoryCounts[$category] = ($permissionCategoryCounts[$category] ?? 0) + 1;
      $permissionTable[] = [
        'permission'=>(string)($row['label'] ?? $row['permission_key'] ?? ''),
        'key'=>(string)($row['permission_key'] ?? ''), 'category'=>$category,
        'loa'=>!empty($row['dev_only']) ? 'DEV only' : (string)($row['min_authority_level'] ?? ''),
      ];
    }

    $syncRows = [];
    $syncLabels = [];
    $syncValues = [];
    foreach ($this->rows($analytics['sync_statuses'] ?? []) as $row) {
      $label = strtoupper((string)($row['status'] ?? 'unknown'));
      $count = (int)($row['count'] ?? 0);
      $syncRows[] = ['status'=>$label, 'count'=>(string)$count];
      $syncLabels[] = $label;
      $syncValues[] = $count;
    }
    $syncAttention = array_sum($syncValues);

    $securityRows = [];
    foreach (array_slice($this->rows($statePayload['attendance_flags'] ?? []), 0, 20) as $row) {
      $securityRows[] = [
        'employee'=>(string)($row['full_name'] ?? $row['employee_name'] ?? ''),
        'store'=>(string)($row['attempted_store'] ?? $row['store_name'] ?? ''),
        'event'=>(string)($row['reason'] ?? $row['flag_type'] ?? ''),
        'status'=>strtoupper((string)($row['status'] ?? '')), 'at'=>(string)($row['created_at'] ?? ''),
      ];
    }

    $roleKey = (string)($statePayload['role_key'] ?? $statePayload['role'] ?? 'DEV');
    $roleLabel = (string)($statePayload['role_label'] ?? $roleKey);
    $actorLoa = (int)($statePayload['authority_level'] ?? $statusPayload['actor_loa'] ?? 0);
    $crossClient = !empty($contextPayload['cross_client_context']);
    $sourceStatuses = [
      'DEV status'=>$status['status'], 'Clients'=>$clients['status'], 'Role authority'=>$roles['status'],
      'Client context'=>$context['status'], 'Dashboard telemetry'=>$dashboard['status'], 'Actor state'=>$state['status'],
    ];
    $chartSpecs = [];
    if ($roleLabels) $chartSpecs[] = $this->chartSpecValues('dev_role_loa', 'bar', $roleLabels, $roleLoa, 'Authority level', '#1c4587');
    if ($syncLabels) $chartSpecs[] = $this->chartSpecValues('dev_sync_queue', 'bar', $syncLabels, $syncValues, 'Outbox records', '#23a6a8');
    if ($topDbLabels) $chartSpecs[] = $this->chartSpecValues('dev_db_rows', 'bar', $topDbLabels, $topDbValues, 'Rows', '#6d5dfc');
    if ($clientLabels) $chartSpecs[] = $this->chartSpecValues('dev_client_employees', 'bar', $clientLabels, $clientEmployees, 'Employees', '#16865b');

    return array_merge($this->surface(
      'dev', 'DEV', 'Platform command centre',
      'Live platform health, authorization, sync and environment telemetry from signed MERDPOS services. DevStudio/UI Studio remains intentionally excluded from the Drupal gateway.',
      $this->status(array_values($sourceStatuses)),
      [
        $this->metric('Actor LOA', (string)$actorLoa, $roleLabel, 'brand'),
        $this->metric('DB probes', $healthyTables . '/' . count($tables), $missingTables ? $missingTables . ' unavailable' : 'All probes available', 'info'),
        $this->metric('Clients', (string)count($clientRows), $totalStores . ' stores / ' . $totalEmployees . ' employees', 'success'),
        $this->metric('Sync attention', (string)$syncAttention, 'Pending / failed / processing outbox', $syncAttention > 0 ? 'warning' : 'success'),
        $this->metric('Security flags', (string)count($securityRows), 'Attendance account flags returned', count($securityRows) ? 'warning' : 'success'),
        $this->metric('Permissions', (string)count($permissionRows), count($permissionCategoryCounts) . ' policy categories', 'info'),
      ],
      [
        $this->table('Clients', 'Client directory', [['key'=>'name','label'=>'Client'],['key'=>'code','label'=>'Code'],['key'=>'status','label'=>'Status'],['key'=>'stores','label'=>'Stores'],['key'=>'employees','label'=>'Employees']], $clientTable),
        $this->table('Roles', 'Role and LOA model', [['key'=>'role','label'=>'Role'],['key'=>'key','label'=>'Key'],['key'=>'base','label'=>'Base'],['key'=>'loa','label'=>'LOA'],['key'=>'employees','label'=>'Employees'],['key'=>'widgets','label'=>'Widgets']], $roleTable),
        $this->table('Permission policy', 'Named permissions', [['key'=>'permission','label'=>'Permission'],['key'=>'category','label'=>'Category'],['key'=>'loa','label'=>'Minimum']], $permissionTable),
      ],
      ['source'=>'dev_status + clients + role_authority + client_context + dashboard_data + beta_state'],
    ), [
      'role'=>['key'=>$roleKey,'label'=>$roleLabel,'loa'=>$actorLoa],
      'environment'=>[
        'app'=>(string)($statusPayload['app'] ?? 'MERDPOS beta'), 'branch'=>(string)($statusPayload['branch'] ?? ''),
        'php'=>(string)($statusPayload['php_version'] ?? ''), 'db_server'=>(string)($statusPayload['server_version'] ?? ''),
        'authorization'=>(string)($statusPayload['authorization_model'] ?? ''),
      ],
      'active_client'=>[
        'name'=>(string)($clientContext['name'] ?? 'MERDPOS'), 'code'=>(string)($clientContext['client_code'] ?? ''),
        'scope'=>(string)($contextPayload['scope'] ?? ''), 'cross_client'=>$crossClient,
      ],
      'source_statuses'=>$sourceStatuses, 'sync_rows'=>$syncRows, 'security_rows'=>$securityRows,
      'client_rows'=>$clientTable, 'role_rows'=>$roleTable, 'permission_rows'=>$permissionTable, 'table_rows'=>$tableRows,
      'chart_specs'=>$chartSpecs, 'read_only'=>true, 'studio_excluded'=>true,
    ]);
  }

  private function call(string $route, array $query = []): array {
    $result = $this->gateway->call($route, 'GET', $query);
    $payload = $this->map($result['payload'] ?? []);
    if (($result['status'] ?? '') === 'ok' && ($payload['success'] ?? false) !== true) {
      return ['status'=>'unavailable','payload'=>[],'message'=>'MERDPOS returned an unsuccessful response.'];
    }
    return [
      'status' => (string)($result['status'] ?? 'unavailable'),
      'payload' => $payload,
      'message' => (string)($result['message'] ?? ''),
    ];
  }

  private function emptyCall(string $status): array {
    return ['status'=>$status, 'payload'=>[], 'message'=>''];
  }

  private function surface(
    string $key,
    string $eyebrow,
    string $title,
    string $description,
    string $status,
    array $metrics,
    array $groups,
    array $meta = [],
    array $filters = [],
  ): array {
    return [
      'key'=>$key,
      'eyebrow'=>$eyebrow,
      'title'=>$title,
      'description'=>$description,
      'status'=>$status,
      'status_label'=>match($status){'ok'=>'LIVE','partial'=>'PARTIAL','forbidden'=>'FORBIDDEN','unconfigured'=>'UNCONFIGURED',default=>'UNAVAILABLE'},
      'metrics'=>$metrics,
      'groups'=>array_values(array_filter($groups, static fn(array $group): bool => !empty($group))),
      'meta'=>$meta,
      'filters'=>$filters,
    ];
  }

  private function unavailableSurface(string $section): array {
    return $this->surface($section, strtoupper($section), 'Unavailable section', 'This MERDPOS section is not available.', 'unavailable', [], []);
  }

  private function metric(string $label, string $value, string $meta, string $tone): array {
    return ['label'=>$label, 'value'=>$value, 'meta'=>$meta, 'tone'=>$tone];
  }

  private function cards(string $eyebrow, string $title, array $items, string $empty): array {
    return ['kind'=>'cards','eyebrow'=>$eyebrow,'title'=>$title,'items'=>$items,'empty'=>$empty];
  }

  private function table(string $eyebrow, string $title, array $columns, array $rows): array {
    return ['kind'=>'table','eyebrow'=>$eyebrow,'title'=>$title,'columns'=>$columns,'rows'=>$rows,'empty'=>'No records returned for this view.'];
  }

  private function bars(string $eyebrow, string $title, array $items): array {
    if (!$items) return [];
    return ['kind'=>'bars','eyebrow'=>$eyebrow,'title'=>$title,'items'=>$items,'empty'=>'No trend data returned.'];
  }

  private function trend(mixed $value, string $currency, bool $money): array {
    $rows = $this->rows($value);
    if (!$rows) return [];
    $max = 0.0;
    foreach ($rows as $row) $max = max($max, (float)($row['value'] ?? 0));
    $out = [];
    foreach ($rows as $row) {
      $raw = (float)($row['value'] ?? 0);
      $out[] = [
        'label'=>(string)($row['date'] ?? ''),
        'value'=>$money ? $this->money($raw, $currency) : $this->number($raw),
        'percent'=>$max > 0 ? (int)round(($raw / $max) * 100) : 0,
      ];
    }
    return $out;
  }

  private function dashboardQuery(array $query): array {
    $out = [];
    $storeId = filter_var($query['store_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($storeId !== false && $storeId > 0) $out['store_id'] = (string)$storeId;
    $period = strtolower(trim((string)($query['period'] ?? '7')));
    if (!in_array($period, ['current_week','7','14','30'], true)) $period = '7';
    $out['period'] = $period;
    if ($period !== 'current_week') $out['days'] = $period;
    return $out;
  }

  private function strings(mixed $value): array {
    if (!is_array($value) || !array_is_list($value)) return [];
    $out = [];
    foreach ($value as $item) {
      if (!is_scalar($item)) continue;
      $text = trim((string)$item);
      if ($text !== '') $out[] = $text;
    }
    return array_values(array_unique($out));
  }

  private function dashboardKpi(string $key, string $label, string $value, string $meta, string $tone, string $icon): array {
    return ['key'=>$key,'kind'=>'kpi','label'=>$label,'value'=>$value,'meta'=>$meta,'tone'=>$tone,'icon'=>$icon];
  }
  private function dashboardChange(string $key, string $label, array $rows, string $currency, bool $money, string $meta, string $icon): array {
    $count = count($rows);
    $current = $count > 0 ? (float)($rows[$count - 1]['value'] ?? 0) : 0.0;
    $previous = $count > 1 ? (float)($rows[$count - 2]['value'] ?? 0) : 0.0;
    $delta = $current - $previous;
    $direction = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
    if ($previous != 0.0) {
      $relative = ($delta > 0 ? '+' : '') . number_format(($delta / abs($previous)) * 100, 1) . '%';
    }
    else {
      $relative = $current != 0.0 ? 'New activity' : 'No change';
    }
    $formattedCurrent = $money ? $this->money($current, $currency) : (string)(int)round($current);
    $formattedDelta = $money ? $this->money($delta, $currency) : (string)(int)round($delta);
    $detail = $delta == 0.0 ? 'Same as yesterday' : (($delta > 0 ? '+' : '') . $formattedDelta . ' vs yesterday');
    $tone = $money ? ($direction === 'up' ? 'success' : ($direction === 'down' ? 'danger' : 'info')) : 'info';
    return [
      'key'=>$key,'kind'=>'change','label'=>$label,'value'=>$formattedCurrent,'meta'=>$meta,
      'tone'=>$tone,'icon'=>$icon,'direction'=>$direction,'change'=>$relative,'detail'=>$detail,
    ];
  }

  private function dashboardWidget(string $key, string $kind, string $title, string $description, string $icon, array $data = []): array {
    return ['key'=>$key,'kind'=>$kind,'title'=>$title,'description'=>$description,'icon'=>$icon] + $data;
  }
  private function chartSpec(string $key, string $type, array $rows, string $seriesLabel, string $color): array {
    $labels = [];
    $values = [];
    foreach ($rows as $row) {
      $date = (string)($row['date'] ?? '');
      $labels[] = $date !== '' && strlen($date) >= 10 ? substr($date, 5) : $date;
      $values[] = is_numeric($row['value'] ?? NULL) ? (float)$row['value'] : 0.0;
    }
    return $this->chartSpecValues($key, $type, $labels, $values, $seriesLabel, $color);
  }

  private function chartSpecValues(string $key, string $type, array $labels, array $values, string $seriesLabel, string $color): array {
    return [
      'key'=>$key,'type'=>$type,'labels'=>array_values($labels),'values'=>array_values($values),
      'series_label'=>$seriesLabel,'color'=>$color,'height'=>280,
    ];
  }

  private function localDateTime(mixed $value, string $timezone): string {
    $text = trim((string)$value);
    if ($text === '') return '—';
    try {
      $source = new \DateTimeImmutable($text, new \DateTimeZone('UTC'));
      $zone = new \DateTimeZone($timezone !== '' ? $timezone : 'Australia/Sydney');
      return $source->setTimezone($zone)->format('d M H:i');
    }
    catch (\Throwable) {
      return $text;
    }
  }

  private function status(array $statuses): string {
    $statuses = array_values(array_filter($statuses, static fn(string $s): bool => $s !== ''));
    if (!$statuses) return 'unavailable';
    $ok = count(array_filter($statuses, static fn(string $s): bool => $s === 'ok'));
    if ($ok === count($statuses)) return 'ok';
    if ($ok > 0) return 'partial';
    if (in_array('forbidden', $statuses, true)) return 'forbidden';
    if (in_array('unconfigured', $statuses, true)) return 'unconfigured';
    return 'unavailable';
  }

  private function rows(mixed $value): array {
    if (!is_array($value) || !array_is_list($value)) return [];
    return array_values(array_filter($value, 'is_array'));
  }

  private function map(mixed $value): array {
    return is_array($value) ? $value : [];
  }

  private function sum(array $rows, string $key): float {
    $sum = 0.0;
    foreach ($rows as $row) $sum += is_numeric($row[$key] ?? null) ? (float)$row[$key] : 0.0;
    return $sum;
  }

  private function money(mixed $value, string $currency): string {
    $amount = is_numeric($value) ? (float)$value : 0.0;
    $code = strtoupper(trim($currency)) ?: 'AUD';
    return $code . ' ' . number_format($amount, 2, '.', ',');
  }

  private function number(mixed $value): string {
    $number = is_numeric($value) ? (float)$value : 0.0;
    return rtrim(rtrim(number_format($number, 2, '.', ','), '0'), '.');
  }

  private function duration(int $minutes): string {
    $hours = intdiv(max(0, $minutes), 60);
    $rest = max(0, $minutes) % 60;
    return $hours > 0 ? sprintf('%dh %02dm', $hours, $rest) : sprintf('%dm', $rest);
  }

  private function dayName(int $day): string {
    return [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'][$day] ?? '—';
  }

  private function clock(string $value): string {
    $value = trim($value);
    if ($value === '') return '—';
    $parts = explode(':', $value);
    if (count($parts) < 2) return $value;
    return sprintf('%02d:%02d', (int)$parts[0], (int)$parts[1]);
  }

}
