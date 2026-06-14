import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

// MERDPOS / POS LATEST - API Timesheet v2 + POS-style staff dashboard
// Setup -> Store selection -> Device activation -> Numeric login -> Main Menu
// Main Menu supports primary user + one secondary visible user.
const String kApiBaseUrl = 'https://app.merdpos.com/api';
const String kGetStoresUrl = '$kApiBaseUrl/get_stores.php';
const String kActivateDeviceUrl = '$kApiBaseUrl/activate_device.php';
const String kGetEmployeesUrl = '$kApiBaseUrl/get_employees.php';
const String kSyncEmployeeLogsUrl = '$kApiBaseUrl/sync_employee_logs.php';
const String kTimesheetApiUrl = '$kApiBaseUrl/get_timesheet.php';

void main() {
  runApp(const MerdPosStaffApp());
}

class MerdPosStaffApp extends StatelessWidget {
  const MerdPosStaffApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'MerdPOS Staff',
      theme: ThemeData(
        useMaterial3: true,
        colorSchemeSeed: Colors.indigo,
        scaffoldBackgroundColor: const Color(0xFFF4F6FA),
        appBarTheme: const AppBarTheme(
          backgroundColor: Color(0xFF0D47A1),
          foregroundColor: Colors.white,
          centerTitle: false,
        ),
      ),
      home: const AppBootstrap(),
    );
  }
}

class AppBootstrap extends StatefulWidget {
  const AppBootstrap({super.key});

  @override
  State<AppBootstrap> createState() => _AppBootstrapState();
}

class _AppBootstrapState extends State<AppBootstrap> {
  AppSession? _session;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    final AppSession? session = await AppSession.load();
    if (!mounted) return;
    setState(() {
      _session = session;
      _loading = false;
    });
  }

  Future<void> _onConfigured(AppSession session) async {
    setState(() => _session = session);
  }

  Future<void> _clearSetup() async {
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.clear();
    if (!mounted) return;
    setState(() => _session = null);
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (_session == null) {
      return SetupPage(onConfigured: _onConfigured);
    }
    return LoginPage(session: _session!, onResetSetup: _clearSetup);
  }
}

class AppSession {
  AppSession({
    required this.clientId,
    required this.clientName,
    required this.storeId,
    required this.storeName,
    required this.deviceUuid,
    required this.activationToken,
  });

  final int clientId;
  final String clientName;
  final int storeId;
  final String storeName;
  final String deviceUuid;
  final String activationToken;

  static Future<AppSession?> load() async {
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    final int? clientId = prefs.getInt('client_id');
    final int? storeId = prefs.getInt('store_id');
    final String? clientName = prefs.getString('client_name');
    final String? storeName = prefs.getString('store_name');
    final String? deviceUuid = prefs.getString('device_uuid');
    final String? activationToken = prefs.getString('activation_token');

    if (clientId == null ||
        storeId == null ||
        clientName == null ||
        storeName == null ||
        deviceUuid == null ||
        activationToken == null) {
      return null;
    }

    return AppSession(
      clientId: clientId,
      clientName: clientName,
      storeId: storeId,
      storeName: storeName,
      deviceUuid: deviceUuid,
      activationToken: activationToken,
    );
  }

  Future<void> save() async {
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.setInt('client_id', clientId);
    await prefs.setString('client_name', clientName);
    await prefs.setInt('store_id', storeId);
    await prefs.setString('store_name', storeName);
    await prefs.setString('device_uuid', deviceUuid);
    await prefs.setString('activation_token', activationToken);
  }
}

class SetupPage extends StatefulWidget {
  const SetupPage({super.key, required this.onConfigured});

  final ValueChanged<AppSession> onConfigured;

  @override
  State<SetupPage> createState() => _SetupPageState();
}

class _SetupPageState extends State<SetupPage> {
  final TextEditingController _companyCodeController = TextEditingController();
  final TextEditingController _setupKeyController = TextEditingController();

  bool _loadingStores = false;
  bool _activating = false;
  String? _error;
  Map<String, dynamic>? _client;
  List<Map<String, dynamic>> _stores = <Map<String, dynamic>>[];
  Map<String, dynamic>? _selectedStore;

  @override
  void dispose() {
    _companyCodeController.dispose();
    _setupKeyController.dispose();
    super.dispose();
  }

