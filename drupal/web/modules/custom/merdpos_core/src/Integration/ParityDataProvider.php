<?php
declare(strict_types=1);

namespace Drupal\merdpos_core\Integration;

final class ParityDataProvider implements ParityDataProviderInterface {

  public function __construct(
    private readonly PortalGatewayClientInterface $gateway,
    private readonly WorkingNowProviderInterface $workingNow,
  ) {}

  public function home(array $query = []): array {
    $dashboard = $this->call('dashboard_data');
    $working = $this->workingNow->load();
    $payload = $dashboard['payload'];
    $management = $this->map($payload['management'] ?? []);
    $currency = (string)($management['currency_code'] ?? $payload['client_defaults']['currency_code'] ?? 'AUD');
    $salesRows = $this->rows($management['sales_by_store'] ?? []);
    $cashRows = $this->rows($management['financial_by_store'] ?? []);
    $totalSales = $this->sum($salesRows, 'today_sales');
    $cashPosition = $this->sum($cashRows, 'register_balance') + $this->sum($cashRows, 'petty_balance');
    $workingCount = ($working['status'] ?? '') === 'ok' ? (int)($working['count'] ?? 0) : null;
    $pending = isset($payload['pending_disputes_count']) ? (int)$payload['pending_disputes_count'] : null;

    $workingCards = [];
    foreach ($this->rows($working['people'] ?? []) as $person) {
      $minutes = max(0, (int)($person['working_minutes'] ?? 0));
      $workingCards[] = [
        'title' => (string)($person['full_name'] ?? ''),
        'meta' => (string)($person['store_name'] ?? ''),
        'value' => $this->duration($minutes),
      ];
    }

    $salesTable = [];
    foreach ($salesRows as $row) {
      $salesTable[] = [
        'store' => (string)($row['store_name'] ?? ''),
        'sales' => $this->money($row['today_sales'] ?? 0, (string)($row['currency_code'] ?? $currency)),
      ];
    }
    $cashTable = [];
    foreach ($cashRows as $row) {
      $rowCurrency = (string)($row['currency_code'] ?? $currency);
      $cashTable[] = [
        'store' => (string)($row['store_name'] ?? ''),
        'register' => $this->money($row['register_balance'] ?? 0, $rowCurrency),
        'petty' => $this->money($row['petty_balance'] ?? 0, $rowCurrency),
      ];
    }

    $analytics = $this->map($management['analytics'] ?? []);
    return $this->surface(
      'home', 'Home', 'Management workspace',
      'Live MERDPOS Beta operations through the signed Drupal service boundary.',
      $this->status([$dashboard['status'], (string)($working['status'] ?? 'unavailable')]),
      [
        $this->metric('Working now', $workingCount === null ? '—' : (string)$workingCount, 'Current open attendance shifts', 'info'),
        $this->metric('Sales today', $this->money($totalSales, $currency), (string)($management['business_date'] ?? 'Current business date'), 'brand'),
        $this->metric('Pending disputes', $pending === null ? '—' : (string)$pending, 'Awaiting review', 'warning'),
        $this->metric('Cash position', $this->money($cashPosition, $currency), 'Register + petty cash', 'success'),
      ],
      [
        $this->cards('Live workforce', 'Working now', $workingCards, 'Nobody is currently clocked in.'),
        $this->table('Store sales', 'Today by store', [['key'=>'store','label'=>'Store'],['key'=>'sales','label'=>'Sales']], $salesTable),
        $this->table('Cash position', 'Register and petty cash', [['key'=>'store','label'=>'Store'],['key'=>'register','label'=>'Register'],['key'=>'petty','label'=>'Petty cash']], $cashTable),
        $this->bars('Sales trend', 'Recent period', $this->trend($analytics['sales_period'] ?? [], $currency, true)),
        $this->bars('Attendance trend', 'Recent period', $this->trend($analytics['attendance_period'] ?? [], '', false)),
      ],
      ['source' => 'dashboard_data + working_now', 'generated_at' => (string)($working['generated_at'] ?? '')],
    );
  }

  public function section(string $section, array $query = []): array {
    return match ($section) {
      'operations' => $this->operations(),
      'reports' => $this->reports($query),
      'finance' => $this->finance($query),
      'dev' => $this->dev(),
      default => $this->unavailableSurface($section),
    };
  }

