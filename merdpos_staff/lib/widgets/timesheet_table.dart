part of merdpos_staff;

class _TimesheetTopBar extends StatelessWidget {
  const _TimesheetTopBar({required this.storeName, required this.employee, required this.weekStart, required this.weekEnd, required this.shiftCount, required this.totalHours, required this.totalWage, required this.loading, required this.onPrevious, required this.onNext, required this.onRefresh});
  final String storeName;
  final Employee employee;
  final DateTime weekStart;
  final DateTime weekEnd;
  final int shiftCount;
  final double totalHours;
  final double totalWage;
  final bool loading;
  final VoidCallback? onPrevious;
  final VoidCallback? onNext;
  final VoidCallback? onRefresh;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: _panelDecoration(),
      child: Row(
        children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(storeName, style: const TextStyle(color: BlueIce.textMuted, fontSize: 13, fontWeight: FontWeight.w600)),
              const SizedBox(height: 4),
              Text('Weekly Time Sheet', style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 10),
              Wrap(spacing: 8, runSpacing: 8, children: [
                _MetricPill(icon: Icons.person, label: employee.fullName),
                const _MetricPill(icon: Icons.verified, label: 'API Timesheet v2'),
                _MetricPill(icon: Icons.table_rows, label: '$shiftCount shift${shiftCount == 1 ? '' : 's'}'),
                _MetricPill(icon: Icons.schedule, label: '${_formatHours(totalHours)} hours'),
                _MetricPill(icon: Icons.payments, label: '\$${totalWage.toStringAsFixed(2)}'),
              ]),
            ]),
          ),
          const SizedBox(width: 12),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
            decoration: BoxDecoration(color: BlueIce.bg, borderRadius: BorderRadius.circular(14), border: Border.all(color: BlueIce.border)),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              IconButton(tooltip: 'Previous week', onPressed: onPrevious, icon: const Icon(Icons.chevron_left)),
              Column(children: [
                const Text('Payroll week', style: TextStyle(fontSize: 12, color: BlueIce.textMuted, fontWeight: FontWeight.w600)),
                const SizedBox(height: 3),
                Text('${_dateOnly(weekStart)} → ${_dateOnly(weekEnd)}', style: const TextStyle(fontSize: 13, color: BlueIce.text, fontWeight: FontWeight.w700)),
              ]),
              IconButton(tooltip: 'Next week', onPressed: onNext, icon: const Icon(Icons.chevron_right)),
              IconButton(tooltip: 'Refresh', onPressed: onRefresh, icon: loading ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.refresh)),
            ]),
          ),
        ],
      ),
    );
  }
}

class TimesheetTable extends StatelessWidget {
  const TimesheetTable({super.key, required this.rows});
  final List<TimesheetRow> rows;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(builder: (BuildContext context, BoxConstraints constraints) {
      final double width = constraints.maxWidth < 820 ? 820 : constraints.maxWidth;
      final double storeWidth = width * 0.21;
      final double dateWidth = width * 0.13;
      final double clockWidth = width * 0.17;
      final double hoursWidth = width * 0.12;
      final double wageWidth = width * 0.10;
      return ClipRRect(
        borderRadius: BorderRadius.circular(14),
        child: Container(
          decoration: _panelDecoration(radius: 14),
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: SizedBox(
              width: width,
              child: SingleChildScrollView(
                child: DataTable(
                  border: TableBorder.all(color: BlueIce.border, width: 1),
                  headingRowColor: const WidgetStatePropertyAll<Color>(BlueIce.surface),
                  headingTextStyle: const TextStyle(color: BlueIce.brandBlue, fontWeight: FontWeight.w800, fontSize: 13),
                  dataTextStyle: const TextStyle(color: BlueIce.text, fontSize: 13),
                  columnSpacing: 0,
                  horizontalMargin: 0,
                  columns: [
                    DataColumn(label: _tsHeader('Store name', width: storeWidth, left: true)),
                    DataColumn(label: _tsHeader('In date', width: dateWidth)),
                    DataColumn(label: _tsHeader('Actual in', width: clockWidth)),
                    DataColumn(label: _tsHeader('Rounded in', width: clockWidth)),
                    DataColumn(label: _tsHeader('Out date', width: dateWidth)),
                    DataColumn(label: _tsHeader('Actual out', width: clockWidth)),
                    DataColumn(label: _tsHeader('Rounded out', width: clockWidth)),
                    DataColumn(label: _tsHeader('Total hours', width: hoursWidth)),
                    DataColumn(label: _tsHeader('Wage', width: wageWidth)),
                  ],
                  rows: rows.map((TimesheetRow row) => DataRow(cells: [
                    DataCell(_tsCell(row.store, width: storeWidth, left: true)),
                    DataCell(_tsCell(row.inDate, width: dateWidth)),
                    DataCell(_tsCell(row.actualIn, width: clockWidth)),
                    DataCell(_tsCell(row.roundedIn, width: clockWidth)),
                    DataCell(_tsCell(row.outDate, width: dateWidth)),
                    DataCell(_tsCell(row.actualOut, width: clockWidth)),
                    DataCell(_tsCell(row.roundedOut, width: clockWidth)),
                    DataCell(_tsCell(row.totalHours, width: hoursWidth)),
                    DataCell(_tsCell(row.wage, width: wageWidth)),
                  ])).toList(),
                ),
              ),
            ),
          ),
        ),
      );
    });
  }
}

Widget _tsHeader(String text, {required double width, bool left = false}) => SizedBox(width: width, child: Align(alignment: left ? Alignment.centerLeft : Alignment.center, child: Padding(padding: const EdgeInsets.symmetric(horizontal: 8), child: Text(text))));
Widget _tsCell(String text, {required double width, bool left = false}) => SizedBox(width: width, child: Align(alignment: left ? Alignment.centerLeft : Alignment.center, child: Padding(padding: const EdgeInsets.symmetric(horizontal: 8), child: Text(text, overflow: TextOverflow.ellipsis))));

class _EmptyTimesheetCard extends StatelessWidget {
  const _EmptyTimesheetCard({required this.employeeName, required this.weekStart, required this.weekEnd});
  final String employeeName;
  final DateTime weekStart;
  final DateTime weekEnd;
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: _panelDecoration(radius: 14),
      child: Row(children: [
        const CircleAvatar(radius: 28, backgroundColor: BlueIce.surface, child: Icon(Icons.event_busy, color: BlueIce.error, size: 28)),
        const SizedBox(width: 14),
        Expanded(child: Text('No timesheet shifts found for $employeeName from ${_dateOnly(weekStart)} to ${_dateOnly(weekEnd)}. Use the arrows to select another week.', style: const TextStyle(color: BlueIce.textMuted, fontSize: 14, fontWeight: FontWeight.w600))),
      ]),
    );
  }
}