  Future<void> _loadStores() async {
    if (_companyCodeController.text.trim().isEmpty ||
        _setupKeyController.text.trim().isEmpty) {
      setState(() => _error = 'Enter company code and setup key');
      return;
    }

    setState(() {
      _loadingStores = true;
      _error = null;
      _client = null;
      _stores = <Map<String, dynamic>>[];
      _selectedStore = null;
    });

    try {
      final Uri uri = Uri.parse(kGetStoresUrl).replace(queryParameters: {
        'client_code': _companyCodeController.text.trim(),
        'setup_key': _setupKeyController.text.trim(),
      });
      final Map<String, dynamic> payload = await Api.getJson(uri);
      if (payload['success'] != true) {
        throw Exception(payload['error']?.toString() ?? 'Could not load stores');
      }
      final Object? storesRaw = payload['stores'];
      if (storesRaw is! List) throw Exception('Invalid stores response');
      setState(() {
        _client = _asMap(payload['client']);
        _stores = storesRaw.map((e) => _asMap(e)).toList();
        if (_stores.isNotEmpty) _selectedStore = _stores.first;
      });
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loadingStores = false);
    }
  }

  Future<void> _activateSelectedStore() async {
    if (_client == null || _selectedStore == null) return;
    setState(() {
      _activating = true;
      _error = null;
    });

    try {
      final SharedPreferences prefs = await SharedPreferences.getInstance();
      String? deviceUuid = prefs.getString('device_uuid');
      deviceUuid ??= const Uuid().v4();
      await prefs.setString('device_uuid', deviceUuid);

      final Map<String, dynamic> body = <String, dynamic>{
        'client_id': _toInt(_client!['id']),
        'store_id': _toInt(_selectedStore!['id']),
        'device_uuid': deviceUuid,
        'device_name': 'MerdPOS Staff App',
      };

      final Map<String, dynamic> payload = await Api.postJson(
        Uri.parse(kActivateDeviceUrl),
        body,
      );
      if (payload['success'] != true) {
        throw Exception(payload['error']?.toString() ?? 'Device activation failed');
      }
      final String token = payload['activation_token']?.toString() ?? '';
      if (token.isEmpty) throw Exception('Activation token missing');

      final AppSession session = AppSession(
        clientId: _toInt(_client!['id']),
        clientName: _client!['name']?.toString() ?? 'Client',
        storeId: _toInt(_selectedStore!['id']),
        storeName: _selectedStore!['store_name']?.toString() ?? 'Store',
        deviceUuid: deviceUuid,
        activationToken: token,
      );
      await session.save();
      if (!mounted) return;
      widget.onConfigured(session);
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _activating = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('MerdPOS Setup')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const _HeaderCard(
            title: 'Connect this device',
            subtitle: 'Enter company code and setup key, then select the store.',
            icon: Icons.storefront,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _companyCodeController,
            decoration: const InputDecoration(
              labelText: 'Company code',
              border: OutlineInputBorder(),
            ),
            textInputAction: TextInputAction.next,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _setupKeyController,
            decoration: const InputDecoration(
              labelText: 'Setup key',
              border: OutlineInputBorder(),
            ),
            obscureText: true,
            onSubmitted: (_) => _loadingStores ? null : _loadStores(),
          ),
          const SizedBox(height: 12),
          FilledButton.icon(
            onPressed: _loadingStores ? null : _loadStores,
            icon: _loadingStores
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.search),
            label: const Text('Load stores'),
          ),
          if (_stores.isNotEmpty) ...[
            const SizedBox(height: 16),
            DropdownButtonFormField<Map<String, dynamic>>(
              value: _selectedStore,
              decoration: const InputDecoration(
                labelText: 'Select store',
                border: OutlineInputBorder(),
              ),
              items: _stores.map((Map<String, dynamic> store) {
                final String name = store['store_name']?.toString() ?? 'Store';
                final String code = store['store_code']?.toString() ?? '';
                return DropdownMenuItem<Map<String, dynamic>>(
                  value: store,
                  child: Text(code.isEmpty ? name : '$name ($code)'),
                );
              }).toList(),
              onChanged: (value) => setState(() => _selectedStore = value),
            ),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: _activating ? null : _activateSelectedStore,
              icon: _activating
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.verified_user),
              label: const Text('Activate device'),
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            _ErrorCard(message: _error!),
          ],
        ],
      ),
    );
  }
}

class LoginPage extends StatefulWidget {
  const LoginPage({super.key, required this.session, required this.onResetSetup});