  private function operations(): array {
    $directory = $this->call('admin_directory');
    $identity = $this->call('store_identity');
    $timings = $this->call('store_timings');
    $directoryPayload = $directory['payload'];
    $employees = $this->rows($directoryPayload['employees'] ?? []);
    $stores = $this->rows($identity['payload']['stores'] ?? $directoryPayload['stores'] ?? []);
    $timingRows = $this->rows($timings['payload']['timings'] ?? []);
    $storeNames = [];
    foreach ($stores as $store) $storeNames[(int)($store['id'] ?? 0)] = (string)($store['store_name'] ?? '');

    $employeeTable = [];
    foreach (array_slice($employees, 0, 120) as $row) {
      $employeeTable[] = [
        'name' => (string)($row['full_name'] ?? ''),
        'role' => (string)($row['role_label'] ?? $row['role_name'] ?? $row['employee_type'] ?? ''),
        'store' => (string)($row['store_name'] ?? '—'),
        'status' => strtoupper((string)($row['status'] ?? '')),
      ];
    }
    $storeTable = [];
    foreach ($stores as $row) {
      $storeTable[] = [
        'name' => (string)($row['store_name'] ?? ''),
        'code' => (string)($row['store_code'] ?? ''),
        'status' => strtoupper((string)($row['status'] ?? '')),
        'timezone' => (string)($row['timezone'] ?? ''),
      ];
    }

    $timingTable = [];
    foreach ($timingRows as $row) {
      $day = (int)($row['day_of_week'] ?? 0);
      $timingTable[] = [
        'store' => $storeNames[(int)($row['store_id'] ?? 0)] ?? ('Store ' . (string)($row['store_id'] ?? '')),
        'day' => $this->dayName($day),
        'hours' => (int)($row['is_closed'] ?? 0) === 1
          ? 'Closed'
          : $this->clock((string)($row['start_time'] ?? '')) . ' – ' . $this->clock((string)($row['end_time'] ?? '')),
      ];
    }
    $activeEmployees = count(array_filter($employees, static fn(array $r): bool => strtolower((string)($r['status'] ?? '')) === 'active'));
    $roles = array_unique(array_filter(array_map(static fn(array $r): string => (string)($r['role_label'] ?? $r['role_name'] ?? ''), $employees)));

    return $this->surface(
      'operations', 'Operations', 'Workforce and stores',
      'Permission-scoped workforce, store identity and operating hours from authoritative MERDPOS Beta.',
      $this->status([$directory['status'], $identity['status'], $timings['status']]),
      [
        $this->metric('Active employees', (string)$activeEmployees, 'Current client workforce', 'info'),
        $this->metric('Stores', (string)count($stores), 'Client-scoped locations', 'brand'),
        $this->metric('Roles in use', (string)count($roles), 'Current workforce role labels', 'success'),
        $this->metric('Weekly timing rows', (string)count($timingRows), 'Store operating schedule', 'warning'),
      ],
      [
        $this->table('Workforce', 'Employees', [['key'=>'name','label'=>'Employee'],['key'=>'role','label'=>'Role'],['key'=>'store','label'=>'Store'],['key'=>'status','label'=>'Status']], $employeeTable),
        $this->table('Stores', 'Store directory', [['key'=>'name','label'=>'Store'],['key'=>'code','label'=>'Code'],['key'=>'status','label'=>'Status'],['key'=>'timezone','label'=>'Timezone']], $storeTable),
        $this->table('Store timings', 'Weekly schedule', [['key'=>'store','label'=>'Store'],['key'=>'day','label'=>'Day'],['key'=>'hours','label'=>'Hours']], $timingTable),
      ],
      ['source' => 'admin_directory + store_identity + store_timings'],
    );
  }

