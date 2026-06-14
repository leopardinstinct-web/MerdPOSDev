import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

// MERDPOS / POS LATEST - API Timesheet v2
// Replace this URL with your hosted PHP endpoint.
const String kTimesheetApiUrl = 'https://app.merdpos.com/api/get_timesheet.php';

// Change these defaults only if your backend uses different IDs.
const int kDefaultClientId = 1;
const int kDefaultStoreId = 1;

void main() {
  runApp(const MerdPosTimesheetApp());
}

class MerdPosTimesheetApp extends StatelessWidget {
  const MerdPosTimesheetApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'MERDPOS Timesheet',
      theme: ThemeData(
        useMaterial3: true,
        colorSchemeSeed: Colors.indigo,
        scaffoldBackgroundColor: const Color(0xFFF6F7FB),
      ),
      home: const TimesheetPage(),
    );
  }
}

class TimesheetPage extends StatefulWidget {
  const TimesheetPage({super.key});

  @override
  State<TimesheetPage> createState() => _TimesheetPageState();
}

class _TimesheetPageState extends State<TimesheetPage> {
  bool _loading = false;
  String? _error;
  Map<String, dynamic>? _payload;
  List<TimesheetRow> _rows = <TimesheetRow>[];

  late DateTime _weekStart;
  late DateTime _weekEnd;

  @override
  void initState() {
    super.initState();
    final DateTime now = DateTime.now();
    _weekStart = DateTime(
      now.year,
      now.month,
      now.day,
    ).subtract(Duration(days: now.weekday - DateTime.monday));
    _weekEnd = _weekStart.add(const Duration(days: 6));
    unawaited(_loadTimesheet());
  }

  Future<void> _loadTimesheet() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final Uri uri = Uri.parse(kTimesheetApiUrl).replace(
        queryParameters: {
          'client_id': '$kDefaultClientId',
          'store_id': '$kDefaultStoreId',
          'week_start': _dateOnly(_weekStart),
          'week_end': _dateOnly(_weekEnd),
        },
      );

      final http.Response response = await http
          .get(uri)
          .timeout(const Duration(seconds: 25));

      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw Exception('HTTP ${response.statusCode}: ${response.body}');
      }

      final Object? decoded = jsonDecode(response.body);
      if (decoded is! Map<String, dynamic>) {
        throw Exception('API did not return a JSON object.');
      }

      final List<TimesheetRow> parsedRows = TimesheetParser.parse(decoded);

      setState(() {
        _payload = decoded;
        _rows = parsedRows;
      });
    } catch (e) {
      setState(() {
        _error = e.toString();
      });
    } finally {
      if (mounted) {
        setState(() {
          _loading = false;
        });
      }
    }
  }

  Future<void> _moveWeek(int deltaWeeks) async {
    setState(() {
      _weekStart = _weekStart.add(Duration(days: 7 * deltaWeeks));
      _weekEnd = _weekStart.add(const Duration(days: 6));
    });
    await _loadTimesheet();
  }

  @override
  Widget build(BuildContext context) {
    final Map<String, dynamic>? summary =
        _payload?['summary'] is Map<String, dynamic>
        ? _payload!['summary'] as Map<String, dynamic>
        : null;

    return Scaffold(
      appBar: AppBar(
        title: const Text('API Timesheet v2'),
        actions: [
          IconButton(
            tooltip: 'Refresh',
            onPressed: _loading ? null : _loadTimesheet,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _loadTimesheet,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            _WeekPicker(
              start: _weekStart,
              end: _weekEnd,
              onPrevious: _loading ? null : () => _moveWeek(-1),
              onNext: _loading ? null : () => _moveWeek(1),
            ),
            const SizedBox(height: 12),
            if (_loading) const LinearProgressIndicator(),
            if (_error != null) ...[
              const SizedBox(height: 12),
              _ErrorCard(message: _error!),
            ],
            if (_payload != null) ...[
              const SizedBox(height: 12),
              _ApiStatusCard(payload: _payload!),
            ],
            if (summary != null) ...[
              const SizedBox(height: 12),
              _SummaryGrid(summary: summary),
            ],
            const SizedBox(height: 12),
            if (!_loading && _payload != null && _rows.isEmpty)
              _RawPayloadCard(payload: _payload!)
            else
              ..._rows.map((TimesheetRow row) => _TimesheetRowCard(row: row)),
          ],
        ),
      ),
    );
  }
}