  final AppSession session;
  final Future<void> Function() onResetSetup;

  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final TextEditingController _userIdController = TextEditingController();
  final TextEditingController _passwordController = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Employee> _employees = <Employee>[];

  @override
  void initState() {
    super.initState();
    unawaited(_loadEmployees());
  }

  @override
  void dispose() {
    _userIdController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _loadEmployees() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final List<Employee> employees = await EmployeeService.loadEmployees(widget.session);
      setState(() => _employees = employees);
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _login() {
    final String userId = _userIdController.text.trim();
    final String password = _passwordController.text.trim();

    final Employee? employee = _findEmployee(_employees, userId, password);
    if (employee == null) {
      setState(() => _error = 'Invalid USER_ID or PASSWORD');
      return;
    }

    Navigator.of(context).pushReplacement(
      MaterialPageRoute(
        builder: (_) => HomePage(
          session: widget.session,
          primaryEmployee: employee,
          employees: _employees,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Staff Login'),
        actions: [
          PopupMenuButton<String>(
            onSelected: (String value) {
              if (value == 'reset') widget.onResetSetup();
              if (value == 'refresh') _loadEmployees();
            },
            itemBuilder: (BuildContext context) => const [
              PopupMenuItem(value: 'refresh', child: Text('Refresh employees')),
              PopupMenuItem(value: 'reset', child: Text('Reset setup')),
            ],
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _HeaderCard(
            title: widget.session.storeName,
            subtitle: '${widget.session.clientName} • ${_employees.length} active employees',
            icon: Icons.badge,
          ),
          const SizedBox(height: 12),
          if (_loading) const LinearProgressIndicator(),
          TextField(
            controller: _userIdController,
            decoration: const InputDecoration(
              labelText: 'USER_ID',
              border: OutlineInputBorder(),
            ),
            keyboardType: TextInputType.number,
            textInputAction: TextInputAction.next,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _passwordController,
            decoration: const InputDecoration(
              labelText: 'PASSWORD',
              border: OutlineInputBorder(),
            ),
            keyboardType: TextInputType.number,
            obscureText: true,
            onSubmitted: (_) => _login(),
          ),
          const SizedBox(height: 12),
          FilledButton.icon(
            onPressed: _loading ? null : _login,
            icon: const Icon(Icons.login),
            label: const Text('Login'),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            _ErrorCard(message: _error!),
          ],
        ],
      ),
    );
  }
}

class HomePage extends StatefulWidget {
  const HomePage({
    super.key,
    required this.session,
    required this.primaryEmployee,
    required this.employees,
  });

  final AppSession session;
  final Employee primaryEmployee;
  final List<Employee> employees;

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  Employee? _secondaryEmployee;
  bool _syncing = false;
  String? _message;
  String? _error;

  void _showInfo(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
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
      final String localLogId = const Uuid().v4();

      final Map<String, dynamic> log = <String, dynamic>{
        'employee_id': employee.id,
        'user_name': employee.fullName,
        'store_name': widget.session.storeName,
        'log_type': type,
        'log_date': logDate,
        'log_time': logTime,
        'log_datetime': '$logDate $logTime',
        'local_log_id': localLogId,
      };

      final Map<String, dynamic> payload = await Api.postJson(
        Uri.parse(kSyncEmployeeLogsUrl),
        <String, dynamic>{
          'client_id': widget.session.clientId,
          'store_id': widget.session.storeId,
          'store_name': widget.session.storeName,
          'device_uuid': widget.session.deviceUuid,
          'activation_token': widget.session.activationToken,
          'logs': [log],
        },
      );

      if (payload['success'] != true) {
        throw Exception(payload['error']?.toString() ?? 'Punch sync failed');
      }

      setState(() => _message = '${employee.fullName}: $type recorded at $logTime');
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _syncing = false);
    }
  }

  void _openTimesheet(Employee employee) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => TimesheetPage(session: widget.session, employee: employee),
      ),
    );
  }

  void _logoutPrimary() {
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(
        builder: (_) => LoginPage(session: widget.session, onResetSetup: () async {}),
      ),
    );
  }

  Future<void> _addSecondaryUser() async {
    if (_secondaryEmployee != null) {
      _showInfo('Maximum 2 users allowed at the same time.');
      return;
    }

    final Employee? employee = await showDialog<Employee>(
      context: context,
      builder: (BuildContext context) => SecondaryLoginDialog(
        employees: widget.employees,
        primaryEmployee: widget.primaryEmployee,
      ),
    );

    if (employee == null) return;
    setState(() => _secondaryEmployee = employee);
  }

  List<Widget> _dashboardTiles() {
    final bool secondaryActive = _secondaryEmployee != null;
    return <Widget>[
      DashboardTile(
        title: 'Add User',
        subtitle: secondaryActive ? 'Maximum 2 users active' : 'Add temporary second user',
        icon: Icons.person_add_alt_1,
        color: secondaryActive ? Colors.grey : Colors.indigo,
        onTap: secondaryActive
            ? () => _showInfo('Maximum 2 users allowed at the same time.')
            : _addSecondaryUser,
      ),
      DashboardTile(
        title: 'User Guides',
        subtitle: 'Help and staff guides',
        icon: Icons.menu_book,
        color: Colors.teal,
        onTap: () => _showInfo('User Guides coming soon.'),
      ),
      DashboardTile(
        title: 'Who Is Working',
        subtitle: 'Current staff view',
        icon: Icons.groups,
        color: Colors.deepPurple,
        onTap: () => _showInfo('Who Is Working coming soon.'),
      ),
      DashboardTile(
        title: 'Financials',
        subtitle: 'Store financial overview',
        icon: Icons.attach_money,
        color: Colors.green,
        onTap: () => _showInfo('Financials coming soon.'),
      ),
      DashboardTile(
        title: 'POS Coming Soon',
        subtitle: 'Future POS module',
        icon: Icons.point_of_sale,
        color: Colors.orange,
        onTap: () => _showInfo('POS Coming Soon.'),
      ),
      DashboardTile(
        title: 'Tobacco Order',
        subtitle: 'Order workflow',
        icon: Icons.inventory_2,
        color: Colors.brown,
        onTap: () => _showInfo('Tobacco Order coming soon.'),
      ),
      DashboardTile(
        title: 'Inventory Report',
        subtitle: 'Stock and variance',
        icon: Icons.assessment,
        color: Colors.blueGrey,
        onTap: () => _showInfo('Inventory Report coming soon.'),
      ),
      DashboardTile(
        title: 'API Timesheet v2',
        subtitle: 'Open primary user timesheet',
        icon: Icons.table_chart,
        color: Colors.blue,
        onTap: () => _openTimesheet(widget.primaryEmployee),
      ),
      DashboardTile(
        title: 'Punch IN',
        subtitle: 'Primary user only',
        icon: Icons.login,
        color: Colors.lightGreen,
        onTap: _syncing ? null : () => _punch(widget.primaryEmployee, 'IN'),
      ),
      DashboardTile(
        title: 'Punch OUT',
        subtitle: 'Primary user only',
        icon: Icons.logout,
        color: Colors.redAccent,
        onTap: _syncing ? null : () => _punch(widget.primaryEmployee, 'OUT'),
      ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final Employee primary = widget.primaryEmployee;
    final Employee? secondary = _secondaryEmployee;

    return Scaffold(
      appBar: AppBar(
        title: Text('${widget.session.storeName} - Main Menu'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(14),
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                UserBadge(
                  labelPrefix: 'Primary',
                  employee: primary,
                  onTimesheet: () => _openTimesheet(primary),
                  onChangePassword: () => _showInfo('Change Password coming soon.'),
                  onLogOff: _logoutPrimary,
                ),
                if (secondary != null)
                  UserBadge.secondary(
                    employee: secondary,
                    onTimesheet: () => _openTimesheet(secondary),
                    onRemove: () => setState(() => _secondaryEmployee = null),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          if (_syncing) const LinearProgressIndicator(),
          if (_message != null) ...[
            const SizedBox(height: 8),
            _InfoCard(message: _message!),
          ],
          if (_error != null) ...[
            const SizedBox(height: 8),
            _ErrorCard(message: _error!),
          ],
          const SizedBox(height: 12),
          LayoutBuilder(
            builder: (BuildContext context, BoxConstraints constraints) {
              final double width = constraints.maxWidth;
              final int columns = width >= 900 ? 4 : width >= 620 ? 3 : 2;
              final double tileWidth =
                  (width - (12 * (columns - 1))) / columns;
              return Wrap(
                spacing: 12,
                runSpacing: 12,
                children: _dashboardTiles()
                    .map((Widget tile) => SizedBox(width: tileWidth, child: tile))
                    .toList(),
              );
            },
          ),
        ],
      ),
    );
  }
}

class SecondaryLoginDialog extends StatefulWidget {
  const SecondaryLoginDialog({
    super.key,
    required this.employees,
    required this.primaryEmployee,
  });

  final List<Employee> employees;
  final Employee primaryEmployee;

  @override
  State<SecondaryLoginDialog> createState() => _SecondaryLoginDialogState();
}

class _SecondaryLoginDialogState extends State<SecondaryLoginDialog> {
  final TextEditingController _userIdController = TextEditingController();
  final TextEditingController _passwordController = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _userIdController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  void _login() {
    final Employee? employee = _findEmployee(
      widget.employees,
      _userIdController.text.trim(),
      _passwordController.text.trim(),
    );
    if (employee == null) {
      setState(() => _error = 'Invalid USER_ID or PASSWORD');
      return;
    }
    if (employee.id == widget.primaryEmployee.id) {
      setState(() => _error = 'This user is already the primary user.');
      return;
    }
    Navigator.of(context).pop(employee);
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Add Secondary User'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: _userIdController,
              decoration: const InputDecoration(
                labelText: 'USER_ID',
                border: OutlineInputBorder(),
              ),
              keyboardType: TextInputType.number,
              textInputAction: TextInputAction.next,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _passwordController,
              decoration: const InputDecoration(
                labelText: 'PASSWORD',
                border: OutlineInputBorder(),
              ),
              keyboardType: TextInputType.number,
              obscureText: true,
              onSubmitted: (_) => _login(),
            ),
            if (_error != null) ...[
              const SizedBox(height: 10),
              Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ],
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(onPressed: _login, child: const Text('Add User')),
      ],
    );
  }
}