  private function reports(array $query): array {
    $dashboard = $this->call('dashboard_data');
    $currency = (string)($dashboard['payload']['client_defaults']['currency_code'] ?? 'AUD');
    $weeks = $this->call('weeks');
    $weekRows = $this->rows($weeks['payload']['weeks'] ?? []);
    $currentWeek = (string)($weeks['payload']['current_week'] ?? '');
    $allowedWeeks = array_values(array_filter(array_map(static fn(array $r): string => (string)($r['value'] ?? ''), $weekRows)));
    $requestedWeek = trim((string)($query['week_start'] ?? ''));
    $selectedWeek = in_array($requestedWeek, $allowedWeeks, true) ? $requestedWeek : $currentWeek;
    $timesheet = $selectedWeek !== '' ? $this->call('timesheet', ['week_start' => $selectedWeek]) : $this->emptyCall('unavailable');
    $disputes = $this->call('disputes');
    $report = $this->map($timesheet['payload']['report'] ?? []);
    $employeeSummary = $this->rows($report['employee_summary'] ?? []);
    $storeSummary = $this->rows($report['store_summary'] ?? []);
    $disputeRows = $this->rows($disputes['payload']['disputes'] ?? []);
    $payrollVisible = (bool)($report['payroll_visible'] ?? false);
    $pendingDisputes = count(array_filter($disputeRows, static fn(array $r): bool => strtolower((string)($r['status'] ?? '')) === 'pending'));

    $employeesTable = [];
    foreach ($employeeSummary as $row) {
      $item = [
        'employee' => (string)($row['employee_name'] ?? ''),
        'hours' => $this->number($row['total_hours'] ?? 0),
      ];
      if ($payrollVisible) $item['wage'] = $this->money($row['total_wage'] ?? 0, $currency);
      $employeesTable[] = $item;
    }

    $storeTable = [];
    foreach ($storeSummary as $row) {
      $item = [
        'store' => (string)($row['store_name'] ?? ''),
        'employees' => (string)($row['total_employees_worked'] ?? 0),
        'hours' => $this->number($row['total_hours_worked'] ?? 0),
      ];
      if ($payrollVisible) $item['amount'] = $this->money($row['total_amount'] ?? 0, $currency);
      $storeTable[] = $item;
    }

    $shiftTable = [];
    foreach ($this->rows($report['employees'] ?? []) as $employee) {
      foreach ($this->rows($employee['rows'] ?? []) as $row) {
        $shiftTable[] = [
          'employee' => (string)($employee['employee_name'] ?? ''),
          'store' => (string)($row['store_name'] ?? ''),
          'date' => (string)($row['in_date'] ?? ''),
          'in' => $this->clock((string)($row['actual_in_time'] ?? '')),
          'out' => $this->clock((string)($row['actual_out_time'] ?? '')),
          'hours' => $this->number($row['total_hours'] ?? 0),
          'late' => !empty($row['is_late']) ? 'Late' : 'On time',
        ];
        if (count($shiftTable) >= 200) break 2;
      }
    }

    $disputeTable = [];
    foreach (array_slice($disputeRows, 0, 100) as $row) {
      $disputeTable[] = [
        'employee' => (string)($row['full_name'] ?? ''),
        'store' => (string)($row['store_name'] ?? ''),
        'type' => str_replace('_', ' ', (string)($row['dispute_type'] ?? '')),
        'status' => strtoupper((string)($row['status'] ?? '')),
        'submitted' => (string)($row['submitted_at'] ?? ''),
      ];
    }
    $employeeColumns = [['key'=>'employee','label'=>'Employee'],['key'=>'hours','label'=>'Hours']];
    $storeColumns = [['key'=>'store','label'=>'Store'],['key'=>'employees','label'=>'Employees'],['key'=>'hours','label'=>'Hours']];
    if ($payrollVisible) {
      $employeeColumns[] = ['key'=>'wage','label'=>'Wage'];
      $storeColumns[] = ['key'=>'amount','label'=>'Amount'];
    }

    return $this->surface(
      'reports', 'Reports', 'Timesheets and disputes',
      'The existing MERDPOS reconciliation engine remains authoritative; Drupal renders its permission-scoped report output unchanged.',
      $this->status([$dashboard['status'], $weeks['status'], $timesheet['status'], $disputes['status']]),
      [
        $this->metric('Week', (string)($report['week_label'] ?? $selectedWeek ?: '—'), 'Selected payroll week', 'brand'),
        $this->metric('Total hours', $this->number($report['grand_total_hours'] ?? 0), 'Frozen MERDPOS reconciliation', 'info'),
        $this->metric('Employees', (string)count($employeeSummary), 'Employees with report rows', 'success'),
        $this->metric('Pending disputes', (string)$pendingDisputes, 'Awaiting review', 'warning'),
      ],
      [
        $this->table('Store summary', 'Hours by store', $storeColumns, $storeTable),
        $this->table('Employee summary', $payrollVisible ? 'Hours and wages' : 'Hours', $employeeColumns, $employeesTable),
        $this->table('Shift detail', 'Up to 200 shifts', [['key'=>'employee','label'=>'Employee'],['key'=>'store','label'=>'Store'],['key'=>'date','label'=>'Date'],['key'=>'in','label'=>'IN'],['key'=>'out','label'=>'OUT'],['key'=>'hours','label'=>'Hours'],['key'=>'late','label'=>'Start']], $shiftTable),
        $this->table('Disputes', 'Current dispute queue', [['key'=>'employee','label'=>'Employee'],['key'=>'store','label'=>'Store'],['key'=>'type','label'=>'Type'],['key'=>'status','label'=>'Status'],['key'=>'submitted','label'=>'Submitted']], $disputeTable),
      ],
      ['source' => 'dashboard_data + weeks + timesheet + disputes', 'payroll_visible' => $payrollVisible ? 'yes' : 'no'],
      [
        [
          'name' => 'week_start',
          'label' => 'Week',
          'type' => 'select',
          'value' => $selectedWeek,
          'options' => array_map(static fn(array $row): array => ['value'=>(string)($row['value'] ?? ''),'label'=>(string)($row['label'] ?? $row['value'] ?? '')], $weekRows),
        ],
      ],
    );
  }

