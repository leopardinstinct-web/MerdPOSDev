part of merdpos_staff;

class _TimesheetTopBar extends StatelessWidget {
  const _TimesheetTopBar({
    required this.storeName,
    required this.employee,
    required this.weekStart,
    required this.weekEnd,
    required this.shiftCount,
    required this.totalHours,
    required this.totalWage,
    required this.loading,
    required this.onPrevious,
    required this.onNext,
    required this.onPickWeek,
    required this.onRefresh,
  });
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
  final VoidCallback? onPickWeek;
  final VoidCallback? onRefresh;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: _panelDecoration(),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  storeName,
                  style: const TextStyle(
                    color: BlueIce.textMuted,
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  'Weekly Time Sheet',
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    _MetricPill(icon: Icons.person, label: employee.fullName),
                    const _MetricPill(
                      icon: Icons.verified,
                      label: 'API Timesheet v2',
                    ),
                    _MetricPill(
                      icon: Icons.table_rows,
                      label: '$shiftCount shift${shiftCount == 1 ? '' : 's'}',
                    ),
                    _MetricPill(
                      icon: Icons.schedule,
                      label: '${_formatHours(totalHours)} hours',
                    ),
                    _MetricPill(
                      icon: Icons.payments,
                      label: '\$${totalWage.toStringAsFixed(2)}',
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
            decoration: BoxDecoration(
              color: BlueIce.bg,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: BlueIce.border),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                IconButton(
                  tooltip: 'Previous week',
                  onPressed: onPrevious,
                  icon: const Icon(Icons.chevron_left),
                ),
                Tooltip(
                  message: 'Choose week',
                  child: InkWell(
                    onTap: onPickWeek,
                    borderRadius: BorderRadius.circular(10),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 4,
                      ),
                      child: Column(
                        children: [
                          const Text(
                            'Payroll week',
                            style: TextStyle(
                              fontSize: 12,
                              color: BlueIce.textMuted,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          const SizedBox(height: 3),
                          Text(
                            '${_dateOnly(weekStart)} to ${_dateOnly(weekEnd)}',
                            style: const TextStyle(
                              fontSize: 13,
                              color: BlueIce.text,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                IconButton(
                  tooltip: 'Choose week',
                  onPressed: onPickWeek,
                  icon: const Icon(Icons.calendar_month_outlined),
                ),
                IconButton(
                  tooltip: 'Next week',
                  onPressed: onNext,
                  icon: const Icon(Icons.chevron_right),
                ),
                IconButton(
                  tooltip: 'Refresh',
                  onPressed: onRefresh,
                  icon: loading
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.refresh),
                ),
              ],
            ),
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
    return ClipRRect(
      borderRadius: BorderRadius.circular(14),
      child: Container(
        decoration: _panelDecoration(radius: 14),
        child: Column(
          children: [
            const _TimesheetHeaderRow(),
            Expanded(
              child: Scrollbar(
                child: ListView.builder(
                  padding: EdgeInsets.zero,
                  itemCount: rows.length,
                  itemBuilder: (BuildContext context, int index) =>
                      _TimesheetDataRow(row: rows[index], shaded: index.isOdd),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _TimesheetHeaderRow extends StatelessWidget {
  const _TimesheetHeaderRow();

  @override
  Widget build(BuildContext context) {
    return const _TimesheetGridRow(
      shaded: true,
      textStyle: TextStyle(
        color: BlueIce.brandBlue,
        fontWeight: FontWeight.w800,
        fontSize: 12,
      ),
      children: [
        'Store',
        'In date',
        'Actual In',
        'Rounded In',
        'Out date',
        'Actual Out',
        'Rounded Out',
        'Hours',
        'Wage',
      ],
    );
  }
}

class _TimesheetDataRow extends StatelessWidget {
  const _TimesheetDataRow({required this.row, required this.shaded});
  final TimesheetRow row;
  final bool shaded;

  @override
  Widget build(BuildContext context) {
    return _TimesheetGridRow(
      shaded: shaded,
      textStyle: const TextStyle(
        color: BlueIce.text,
        fontSize: 11,
        fontWeight: FontWeight.w500,
      ),
      children: [
        row.store,
        row.inDate,
        row.actualIn,
        row.roundedIn,
        row.outDate,
        row.actualOut,
        row.roundedOut,
        row.totalHours,
        row.wage,
      ],
    );
  }
}

class _TimesheetGridRow extends StatelessWidget {
  const _TimesheetGridRow({
    required this.children,
    required this.textStyle,
    required this.shaded,
  });
  final List<String> children;
  final TextStyle textStyle;
  final bool shaded;

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: const BoxConstraints(minHeight: 42),
      decoration: BoxDecoration(
        color: shaded ? BlueIce.slate.withOpacity(0.52) : BlueIce.surface,
        border: const Border(bottom: BorderSide(color: BlueIce.border)),
      ),
      child: Row(
        children: [
          _fitCell(children[0], flex: 13, left: true),
          _fitCell(children[1], flex: 10),
          _fitCell(children[2], flex: 11),
          _fitCell(children[3], flex: 11),
          _fitCell(children[4], flex: 10),
          _fitCell(children[5], flex: 11),
          _fitCell(children[6], flex: 11),
          _fitCell(children[7], flex: 7),
          _fitCell(children[8], flex: 7),
        ],
      ),
    );
  }

  Widget _fitCell(String value, {required int flex, bool left = false}) {
    return Expanded(
      flex: flex,
      child: Container(
        alignment: left ? Alignment.centerLeft : Alignment.center,
        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 6),
        decoration: const BoxDecoration(
          border: Border(right: BorderSide(color: BlueIce.border)),
        ),
        child: Text(
          value,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          textAlign: left ? TextAlign.left : TextAlign.center,
          style: textStyle,
        ),
      ),
    );
  }
}

class _EmptyTimesheetCard extends StatelessWidget {
  const _EmptyTimesheetCard({
    required this.employeeName,
    required this.weekStart,
    required this.weekEnd,
  });
  final String employeeName;
  final DateTime weekStart;
  final DateTime weekEnd;
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: _panelDecoration(radius: 14),
      child: Row(
        children: [
          const CircleAvatar(
            radius: 28,
            backgroundColor: BlueIce.surface,
            child: Icon(Icons.event_busy, color: BlueIce.error, size: 28),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Text(
              'No timesheet shifts found for $employeeName from ${_dateOnly(weekStart)} to ${_dateOnly(weekEnd)}. Use the arrows to select another week.',
              style: const TextStyle(
                color: BlueIce.textMuted,
                fontSize: 14,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