class UserBadge extends StatelessWidget {
  const UserBadge({
    super.key,
    required this.labelPrefix,
    required this.employee,
    required this.onTimesheet,
    required this.onChangePassword,
    required this.onLogOff,
  })  : isSecondary = false,
        onRemove = null;

  const UserBadge.secondary({
    super.key,
    required this.employee,
    required this.onTimesheet,
    required this.onRemove,
  })  : labelPrefix = 'Secondary',
        isSecondary = true,
        onChangePassword = null,
        onLogOff = null;

  final String labelPrefix;
  final Employee employee;
  final bool isSecondary;
  final VoidCallback onTimesheet;
  final VoidCallback? onChangePassword;
  final VoidCallback? onLogOff;
  final VoidCallback? onRemove;

  @override
  Widget build(BuildContext context) {
    return PopupMenuButton<String>(
      tooltip: employee.fullName,
      onSelected: (String value) {
        if (value == 'timesheet') onTimesheet();
        if (value == 'password') onChangePassword?.call();
        if (value == 'logout') onLogOff?.call();
        if (value == 'remove') onRemove?.call();
      },
      itemBuilder: (BuildContext context) {
        if (isSecondary) {
          return <PopupMenuEntry<String>>[
            PopupMenuItem<String>(
              enabled: false,
              child: Text('${employee.fullName} / ${employee.roleName}'),
            ),
            const PopupMenuDivider(),
            const PopupMenuItem<String>(value: 'timesheet', child: Text('Time Sheet')),
            const PopupMenuItem<String>(
              value: 'remove',
              child: Text('Remove Secondary User'),
            ),
          ];
        }
        return <PopupMenuEntry<String>>[
          PopupMenuItem<String>(
            enabled: false,
            child: Text('${employee.fullName} / ${employee.roleName}'),
          ),
          const PopupMenuDivider(),
          const PopupMenuItem<String>(value: 'timesheet', child: Text('Time Sheet')),
          const PopupMenuItem<String>(value: 'password', child: Text('Change Password')),
          const PopupMenuItem<String>(value: 'logout', child: Text('Log Off')),
        ];
      },
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          color: isSecondary ? const Color(0xFFE3F2FD) : const Color(0xFFE8EAF6),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: isSecondary ? Colors.blue : Colors.indigo),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(isSecondary ? Icons.person_outline : Icons.person, size: 18),
            const SizedBox(width: 6),
            Text(
              employee.shortName,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
            const SizedBox(width: 4),
            const Icon(Icons.arrow_drop_down, size: 18),
          ],
        ),
      ),
    );
  }
}