  private function finance(array $query): array {
    $dashboard = $this->call('dashboard_data');
    $identity = $this->call('store_identity');
    $management = $this->map($dashboard['payload']['management'] ?? []);
    $stores = $this->rows($identity['payload']['stores'] ?? []);
    $currency = (string)($management['currency_code'] ?? 'AUD');
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
    foreach ($cashRows as $row) {
      $id = (int)($row['store_id'] ?? 0);
      $rowCurrency = (string)($row['currency_code'] ?? $currency);
      $storeTable[] = [
        'store' => (string)($row['store_name'] ?? ''),
        'sales' => $this->money($salesByStore[$id] ?? 0, $rowCurrency),
        'register' => $this->money($row['register_balance'] ?? 0, $rowCurrency),
        'petty' => $this->money($row['petty_balance'] ?? 0, $rowCurrency),
      ];
    }
    $accountTable = [];
    foreach ($this->rows($statement['accounts'] ?? []) as $row) {
      $accountTable[] = [
        'account' => (string)($row['account'] ?? ''),
        'opening' => $this->money($row['opening'] ?? 0, $currency),
        'in' => $this->money($row['cash_in'] ?? 0, $currency),
        'out' => $this->money($row['cash_out'] ?? 0, $currency),
        'available' => $this->money($row['available'] ?? 0, $currency),
        'closing' => $row['closing'] === null ? '—' : $this->money($row['closing'] ?? 0, $currency),
        'status' => strtoupper((string)($row['status'] ?? '')),
      ];
    }

    $entryTable = [];
    foreach (array_slice($this->rows($statement['entries'] ?? []), 0, 60) as $row) {
      $entryTable[] = [
        'account' => (string)($row['account'] ?? ''),
        'type' => (string)($row['entry_type'] ?? ''),
        'head' => (string)($row['head'] ?? ''),
        'amount' => $this->money($row['amount'] ?? 0, $currency),
        'by' => (string)($row['full_name'] ?? ''),
        'at' => (string)($row['created_at'] ?? ''),
      ];
    }
    $statuses = [$dashboard['status'], $identity['status']];
    if ($selectedStore > 0) $statuses[] = $financial['status'];

    return $this->surface(
      'finance', 'Finance', 'Financial operations',
      'Store cash, sales and ledger detail come from existing MERDPOS financial services; Drupal performs no financial calculations or writes.',
      $this->status($statuses),
      [
        $this->metric('Sales today', $this->money($totalSales, $currency), $businessDate ?: 'Business date', 'brand'),
        $this->metric('Register', $this->money($register, $currency), 'All visible stores', 'info'),
        $this->metric('Petty cash', $this->money($petty, $currency), 'All visible stores', 'success'),
        $this->metric('Selected day', strtoupper((string)($statement['day_status'] ?? '—')), (string)($statement['store_name'] ?? 'Store detail'), 'warning'),
      ],
      [
        $this->table('Store position', 'Sales and cash by store', [['key'=>'store','label'=>'Store'],['key'=>'sales','label'=>'Sales'],['key'=>'register','label'=>'Register'],['key'=>'petty','label'=>'Petty cash']], $storeTable),
        $this->table('Day accounts', (string)($statement['store_name'] ?? 'Selected store'), [['key'=>'account','label'=>'Account'],['key'=>'opening','label'=>'Opening'],['key'=>'in','label'=>'Cash IN'],['key'=>'out','label'=>'Cash OUT'],['key'=>'available','label'=>'Available'],['key'=>'closing','label'=>'Closing'],['key'=>'status','label'=>'Status']], $accountTable),
        $this->table('Ledger', 'Latest 60 entries', [['key'=>'account','label'=>'Account'],['key'=>'type','label'=>'Type'],['key'=>'head','label'=>'Head'],['key'=>'amount','label'=>'Amount'],['key'=>'by','label'=>'By'],['key'=>'at','label'=>'Time']], $entryTable),
      ],
      ['source' => 'dashboard_data + store_identity + financials', 'business_date' => $businessDate],
      [
        [
          'name' => 'store_id', 'label' => 'Store', 'type' => 'select', 'value' => (string)$selectedStore,
          'options' => array_map(static fn(array $row): array => ['value'=>(string)($row['id'] ?? ''),'label'=>(string)($row['store_name'] ?? '')], $stores),
        ],
        ['name'=>'business_date','label'=>'Business date','type'=>'date','value'=>$businessDate,'options'=>[]],
      ],
    );
  }

