part of merdpos_staff;

class TimesheetRow {
  const TimesheetRow({required this.employee, required this.store, required this.inDate, required this.actualIn, required this.roundedIn, required this.outDate, required this.actualOut, required this.roundedOut, required this.totalHours, required this.wage});
  final String employee;
  final String store;
  final String inDate;
  final String actualIn;
  final String roundedIn;
  final String outDate;
  final String actualOut;
  final String roundedOut;
  final String totalHours;
  final String wage;
  bool get hasUsefulData => store != '-' || inDate != '-' || actualIn != '-' || actualOut != '-' || totalHours != '-';
  double get totalHoursValue => double.tryParse(totalHours.replaceAll(',', '')) ?? 0.0;
  double get wageValue => double.tryParse(wage.replaceAll('\$', '').replaceAll(',', '')) ?? 0.0;

  factory TimesheetRow.fromMap(Map<String, dynamic> map, {String? inheritedName}) {
    final Map<String, dynamic> lower = _lowerKeyMap(map);
    final String actualIn = _formatTimeForDisplay(_firstString(lower, const ['actual_in_time', 'actual_in', 'clock_in', 'time_in', 'in_time']) ?? '-');
    final String actualOut = _formatTimeForDisplay(_firstString(lower, const ['actual_out_time', 'actual_out', 'clock_out', 'time_out', 'out_time']) ?? '-');
    final String roundedIn = _formatTimeForDisplay(_firstString(lower, const ['rounded_in_time', 'rounded_in']) ?? actualIn);
    final String roundedOut = _formatTimeForDisplay(_firstString(lower, const ['rounded_out_time', 'rounded_out']) ?? actualOut);
    return TimesheetRow(
      employee: _firstString(lower, const ['user_name', 'employee_name', 'staff_name', 'name']) ?? inheritedName ?? '-',
      store: _firstString(lower, const ['store_name', 'store', 'shop_name']) ?? '-',
      inDate: _firstString(lower, const ['in_date', 'date', 'log_date']) ?? '-',
      actualIn: actualIn,
      roundedIn: roundedIn,
      outDate: _firstString(lower, const ['out_date']) ?? _firstString(lower, const ['in_date', 'date', 'log_date']) ?? '-',
      actualOut: actualOut,
      roundedOut: roundedOut,
      totalHours: _firstString(lower, const ['total_hours', 'payable_hours', 'counted_hours', 'hours']) ?? '-',
      wage: _firstString(lower, const ['wage', 'total_wage', 'amount', 'total_amount']) ?? '-',
    );
  }
}