class DashboardTile extends StatelessWidget {
  const DashboardTile({
    super.key,
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.color,
    required this.onTap,
  });

  final String title;
  final String subtitle;
  final IconData icon;
  final Color color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Ink(
        height: 128,
        decoration: BoxDecoration(
          color: color,
          borderRadius: BorderRadius.circular(16),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.12),
              blurRadius: 6,
              offset: const Offset(0, 3),
            ),
          ],
        ),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Icon(icon, color: Colors.white, size: 32),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 17,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    subtitle,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: Colors.white70, fontSize: 12),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class TimesheetPage extends StatefulWidget {
  const TimesheetPage({super.key, required this.session, required this.employee});

  final AppSession session;
  final Employee employee;

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
    _weekStart = DateTime(now.year, now.month, now.day)
        .subtract(Duration(days: now.weekday - DateTime.monday));
    _weekEnd = _weekStart.add(const Duration(days: 6));
    unawaited(_loadTimesheet());
  }

  Future<void> _loadTimesheet() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final Uri uri = Uri.parse(kTimesheetApiUrl).replace(queryParameters: {
        'client_id': widget.session.clientId.toString(),
        'store_id': widget.session.storeId.toString(),
        'employee_id': widget.employee.id.toString(),
        'week_start': _dateOnly(_weekStart),
        'week_end': _dateOnly(_weekEnd),
      });

      final Map<String, dynamic> decoded = await Api.getJson(uri);
      if (decoded['success'] == false) {
        throw Exception(decoded['error']?.toString() ?? 'API returned success=false');
      }

      final List<TimesheetRow> parsedRows = TimesheetParser.parse(decoded);

      setState(() {
        _payload = decoded;
        _rows = parsedRows;
      });
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
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
      appBar: AppBar(title: Text('API Timesheet v2 - ${widget.employee.shortName}')),
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
              _ApiStatusCard(payload: _payload!, rowCount: _rows.length),
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