  private function dev(): array {
    $status = $this->call('dev_status');
    $clients = $this->call('clients');
    $roles = $this->call('role_authority');
    $context = $this->call('client_context');
    $statusPayload = $status['payload'];
    $clientRows = $this->rows($clients['payload']['clients'] ?? []);
    $roleRows = $this->rows($roles['payload']['roles'] ?? []);
    $permissionRows = $this->rows($roles['payload']['permissions'] ?? []);
    $tables = $this->map($statusPayload['tables'] ?? []);
    $healthyTables = count(array_filter($tables, static fn(mixed $v): bool => $v !== null));

    $clientTable = [];
    foreach ($clientRows as $row) {
      $clientTable[] = [
        'name' => (string)($row['name'] ?? ''),
        'code' => (string)($row['client_code'] ?? ''),
        'status' => strtoupper((string)($row['status'] ?? '')),
        'stores' => (string)($row['store_count'] ?? 0),
        'employees' => (string)($row['employee_count'] ?? 0),
      ];
    }
    $roleTable = [];
    foreach ($roleRows as $row) {
      $roleTable[] = [
        'role' => (string)($row['role_label'] ?? ''),
        'key' => (string)($row['role_key'] ?? ''),
        'base' => (string)($row['base_role'] ?? ''),
        'loa' => (string)($row['authority_level'] ?? ''),
        'employees' => (string)($row['employee_count'] ?? 0),
      ];
    }

    $permissionTable = [];
    foreach ($permissionRows as $row) {
      $permissionTable[] = [
        'permission' => (string)($row['label'] ?? $row['permission_key'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'loa' => !empty($row['dev_only']) ? 'DEV only' : (string)($row['min_authority_level'] ?? ''),
      ];
    }
    $tableTable = [];
    foreach ($tables as $name => $count) {
      $tableTable[] = [
        'table' => (string)$name,
        'rows' => $count === null ? 'Unavailable' : (string)$count,
      ];
    }
    $clientContext = $this->map($context['payload']['client'] ?? []);

    return $this->surface(
      'dev', 'DEV', 'Platform workspace',
      'Live platform diagnostics and authorization state for the actual MERDPOS DEV service actor. DevStudio/UI Studio is intentionally excluded from the Drupal gateway.',
      $this->status([$status['status'], $clients['status'], $roles['status'], $context['status']]),
      [
        $this->metric('PHP', (string)($statusPayload['php_version'] ?? '—'), 'Authoritative Beta runtime', 'brand'),
        $this->metric('DB tables', $healthyTables . '/' . count($tables), 'Diagnostic table probes', 'info'),
        $this->metric('Clients', (string)count($clientRows), 'Visible clients', 'success'),
        $this->metric('Actor LOA', (string)($statusPayload['actor_loa'] ?? '—'), (string)($statusPayload['authorization_model'] ?? 'Authorization model'), 'warning'),
      ],
      [
        $this->table('Clients', 'Client directory', [['key'=>'name','label'=>'Client'],['key'=>'code','label'=>'Code'],['key'=>'status','label'=>'Status'],['key'=>'stores','label'=>'Stores'],['key'=>'employees','label'=>'Employees']], $clientTable),
        $this->table('Roles', 'Role and LOA model', [['key'=>'role','label'=>'Role'],['key'=>'key','label'=>'Key'],['key'=>'base','label'=>'Base'],['key'=>'loa','label'=>'LOA'],['key'=>'employees','label'=>'Employees']], $roleTable),
        $this->table('Permission policy', 'Named permissions', [['key'=>'permission','label'=>'Permission'],['key'=>'category','label'=>'Category'],['key'=>'loa','label'=>'Minimum']], $permissionTable),
        $this->table('Database diagnostics', 'Table row visibility', [['key'=>'table','label'=>'Table'],['key'=>'rows','label'=>'Rows']], $tableTable),
        $this->cards('Active client', 'Current gateway context', [[
          'title'=>(string)($clientContext['name'] ?? 'MERDPOS'),
          'meta'=>(string)($clientContext['client_code'] ?? ''),
          'value'=>'DevStudio excluded',
        ]], 'Client context unavailable.'),
      ],
      ['source' => 'dev_status + clients + role_authority + client_context'],
    );
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
