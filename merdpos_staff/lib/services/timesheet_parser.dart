part of merdpos_staff;

class TimesheetParser {
  static List<TimesheetRow> parse(Map<String, dynamic> payload) {
    final List<TimesheetRow> result = <TimesheetRow>[];
    void walk(Object? value, {String? inheritedName}) {
      if (value == null) return;
      if (value is List) {
        for (final Object? item in value) walk(item, inheritedName: inheritedName);
        return;
      }
      if (value is Map) {
        final Map<String, dynamic> map = value.map((key, val) => MapEntry(key.toString(), val));
        final Map<String, dynamic> lower = _lowerKeyMap(map);
        final String? name = _firstString(lower, const ['employee_name', 'user_name', 'staff_name', 'name']) ?? inheritedName;
        if (_looksLikeTimesheetRow(map)) {
          final TimesheetRow row = TimesheetRow.fromMap(map, inheritedName: inheritedName);
          if (row.hasUsefulData) result.add(row);
        }
        for (final String key in const ['detailed_rows', 'employee_wise_detailed_report', 'rows', 'data', 'logs', 'records', 'timesheet', 'timesheets', 'shifts', 'entries', 'items']) {
          if (lower.containsKey(key)) walk(lower[key], inheritedName: name);
        }
      }
    }
    walk(payload);
    final Set<String> seen = <String>{};
    return result.where((TimesheetRow row) {
      final String key = '${row.employee}|${row.store}|${row.inDate}|${row.actualIn}|${row.actualOut}|${row.totalHours}|${row.wage}';
      if (seen.contains(key)) return false;
      seen.add(key);
      return true;
    }).toList();
  }

  static bool _looksLikeTimesheetRow(Map<String, dynamic> map) {
    final Set<String> keys = map.keys.map((String k) => k.toLowerCase()).toSet();
    return keys.any(const {'actual_in_time', 'actual_out_time', 'rounded_in_time', 'rounded_out_time', 'in_date', 'out_date', 'total_hours', 'wage', 'clock_in', 'clock_out'}.contains);
  }
}
