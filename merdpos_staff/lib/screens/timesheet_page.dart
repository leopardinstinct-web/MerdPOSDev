part of merdpos_staff;

class TimesheetPage extends StatefulWidget {
  const TimesheetPage({super.key, required this.session, required this.employee, required this.primary, required this.secondary, required this.onPrimaryChangePassword, required this.onPrimaryLogOff, required this.onAddUser, required this.onSecondaryLogOff});
  final AppSession session;
  final Employee employee;
  final Employee primary;
  final Employee? secondary;
  final VoidCallback onPrimaryChangePassword;
  final Future<void> Function() onPrimaryLogOff;
  final Future<void> Function() onAddUser;
  final VoidCallback onSecondaryLogOff;

  @override
  State<TimesheetPage> createState() => _TimesheetPageState();
}

class _TimesheetPageState extends State<TimesheetPage> {
  late DateTime _weekStart;
  bool _loading = false;
  String? _error;
  List<TimesheetRow> _rows = <TimesheetRow>[];

  @override
  void initState() {
    super.initState();
    _weekStart = startOfWeek(DateTime.now());
    unawaited(_load());
  }

  DateTime get _weekEnd => _weekStart.add(const Duration(days: 6));
  double get _totalHours => _rows.fold(0.0, (double sum, TimesheetRow row) => sum + row.totalHoursValue);
  double get _totalWage => _rows.fold(0.0, (double sum, TimesheetRow row) => sum + row.wageValue);

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final Uri uri = Uri.parse(kTimesheetApiUrl).replace(queryParameters: {
        'client_id': widget.session.clientId.toString(),
        'employee_id': widget.employee.id.toString(),
        'week_start': _dateOnly(_weekStart),
        'week_end': _dateOnly(_weekEnd),
        'activation_token': widget.session.activationToken,
      });
      final Map<String, dynamic> payload = await Api.getJson(uri);
      if (payload['success'] != true) throw Exception(payload['error']?.toString() ?? 'Timesheet failed.');
      final List<TimesheetRow> rows = TimesheetParser.parse(payload);
      if (!mounted) return;
      setState(() => _rows = rows);
    } catch (e) {
      if (mounted) setState(() => _error = cleanError(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _moveWeek(int days) {
    setState(() => _weekStart = _weekStart.add(Duration(days: days)));
    unawaited(_load());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Row(
        children: [
          _PosSideRail(
            primary: widget.primary,
            secondary: widget.secondary,
            onPrimaryTimesheet: () {},
            onPrimaryChangePassword: widget.onPrimaryChangePassword,
            onPrimaryLogOff: widget.onPrimaryLogOff,
            onAddUser: widget.onAddUser,
            onSecondaryLogOff: widget.onSecondaryLogOff,
            onWhoIsWorking: () => Navigator.of(context).pop(),
            onPos: () => Navigator.of(context).pop(),
            onFinancials: () => _snack(context, 'Financials coming soon.'),
            onInventory: () => _snack(context, 'Inventory coming soon.'),
            onSync: null,
            onSettings: () => _snack(context, 'Settings coming soon.'),
          ),
          Expanded(
            child: SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _TimesheetTopBar(
                      storeName: widget.session.storeName,
                      employee: widget.employee,
                      weekStart: _weekStart,
                      weekEnd: _weekEnd,
                      shiftCount: _rows.length,
                      totalHours: _totalHours,
                      totalWage: _totalWage,
                      loading: _loading,
                      onPrevious: _loading ? null : () => _moveWeek(-7),
                      onNext: _loading ? null : () => _moveWeek(7),
                      onRefresh: _loading ? null : _load,
                    ),
                    const SizedBox(height: 16),
                    if (_loading) const LinearProgressIndicator(minHeight: 2),
                    if (_error != null) ...[const SizedBox(height: 12), _MessageCard(message: _error!, type: MessageType.error)],
                    const SizedBox(height: 12),
                    Expanded(child: _rows.isEmpty && !_loading ? _EmptyTimesheetCard(employeeName: widget.employee.fullName, weekStart: _weekStart, weekEnd: _weekEnd) : TimesheetTable(rows: _rows)),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