class EmployeeService {
  static Future<List<Employee>> loadEmployees(AppSession session) async {
    final Uri uri = Uri.parse(kGetEmployeesUrl).replace(queryParameters: {
      'client_id': session.clientId.toString(),
      'store_id': session.storeId.toString(),
      'activation_token': session.activationToken,
    });
    final Map<String, dynamic> payload = await Api.getJson(uri);
    if (payload['success'] != true) {
      throw Exception(payload['error']?.toString() ?? 'Could not load employees');
    }
    final Object? raw = payload['employees'];
    if (raw is! List) throw Exception('Invalid employees response');
    return raw.map((e) => Employee.fromMap(_asMap(e))).toList();
  }
}

class Employee {
  const Employee({
    required this.id,
    required this.fullName,
    required this.userId,
    required this.loginPassword,
    required this.roleName,
    required this.hourlyRate,
  });

  final int id;
  final String fullName;
  final String userId;
  final String loginPassword;
  final String roleName;
  final String hourlyRate;

  String get shortName {
    final String trimmed = fullName.trim();
    if (trimmed.isEmpty) return 'User';
    return trimmed.split(RegExp(r'\s+')).first;
  }

  factory Employee.fromMap(Map<String, dynamic> map) {
    return Employee(
      id: _toInt(map['id']),
      fullName: map['full_name']?.toString() ?? 'Employee',
      userId: map['user_id']?.toString() ?? '',
      loginPassword: map['login_password']?.toString() ?? '',
      roleName: map['role_name']?.toString() ?? map['employee_type']?.toString() ?? 'Staff',
      hourlyRate: map['hourly_rate']?.toString() ?? '',
    );
  }
}

Employee? _findEmployee(List<Employee> employees, String userId, String password) {
  for (final Employee employee in employees) {
    if (employee.userId == userId && employee.loginPassword == password) {
      return employee;
    }
  }
  return null;
}

class TimesheetParser {
  static List<TimesheetRow> parse(Map<String, dynamic> payload) {
    final List<TimesheetRow> result = <TimesheetRow>[];

    void addMap(Map<String, dynamic> map, {String? inheritedName}) {
      final TimesheetRow row = TimesheetRow.fromMap(map, inheritedName: inheritedName);
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
        final Map<String, dynamic> lower = _lowerKeyMap(map);
        final String? name = _firstString(lower, const [
              'employee_name',
              'user_name',
              'staff_name',
              'cashier_name',
              'employee',
              'staff',
              'name',
            ]) ??
            inheritedName;

        if (_looksLikeTimesheetRow(map)) addMap(map, inheritedName: inheritedName);

        for (final String key in const [
          'detailed_rows',
          'employee_wise_detailed_report',
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
          if (lower.containsKey(key)) walk(lower[key], inheritedName: name);
        }
      }
    }

    walk(payload);

    final Set<String> seen = <String>{};
    return result.where((TimesheetRow row) {
      final String key =
          '${row.employee}|${row.store}|${row.date}|${row.clockIn}|${row.clockOut}|${row.payableHours}|${row.totalWage}';
      if (seen.contains(key)) return false;
      seen.add(key);
      return true;
    }).toList();
  }

