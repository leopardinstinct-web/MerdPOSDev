part of merdpos_staff;

class HomePage extends StatefulWidget {
  const HomePage({super.key, required this.session, required this.primaryEmployee, required this.employees});

  final AppSession session;
  final Employee primaryEmployee;
  final List<Employee> employees;

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  late Employee _primaryEmployee;
  late List<Employee> _employees;
  Employee? _secondaryEmployee;
  bool _syncing = false;
  String? _message;
  String? _error;

  @override
  void initState() {
    super.initState();
    _primaryEmployee = widget.primaryEmployee;
    _employees = _mergeEmployee(widget.employees, widget.primaryEmployee);
  }

  void _showInfo(String message) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));

  Future<void> _showChangePasswordDialog(Employee employee) async {
    await showDialog<void>(context: context, builder: (_) => ChangePasswordDialog(session: widget.session, employee: employee));
  }

  Future<void> _punch(Employee employee, String type) async {
    setState(() {
      _syncing = true;
      _message = null;
      _error = null;
    });
    try {
      final DateTime now = DateTime.now();
      final String logDate = _dateOnly(now);
      final String logTime = _timeOnly(now);
      final Map<String, dynamic> payload = await Api.postJson(Uri.parse(kSyncEmployeeLogsUrl), <String, dynamic>{
        'client_id': widget.session.clientId,
        'store_id': widget.session.storeId,
        'store_name': widget.session.storeName,
        'device_uuid': widget.session.deviceUuid,
        'activation_token': widget.session.activationToken,
        'logs': [
          <String, dynamic>{
            'employee_id': employee.id,
            'user_name': employee.fullName,
            'store_id': widget.session.storeId,
            'store_name': widget.session.storeName,
            'log_type': type,
            'log_date': logDate,
            'log_time': logTime,
            'log_datetime': '$logDate $logTime',
            'local_log_id': const Uuid().v4(),
          }
        ],
      });
      if (payload['success'] != true) throw Exception(payload['error']?.toString() ?? 'Punch sync failed.');
      if (!mounted) return;
      setState(() => _message = '${employee.fullName}: $type recorded at $logTime');
    } catch (e) {
      if (mounted) setState(() => _error = cleanError(e));
    } finally {
      if (mounted) setState(() => _syncing = false);
    }
  }

  void _openTimesheet(Employee employee) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => TimesheetPage(
        session: widget.session,
        employee: employee,
        primary: _primaryEmployee,
        secondary: _secondaryEmployee,
        onPrimaryChangePassword: () => _showChangePasswordDialog(_primaryEmployee),
        onPrimaryLogOff: _logoutPrimary,
        onAddUser: () async {
          final bool added = await _addSecondaryUser();
          if (added && mounted && Navigator.of(context).canPop()) Navigator.of(context).pop();
        },
        onSecondaryLogOff: _logOffSecondary,
      ),
    ));
  }

  Future<void> _logoutPrimary() async {
    final Employee? secondary = _secondaryEmployee;
    if (secondary != null) {
      await PrimaryLoginStore.save(secondary);
      if (!mounted) return;
      setState(() {
        _primaryEmployee = secondary;
        _secondaryEmployee = null;
        _message = '${secondary.fullName} is now the active user.';
        _error = null;
      });
      return;
    }
    await PrimaryLoginStore.clear();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => LoginPage(session: widget.session, onResetSetup: () async {})));
  }

  Future<bool> _addSecondaryUser() async {
    if (_secondaryEmployee != null) {
      _showInfo('Maximum 2 users allowed at the same time.');
      return false;
    }
    final Employee? employee = await showDialog<Employee>(
      context: context,
      builder: (_) => SecondaryLoginDialog(session: widget.session, primaryEmployee: _primaryEmployee),
    );
    if (employee == null) return false;
    setState(() {
      _secondaryEmployee = employee;
      _employees = _mergeEmployee(_employees, employee);
    });
    return true;
  }

  void _logOffSecondary() => setState(() => _secondaryEmployee = null);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Row(
        children: [
          _PosSideRail(
            primary: _primaryEmployee,
            secondary: _secondaryEmployee,
            onPrimaryTimesheet: () => _openTimesheet(_primaryEmployee),
            onPrimaryChangePassword: () => _showChangePasswordDialog(_primaryEmployee),
            onPrimaryLogOff: _logoutPrimary,
            onAddUser: () async => _addSecondaryUser(),
            onSecondaryLogOff: _logOffSecondary,
            onWhoIsWorking: () => _showInfo('Who Is Working coming soon.'),
            onPos: () => _showInfo('POS module coming soon.'),
            onFinancials: () => _showInfo('Financials coming soon.'),
            onInventory: () => _showInfo('Inventory coming soon.'),
            onSync: _syncing ? null : () => _punch(_primaryEmployee, 'IN'),
            onSettings: () => _showInfo('Settings coming soon.'),
          ),
          Expanded(
            child: SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _ScreenHeader(storeName: widget.session.storeName, title: 'Staff dashboard', subtitle: 'API Timesheet v2 • backend login hashing enabled'),
                    const SizedBox(height: 18),
                    Wrap(
                      spacing: 12,
                      runSpacing: 12,
                      children: [
                        _MetricCard(icon: Icons.person, label: 'Primary', value: _primaryEmployee.fullName),
                        _MetricCard(icon: Icons.people, label: 'Visible users', value: _secondaryEmployee == null ? '1 user' : '2 users'),
                        _MetricCard(icon: Icons.lock, label: 'Login security', value: 'Backend verified'),
                      ],
                    ),
                    const SizedBox(height: 16),
                    if (_syncing) const LinearProgressIndicator(minHeight: 2),
                    if (_message != null) ...[const SizedBox(height: 12), _MessageCard(message: _message!, type: MessageType.info)],
                    if (_error != null) ...[const SizedBox(height: 12), _MessageCard(message: _error!, type: MessageType.error)],
                    const Spacer(),
                    Center(child: Text('Select a module from the sidebar.', style: Theme.of(context).textTheme.titleMedium?.copyWith(color: BlueIce.textMuted))),
                    const Spacer(),
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