class TimesheetParser {
  static List<TimesheetRow> parse(Map<String, dynamic> payload) {
    final List<TimesheetRow> result = <TimesheetRow>[];

    void addMap(Map<String, dynamic> map, {String? inheritedName}) {
      final TimesheetRow row = TimesheetRow.fromMap(
        map,
        inheritedName: inheritedName,
      );
      if (row.hasUsefulData) result.add(row);
    }

    void walk(Object? value, {String? inheritedName}) {
      if (value == null) return;
      if (value is List) {
        for (final Object? item in value) {
          walk(item, inheritedName: inheritedName);
        }
        return;
      }
      if (value is Map) {
        final Map<String, dynamic> map = value.map(
          (key, val) => MapEntry(key.toString(), val),
        );
        final String? name =
            _firstString(map, const [
              'employee_name',
              'staff_name',
              'user_name',
              'cashier_name',
              'name',
              'employee',
              'staff',
            ]) ??
            inheritedName;

        final bool looksLikeRow = _looksLikeTimesheetRow(map);
        if (looksLikeRow) addMap(map, inheritedName: inheritedName);

        for (final String key in const [
          'rows',
          'data',
          'logs',
          'records',
          'timesheet',
          'timesheets',
          'shifts',
          'entries',
          'employees',
          'employee_rows',
          'items',
        ]) {
          if (map.containsKey(key)) walk(map[key], inheritedName: name);
        }
      }
    }

    walk(payload);

    // If the API returns only employee totals without nested logs, the walk above
    // still captures them because total/payable fields count as useful row data.
    final Set<String> seen = <String>{};
    return result.where((TimesheetRow row) {
      final String key =
          '${row.employee}|${row.date}|${row.clockIn}|${row.clockOut}|${row.payableHours}|${row.totalWage}';
      if (seen.contains(key)) return false;
      seen.add(key);
      return true;
    }).toList();
  }

  static bool _looksLikeTimesheetRow(Map<String, dynamic> map) {
    final Set<String> keys = map.keys
        .map((String k) => k.toLowerCase())
        .toSet();
    const List<String> indicators = [
      'clock_in',
      'clock_out',
      'time_in',
      'time_out',
      'shift_start',
      'shift_end',
      'payable_hours',
      'total_payable_hours',
      'counted_hours',
      'total_counted_hours',
      'hours',
      'duration_minutes',
      'total_wage',
      'wage',
      'needs_review',
    ];
    return indicators.any(keys.contains);
  }

  static String? _firstString(Map<String, dynamic> map, List<String> keys) {
    for (final String key in keys) {
      final Object? value = map[key];
      if (value != null && value.toString().trim().isNotEmpty) {
        return value.toString().trim();
      }
    }
    return null;
  }
}

class TimesheetRow {
  TimesheetRow({
    required this.employee,
    required this.date,
    required this.clockIn,
    required this.clockOut,
    required this.rawHours,
    required this.payableHours,
    required this.totalWage,
    required this.status,
    required this.notes,
  });

  final String employee;
  final String date;
  final String clockIn;
  final String clockOut;
  final String rawHours;
  final String payableHours;
  final String totalWage;
  final String status;
  final String notes;

  bool get hasUsefulData =>
      employee != '-' ||
      date != '-' ||
      clockIn != '-' ||
      clockOut != '-' ||
      rawHours != '-' ||
      payableHours != '-' ||
      totalWage != '-';

  factory TimesheetRow.fromMap(
    Map<String, dynamic> map, {
    String? inheritedName,
  }) {
    String first(List<String> keys, {String fallback = '-'}) {
      for (final String key in keys) {
        if (map.containsKey(key) && map[key] != null) {
          final String value = map[key].toString().trim();
          if (value.isNotEmpty && value.toLowerCase() != 'null') return value;
        }
      }
      return fallback;
    }

    return TimesheetRow(
      employee: first(const [
        'employee_name',
        'staff_name',
        'user_name',
        'cashier_name',
        'employee',
        'staff',
        'name',
      ], fallback: inheritedName ?? '-'),
      date: first(const ['date', 'shift_date', 'work_date', 'day', 'log_date']),
      clockIn: first(const [
        'clock_in',
        'time_in',
        'start_time',
        'shift_start',
        'in_time',
      ]),
      clockOut: first(const [
        'clock_out',
        'time_out',
        'end_time',
        'shift_end',
        'out_time',
      ]),
      rawHours: first(const [
        'raw_hours',
        'actual_hours',
        'duration_hours',
        'hours',
      ]),
      payableHours: first(const [
        'payable_hours',
        'total_payable_hours',
        'counted_hours',
        'total_counted_hours',
        'rounded_hours',
      ]),
      totalWage: first(const [
        'total_wage',
        'wage',
        'pay',
        'amount',
        'payable_amount',
      ]),
      status: first(const [
        'status',
        'review_status',
        'action',
      ], fallback: 'OK'),
      notes: first(const [
        'notes',
        'reason',
        'review_reason',
        'exception',
      ], fallback: ''),
    );
  }
}

class _WeekPicker extends StatelessWidget {
  const _WeekPicker({
    required this.start,
    required this.end,
    required this.onPrevious,
    required this.onNext,
  });