  static bool _looksLikeTimesheetRow(Map<String, dynamic> map) {
    final Set<String> keys = map.keys.map((String k) => k.toLowerCase()).toSet();
    const List<String> indicators = [
      'actual_in_time',
      'actual_out_time',
      'rounded_in_time',
      'rounded_out_time',
      'in_date',
      'out_date',
      'clock_in',
      'clock_out',
      'time_in',
      'time_out',
      'shift_start',
      'shift_end',
      'payable_hours',
      'total_payable_hours',
      'total_hours',
      'counted_hours',
      'total_counted_hours',
      'hours',
      'duration_minutes',
      'hourly_rate',
      'wage',
      'total_wage',
      'needs_review',
    ];
    return indicators.any(keys.contains);
  }

  static Map<String, dynamic> _lowerKeyMap(Map<String, dynamic> map) {
    final Map<String, dynamic> lowered = <String, dynamic>{};
    for (final MapEntry<String, dynamic> entry in map.entries) {
      lowered[entry.key.toLowerCase()] = entry.value;
    }
    return lowered;
  }

  static String? _firstString(Map<String, dynamic> map, List<String> keys) {
    for (final String key in keys) {
      final Object? value = map[key.toLowerCase()];
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
    required this.store,
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
  final String store;
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
      store != '-' ||
      date != '-' ||
      clockIn != '-' ||
      clockOut != '-' ||
      rawHours != '-' ||
      payableHours != '-' ||
      totalWage != '-';

  factory TimesheetRow.fromMap(Map<String, dynamic> map, {String? inheritedName}) {
    final Map<String, dynamic> lower = <String, dynamic>{};
    for (final MapEntry<String, dynamic> entry in map.entries) {
      lower[entry.key.toLowerCase()] = entry.value;
    }

    String first(List<String> keys, {String fallback = '-'}) {
      for (final String key in keys) {
        final Object? value = lower[key.toLowerCase()];
        if (value != null) {
          final String text = value.toString().trim();
          if (text.isNotEmpty && text.toLowerCase() != 'null') return text;
        }
      }
      return fallback;
    }

    final String inDate = first(const [
      'in_date',
      'date',
      'shift_date',
      'work_date',
      'day',
      'log_date',
    ]);
    final String outDate = first(const ['out_date'], fallback: inDate);
    final String displayDate = outDate != '-' && outDate != inDate ? '$inDate → $outDate' : inDate;

    final String actualIn = first(const [
      'actual_in_time',
      'clock_in',
      'time_in',
      'start_time',
      'shift_start',
      'in_time',
    ]);
    final String roundedIn = first(const ['rounded_in_time']);
    final String actualOut = first(const [
      'actual_out_time',
      'clock_out',
      'time_out',
      'end_time',
      'shift_end',
      'out_time',
    ]);
    final String roundedOut = first(const ['rounded_out_time']);

    String withRounded(String actual, String rounded) {
      if (actual == '-') return rounded;
      if (rounded == '-' || rounded == actual) return actual;
      return '$actual  →  $rounded';
    }

    final String note = first(const [
      'note',
      'notes',
      'reason',
      'review_reason',
      'exception',
    ], fallback: '');

    return TimesheetRow(
      employee: first(const [
        'employee_name',
        'user_name',
        'staff_name',
        'cashier_name',
        'employee',
        'staff',
        'name',
      ], fallback: inheritedName ?? '-'),
      store: first(const ['store_name', 'store', 'shop_name', 'location']),
      date: displayDate,
      clockIn: withRounded(actualIn, roundedIn),
      clockOut: withRounded(actualOut, roundedOut),
      rawHours: first(const ['raw_hours', 'actual_hours', 'duration_hours', 'hours']),
      payableHours: first(const [
        'total_hours',
        'payable_hours',
        'total_payable_hours',
        'counted_hours',
        'total_counted_hours',
        'rounded_hours',
      ]),
      totalWage: first(const ['wage', 'total_wage', 'pay', 'amount', 'payable_amount']),
      status: note.toLowerCase().contains('long shift')
          ? 'Needs review'
          : first(const ['status', 'review_status', 'action'], fallback: 'OK'),
      notes: note,
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
            IconButton(onPressed: onPrevious, icon: const Icon(Icons.chevron_left)),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  const Text('Payroll week', style: TextStyle(fontWeight: FontWeight.w600)),
                  const SizedBox(height: 4),
                  Text('${_dateOnly(start)} to ${_dateOnly(end)}'),
                ],
              ),
            ),
            IconButton(onPressed: onNext, icon: const Icon(Icons.chevron_right)),
          ],
        ),
      ),
    );
  }
}

class _ApiStatusCard extends StatelessWidget {
  const _ApiStatusCard({required this.payload, required this.rowCount});

  final Map<String, dynamic> payload;
  final int rowCount;

  @override
  Widget build(BuildContext context) {
    final bool success = payload['success'] == true;
    final String api = payload['api']?.toString() ?? 'get_timesheet.php';
    final String version = payload['version']?.toString() ?? 'unknown';

    return Card(
      child: ListTile(
        leading: Icon(success ? Icons.check_circle : Icons.warning_amber_rounded),
        title: Text('$api  •  $version'),
        subtitle: Text(
          success ? 'API connected • $rowCount shift rows loaded' : (payload['error']?.toString() ?? 'API returned success=false'),
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
      _Metric('Employees', _pick(summary, const ['employee_count', 'employees'])),
      _Metric('Stores', _pick(summary, const ['store_count'])),
      _Metric('Shifts', _pick(summary, const ['shift_count'])),
      _Metric('Payable hrs', _pick(summary, const ['total_payable_hours', 'payable_hours'])),
      _Metric('Total wage', _pick(summary, const ['total_wage', 'wage'])),
      _Metric('Warnings', _pick(summary, const ['warning_count'])),
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
      if (value != null && value.toString().trim().isNotEmpty) return value.toString();
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
        row.notes.toLowerCase().contains('review') ||
        row.notes.toLowerCase().contains('long shift');

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(row.employee, style: Theme.of(context).textTheme.titleMedium)),
                Chip(
                  label: Text(review ? 'Needs review' : row.status),
                  avatar: Icon(review ? Icons.flag : Icons.check, size: 18),
                ),
              ],
            ),
            const SizedBox(height: 8),
            _kv('Store', row.store),
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
            child: Text(key, style: const TextStyle(fontWeight: FontWeight.w600)),
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

class _HeaderCard extends StatelessWidget {
  const _HeaderCard({required this.title, required this.subtitle, required this.icon});

  final String title;
  final String subtitle;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          children: [
            Icon(icon, size: 34),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 4),
                  Text(subtitle),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Card(
      color: Theme.of(context).colorScheme.primaryContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: SelectableText(message),
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

class Api {
  static Future<Map<String, dynamic>> getJson(Uri uri) async {
    final http.Response response = await http.get(uri).timeout(const Duration(seconds: 25));
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw Exception('HTTP ${response.statusCode}: ${response.body}');
    }
    final Object? decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic>) {
      throw Exception('API did not return a JSON object.');
    }
    return decoded;
  }

  static Future<Map<String, dynamic>> postJson(Uri uri, Map<String, dynamic> body) async {
    final http.Response response = await http
        .post(uri, headers: const {'Content-Type': 'application/json'}, body: jsonEncode(body))
        .timeout(const Duration(seconds: 25));
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw Exception('HTTP ${response.statusCode}: ${response.body}');
    }
    final Object? decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic>) {
      throw Exception('API did not return a JSON object.');
    }
    return decoded;
  }
}

Map<String, dynamic> _asMap(Object? value) {
  if (value is Map) {
    return value.map((key, val) => MapEntry(key.toString(), val));
  }
  return <String, dynamic>{};
}

int _toInt(Object? value) {
  if (value is int) return value;
  return int.tryParse(value?.toString() ?? '') ?? 0;
}

String _dateOnly(DateTime date) {
  String two(int n) => n.toString().padLeft(2, '0');
  return '${date.year}-${two(date.month)}-${two(date.day)}';
}

String _timeOnly(DateTime date) {
  String two(int n) => n.toString().padLeft(2, '0');
  return '${two(date.hour)}:${two(date.minute)}:${two(date.second)}';
}