  final DateTime start;
  final DateTime end;
  final VoidCallback? onPrevious;
  final VoidCallback? onNext;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            IconButton(
              onPressed: onPrevious,
              icon: const Icon(Icons.chevron_left),
            ),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  const Text(
                    'Payroll week',
                    style: TextStyle(fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 4),
                  Text('${_dateOnly(start)} to ${_dateOnly(end)}'),
                ],
              ),
            ),
            IconButton(
              onPressed: onNext,
              icon: const Icon(Icons.chevron_right),
            ),
          ],
        ),
      ),
    );
  }
}

class _ApiStatusCard extends StatelessWidget {
  const _ApiStatusCard({required this.payload});

  final Map<String, dynamic> payload;

  @override
  Widget build(BuildContext context) {
    final bool success = payload['success'] == true;
    final String api = payload['api']?.toString() ?? 'get_timesheet.php';
    final String version = payload['version']?.toString() ?? 'unknown';

    return Card(
      child: ListTile(
        leading: Icon(
          success ? Icons.check_circle : Icons.warning_amber_rounded,
        ),
        title: Text('$api  •  $version'),
        subtitle: Text(
          success
              ? 'API connected'
              : (payload['error']?.toString() ?? 'API returned success=false'),
        ),
      ),
    );
  }
}

class _SummaryGrid extends StatelessWidget {
  const _SummaryGrid({required this.summary});

  final Map<String, dynamic> summary;

  @override
  Widget build(BuildContext context) {
    final List<_Metric> metrics = [
      _Metric(
        'Employees',
        _pick(summary, const ['employee_count', 'employees']),
      ),
      _Metric(
        'Counted hrs',
        _pick(summary, const ['total_counted_hours', 'counted_hours']),
      ),
      _Metric(
        'Payable hrs',
        _pick(summary, const ['total_payable_hours', 'payable_hours']),
      ),
      _Metric(
        'Review mins',
        _pick(summary, const ['total_review_minutes', 'review_minutes']),
      ),
      _Metric('Total wage', _pick(summary, const ['total_wage', 'wage'])),
    ];

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Wrap(
          spacing: 10,
          runSpacing: 10,
          children: metrics
              .where((_Metric metric) => metric.value != '-')
              .map((metric) => _MetricBox(metric: metric))
              .toList(),
        ),
      ),
    );
  }

  static String _pick(Map<String, dynamic> map, List<String> keys) {
    for (final String key in keys) {
      final Object? value = map[key];
      if (value != null && value.toString().trim().isNotEmpty)
        return value.toString();
    }
    return '-';
  }
}

class _Metric {
  const _Metric(this.label, this.value);
  final String label;
  final String value;
}

class _MetricBox extends StatelessWidget {
  const _MetricBox({required this.metric});

  final _Metric metric;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 120,
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(metric.label, style: Theme.of(context).textTheme.labelSmall),
          const SizedBox(height: 4),
          Text(metric.value, style: Theme.of(context).textTheme.titleMedium),
        ],
      ),
    );
  }
}

class _TimesheetRowCard extends StatelessWidget {
  const _TimesheetRowCard({required this.row});

  final TimesheetRow row;

  @override
  Widget build(BuildContext context) {
    final bool review =
        row.status.toLowerCase().contains('review') ||
        row.notes.toLowerCase().contains('review');

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    row.employee,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                Chip(
                  label: Text(review ? 'Needs review' : row.status),
                  avatar: Icon(review ? Icons.flag : Icons.check, size: 18),
                ),
              ],
            ),
            const SizedBox(height: 8),
            _kv('Date', row.date),
            _kv('Clock in', row.clockIn),
            _kv('Clock out', row.clockOut),
            _kv('Raw hours', row.rawHours),
            _kv('Payable hours', row.payableHours),
            _kv('Wage', row.totalWage),
            if (row.notes.isNotEmpty) _kv('Notes', row.notes),
          ],
        ),
      ),
    );
  }

  Widget _kv(String key, String value) {
    if (value == '-') return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 105,
            child: Text(
              key,
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
          ),
          Expanded(child: Text(value)),
        ],
      ),
    );
  }
}

class _RawPayloadCard extends StatelessWidget {
  const _RawPayloadCard({required this.payload});

  final Map<String, dynamic> payload;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'API returned no standard shift rows, but the raw response is below.',
              style: TextStyle(fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 8),
            SelectableText(const JsonEncoder.withIndent('  ').convert(payload)),
          ],
        ),
      ),
    );
  }
}

class _ErrorCard extends StatelessWidget {
  const _ErrorCard({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Card(
      color: Theme.of(context).colorScheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: SelectableText(message),
      ),
    );
  }
}

String _dateOnly(DateTime date) {
  String two(int n) => n.toString().padLeft(2, '0');
  return '${date.year}-${two(date.month)}-${two(date.day)}';
}
