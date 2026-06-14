import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

// MERDPOS / POS LATEST - API Timesheet v2 + POS-style staff dashboard v7
// Setup -> Store selection -> Device activation -> Numeric login -> Main Menu
// Main Menu supports persistent primary login + one temporary secondary user.
// Dashboard UI follows the older POS app pattern: dark left rail, user session button, and clean workspace.
const String kApiBaseUrl = 'https://app.merdpos.com/api';
const String kGetStoresUrl = '$kApiBaseUrl/get_stores.php';
const String kActivateDeviceUrl = '$kApiBaseUrl/activate_device.php';
const String kGetEmployeesUrl = '$kApiBaseUrl/get_employees.php';
const String kSyncEmployeeLogsUrl = '$kApiBaseUrl/sync_employee_logs.php';
const String kChangePasswordUrl = '$kApiBaseUrl/change_password.php';
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
      if (!mounted) return;
      setState(() => _employees = employees);

      final int? savedPrimaryId = await PrimaryLoginStore.loadPrimaryEmployeeId();
      if (savedPrimaryId != null && mounted) {
        final Employee? savedPrimary = _findEmployeeById(employees, savedPrimaryId);
        if (savedPrimary != null) {
          _openHome(savedPrimary);
          return;
        }
        await PrimaryLoginStore.clear();
      }
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _login() async {
    final String userId = _userIdController.text.trim();
    final String password = _passwordController.text.trim();

    final Employee? employee = _findEmployee(_employees, userId, password);
    if (employee == null) {
      setState(() => _error = 'Invalid USER_ID or PASSWORD');
      return;
    }

    await PrimaryLoginStore.save(employee);
    if (!mounted) return;
    _openHome(employee);
  }

  void _openHome(Employee employee) {
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
  late Employee _primaryEmployee;
  Employee? _secondaryEmployee;
  bool _syncing = false;
  String? _message;
  String? _error;

  @override
  void initState() {
    super.initState();
    _primaryEmployee = widget.primaryEmployee;
  }

  void _showInfo(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _showChangePasswordDialog(Employee employee) async {
    await showDialog<void>(
      context: context,
      builder: (BuildContext dialogContext) => ChangePasswordDialog(
        session: widget.session,
        employee: employee,
      ),
    );
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
        builder: (_) => TimesheetPage(
          session: widget.session,
          employee: employee,
          primary: _primaryEmployee,
          secondary: _secondaryEmployee,
          onPrimaryChangePassword: () => _showChangePasswordDialog(_primaryEmployee),
          onPrimaryLogOff: _logoutPrimary,
          onAddUser: () async {
            final bool added = await _addSecondaryUser();
            if (added && mounted && Navigator.of(context).canPop()) {
              Navigator.of(context).pop();
            }
          },
          onSecondaryLogOff: _logOffSecondary,
        ),
      ),
    );
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
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(
        builder: (_) => LoginPage(session: widget.session, onResetSetup: () async {}),
      ),
    );
  }

  Future<bool> _addSecondaryUser() async {
    if (_secondaryEmployee != null) {
      _showInfo('Maximum 2 users allowed at the same time.');
      return false;
    }

    final Employee? employee = await showDialog<Employee>(
      context: context,
      builder: (BuildContext context) => SecondaryLoginDialog(
        employees: widget.employees,
        primaryEmployee: _primaryEmployee,
      ),
    );

    if (employee == null) return false;
    setState(() => _secondaryEmployee = employee);
    return true;
  }

  void _logOffSecondary() {
    setState(() => _secondaryEmployee = null);
  }

  @override
  Widget build(BuildContext context) {
    final Employee primary = _primaryEmployee;
    final Employee? secondary = _secondaryEmployee;

    return Scaffold(
      body: Row(
        children: [
          _PosSideRail(
            primary: primary,
            secondary: secondary,
            onPrimaryTimesheet: () => _openTimesheet(primary),
            onPrimaryChangePassword: () => _showChangePasswordDialog(_primaryEmployee),
            onPrimaryLogOff: _logoutPrimary,
            onAddUser: () async { await _addSecondaryUser(); },
            onSecondaryLogOff: _logOffSecondary,
            onWhoIsWorking: () => _showInfo('Who Is Working coming soon.'),
            onPos: () => _showInfo('POS module coming soon.'),
            onFinancials: () => _showInfo('Financials coming soon.'),
            onInventory: () => _showInfo('Inventory coming soon.'),
            onSync: _syncing ? null : () => _punch(primary, 'IN'),
            onSettings: () => _showInfo('Settings coming soon.'),
          ),
          Expanded(
            child: SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(22),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                widget.session.storeName,
                                style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                                      fontWeight: FontWeight.w900,
                                      color: const Color(0xFF0D1B3E),
                                    ),
                              ),
                              const SizedBox(height: 3),
                              Text(
                                'API Timesheet v2',
                                style: Theme.of(context).textTheme.labelLarge?.copyWith(
                                      color: const Color(0xFF1565C0),
                                      fontWeight: FontWeight.w800,
                                    ),
                              ),
                            ],
                          ),
                        ),
                        Text(
                          _timeOnly(DateTime.now()).substring(0, 5),
                          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                                color: Colors.black54,
                                fontWeight: FontWeight.w800,
                              ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    if (_syncing) const LinearProgressIndicator(),
                    if (_message != null) ...[
                      const SizedBox(height: 8),
                      _InfoCard(message: _message!),
                    ],
                    if (_error != null) ...[
                      const SizedBox(height: 8),
                      _ErrorCard(message: _error!),
                    ],
                    const Spacer(),
                    Center(
                      child: Text(
                        'Select a module from the sidebar.',
                        style: Theme.of(context).textTheme.titleMedium?.copyWith(
                              color: Colors.black45,
                              fontWeight: FontWeight.w600,
                            ),
                      ),
                    ),
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


class ChangePasswordDialog extends StatefulWidget {
  const ChangePasswordDialog({
    super.key,
    required this.session,
    required this.employee,
  });

  final AppSession session;
  final Employee employee;

  @override
  State<ChangePasswordDialog> createState() => _ChangePasswordDialogState();
}

class _ChangePasswordDialogState extends State<ChangePasswordDialog> {
  final TextEditingController _oldPasswordController = TextEditingController();
  final TextEditingController _newPasswordController = TextEditingController();
  final TextEditingController _confirmPasswordController = TextEditingController();
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _oldPasswordController.dispose();
    _newPasswordController.dispose();
    _confirmPasswordController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final String oldPassword = _oldPasswordController.text.trim();
    final String newPassword = _newPasswordController.text.trim();
    final String confirmPassword = _confirmPasswordController.text.trim();

    if (oldPassword.isEmpty || newPassword.isEmpty || confirmPassword.isEmpty) {
      setState(() => _error = 'Enter old password, new password, and confirmation.');
      return;
    }
    if (!RegExp(r'^\d+$').hasMatch(newPassword)) {
      setState(() => _error = 'New password must be numeric.');
      return;
    }
    if (newPassword.length < 4) {
      setState(() => _error = 'New password must be at least 4 digits.');
      return;
    }
    if (newPassword != confirmPassword) {
      setState(() => _error = 'New password and confirmation do not match.');
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    try {
      final Map<String, dynamic> payload = await Api.postJson(
        Uri.parse(kChangePasswordUrl),
        <String, dynamic>{
          'client_id': widget.session.clientId,
          'store_id': widget.session.storeId,
          'device_uuid': widget.session.deviceUuid,
          'activation_token': widget.session.activationToken,
          'employee_id': widget.employee.id,
          'old_password': oldPassword,
          'new_password': newPassword,
        },
      );

      if (payload['success'] != true) {
        throw Exception(payload['error']?.toString() ?? 'Password change failed');
      }

      if (!mounted) return;
      Navigator.of(context).pop();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Password changed successfully. Use the new password next login.')),
      );
    } catch (e) {
      if (mounted) setState(() => _error = e.toString().replaceFirst('Exception: ', ''));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text('Change password - ${widget.employee.shortName}'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: _oldPasswordController,
              decoration: const InputDecoration(labelText: 'Current password', border: OutlineInputBorder()),
              keyboardType: TextInputType.number,
              obscureText: true,
              enabled: !_saving,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _newPasswordController,
              decoration: const InputDecoration(labelText: 'New numeric password', border: OutlineInputBorder()),
              keyboardType: TextInputType.number,
              obscureText: true,
              enabled: !_saving,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _confirmPasswordController,
              decoration: const InputDecoration(labelText: 'Confirm new password', border: OutlineInputBorder()),
              keyboardType: TextInputType.number,
              obscureText: true,
              enabled: !_saving,
              onSubmitted: (_) => _saving ? null : _save(),
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
          onPressed: _saving ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _saving ? null : _save,
          child: _saving
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : const Text('Change Password'),
        ),
      ],
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
    this.onAddUser,
    this.isSecondary = false,
  });

  final String labelPrefix;
  final Employee employee;
  final bool isSecondary;
  final VoidCallback onTimesheet;
  final VoidCallback onChangePassword;
  final VoidCallback onLogOff;
  final VoidCallback? onAddUser;

  @override
  Widget build(BuildContext context) {
    return PopupMenuButton<String>(
      tooltip: employee.fullName,
      onSelected: (String value) {
        if (value == 'timesheet') onTimesheet();
        if (value == 'password') onChangePassword();
        if (value == 'logout') onLogOff();
        if (value == 'add_user') onAddUser?.call();
      },
      itemBuilder: (BuildContext context) {
        final List<PopupMenuEntry<String>> items = <PopupMenuEntry<String>>[
          PopupMenuItem<String>(
            enabled: false,
            child: Text('${employee.fullName} / ${employee.roleName}'),
          ),
          const PopupMenuDivider(),
          const PopupMenuItem<String>(value: 'timesheet', child: Text('Time Sheet')),
          const PopupMenuItem<String>(value: 'password', child: Text('Change Password')),
          PopupMenuItem<String>(
            value: 'logout',
            child: Text(isSecondary ? 'Log Off Secondary User' : 'Log Off'),
          ),
        ];
        if (!isSecondary && onAddUser != null) {
          items.add(const PopupMenuDivider());
          items.add(const PopupMenuItem<String>(value: 'add_user', child: Text('Add User')));
        }
        return items;
      },
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          color: isSecondary ? const Color(0xFFE3F2FD) : const Color(0xFFE8EAF6),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: isSecondary ? Colors.blue : Colors.indigo),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircleAvatar(
              radius: 13,
              backgroundColor: isSecondary ? Colors.blue : Colors.indigo,
              child: Text(
                employee.shortName.substring(0, 1).toUpperCase(),
                style: const TextStyle(color: Colors.white, fontSize: 12),
              ),
            ),
            const SizedBox(width: 7),
            Text(
              employee.shortName,
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            const SizedBox(width: 4),
            const Icon(Icons.arrow_drop_down, size: 18),
          ],
        ),
      ),
    );
  }
}

class _PosSideRail extends StatelessWidget {
  const _PosSideRail({
    required this.primary,
    required this.secondary,
    required this.onPrimaryTimesheet,
    required this.onPrimaryChangePassword,
    required this.onPrimaryLogOff,
    required this.onAddUser,
    required this.onSecondaryLogOff,
    required this.onWhoIsWorking,
    required this.onPos,
    required this.onFinancials,
    required this.onInventory,
    required this.onSync,
    required this.onSettings,
  });

  final Employee primary;
  final Employee? secondary;
  final VoidCallback onPrimaryTimesheet;
  final VoidCallback onPrimaryChangePassword;
  final Future<void> Function() onPrimaryLogOff;
  final Future<void> Function() onAddUser;
  final VoidCallback onSecondaryLogOff;
  final VoidCallback onWhoIsWorking;
  final VoidCallback onPos;
  final VoidCallback onFinancials;
  final VoidCallback onInventory;
  final VoidCallback? onSync;
  final VoidCallback onSettings;

  @override
  Widget build(BuildContext context) {
    final bool hasSecondary = secondary != null;
    return Container(
      width: 76,
      color: const Color(0xFF06133A),
      child: SafeArea(
        child: Column(
          children: [
            const SizedBox(height: 10),
            PopupMenuButton<String>(
              tooltip: 'User menu',
              onSelected: (String value) async {
                if (value == 'timesheet') onPrimaryTimesheet();
                if (value == 'password') onPrimaryChangePassword();
                if (value == 'logout_primary') await onPrimaryLogOff();
                if (value == 'add_user') await onAddUser();
                if (value == 'logout_secondary') onSecondaryLogOff();
              },
              itemBuilder: (BuildContext context) {
                if (hasSecondary) {
                  return <PopupMenuEntry<String>>[
                    PopupMenuItem<String>(
                      enabled: false,
                      child: Text('${primary.fullName} / ${primary.roleName}'),
                    ),
                    PopupMenuItem<String>(
                      value: 'logout_primary',
                      child: Text('${primary.shortName} Log off'),
                    ),
                    const PopupMenuDivider(),
                    PopupMenuItem<String>(
                      enabled: false,
                      child: Text('${secondary!.fullName} / ${secondary!.roleName}'),
                    ),
                    PopupMenuItem<String>(
                      value: 'logout_secondary',
                      child: Text('${secondary!.shortName} Log off'),
                    ),
                  ];
                }
                return const <PopupMenuEntry<String>>[
                  PopupMenuItem<String>(value: 'timesheet', child: Text('Time Sheet')),
                  PopupMenuItem<String>(value: 'password', child: Text('Change Password')),
                  PopupMenuItem<String>(value: 'logout_primary', child: Text('Log off')),
                  PopupMenuDivider(),
                  PopupMenuItem<String>(value: 'add_user', child: Text('Add User')),
                ];
              },
              child: _UserSessionAvatar(primary: primary, secondary: secondary),
            ),
            const SizedBox(height: 8),
            Text(
              hasSecondary ? '2 users' : primary.shortName,
              textAlign: TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: Colors.white70, fontSize: 10),
            ),
            const SizedBox(height: 20),
            _RailItem(
              icon: Icons.groups,
              label: 'Who Is Working',
              onTap: onWhoIsWorking,
            ),
            _RailItem(
              icon: Icons.point_of_sale,
              label: 'POS',
              selected: true,
              onTap: onPos,
            ),
            _RailItem(
              icon: Icons.attach_money,
              label: 'Financials',
              onTap: onFinancials,
            ),
            _RailItem(
              icon: Icons.assessment,
              label: 'Inventory',
              onTap: onInventory,
            ),
            const Spacer(),
            _RailItem(icon: Icons.cloud_sync, label: 'Sync', onTap: onSync),
            _RailItem(icon: Icons.settings, label: 'Settings', onTap: onSettings),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }
}

class _UserSessionAvatar extends StatelessWidget {
  const _UserSessionAvatar({required this.primary, required this.secondary});

  final Employee primary;
  final Employee? secondary;

  @override
  Widget build(BuildContext context) {
    final String p = primary.shortName.substring(0, 1).toUpperCase();
    final String? s = secondary?.shortName.substring(0, 1).toUpperCase();
    if (s == null) {
      return CircleAvatar(
        radius: 25,
        backgroundColor: const Color(0xFFE91E63),
        child: Text(
          p,
          style: const TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.w900,
            fontSize: 18,
          ),
        ),
      );
    }

    return ClipOval(
      child: SizedBox(
        width: 50,
        height: 50,
        child: Row(
          children: [
            Expanded(
              child: Container(
                color: const Color(0xFFE91E63),
                alignment: Alignment.center,
                child: Text(
                  p,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w900,
                    fontSize: 16,
                  ),
                ),
              ),
            ),
            Expanded(
              child: Container(
                color: const Color(0xFF1565C0),
                alignment: Alignment.center,
                child: Text(
                  s,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w900,
                    fontSize: 16,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _RailItem extends StatelessWidget {
  const _RailItem({
    required this.icon,
    required this.label,
    this.selected = false,
    this.onTap,
  });

  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final Color fg = onTap == null ? Colors.white38 : Colors.white;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        width: 62,
        margin: const EdgeInsets.symmetric(vertical: 4),
        padding: const EdgeInsets.symmetric(vertical: 8),
        decoration: BoxDecoration(
          color: selected ? const Color(0xFF1565C0) : Colors.transparent,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          children: [
            Icon(icon, color: fg, size: 20),
            const SizedBox(height: 3),
            Text(
              label,
              textAlign: TextAlign.center,
              style: TextStyle(color: onTap == null ? Colors.white38 : Colors.white70, fontSize: 9),
            ),
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
      borderRadius: BorderRadius.circular(18),
      child: Ink(
        height: 142,
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: const Color(0xFFE3E7EF)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.07),
              blurRadius: 10,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              CircleAvatar(
                radius: 30,
                backgroundColor: color.withOpacity(0.16),
                child: Icon(icon, color: color, size: 31),
              ),
              const SizedBox(height: 10),
              Text(
                title,
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  color: Color(0xFF1F2937),
                  fontSize: 14.5,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                subtitle,
                textAlign: TextAlign.center,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(color: Color(0xFF6B7280), fontSize: 11),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class TimesheetPage extends StatefulWidget {
  const TimesheetPage({
    super.key,
    required this.session,
    required this.employee,
    required this.primary,
    required this.secondary,
    required this.onPrimaryChangePassword,
    required this.onPrimaryLogOff,
    required this.onAddUser,
    required this.onSecondaryLogOff,
  });

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
      // Staff timesheet is employee-scoped, not store-scoped.
      // Staff can work across multiple stores in one week, so do not send store_id.
      final Uri uri = Uri.parse(kTimesheetApiUrl).replace(queryParameters: {
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

  void _info(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    final List<_TimesheetTableRowData> tableRows = _extractTimesheetTableRows(_payload);
    final double totalHours = tableRows.fold<double>(0, (double sum, _TimesheetTableRowData row) => sum + row.hoursAsDouble);

    return Scaffold(
      body: Row(
        children: [
          _PosSideRail(
            primary: widget.primary,
            secondary: widget.secondary,
            onPrimaryTimesheet: _loading ? () {} : () => unawaited(_loadTimesheet()),
            onPrimaryChangePassword: () => showDialog<void>(
              context: context,
              builder: (BuildContext dialogContext) => ChangePasswordDialog(
                session: widget.session,
                employee: widget.primary,
              ),
            ),
            onPrimaryLogOff: () async {
              await widget.onPrimaryLogOff();
              if (mounted && Navigator.of(context).canPop()) Navigator.of(context).pop();
            },
            onAddUser: widget.onAddUser,
            onSecondaryLogOff: widget.onSecondaryLogOff,
            onWhoIsWorking: () => _info('Who Is Working coming soon.'),
            onPos: () => Navigator.of(context).pop(),
            onFinancials: () => _info('Financials coming soon.'),
            onInventory: () => _info('Inventory coming soon.'),
            onSync: _loading ? null : () => unawaited(_loadTimesheet()),
            onSettings: () => _info('Settings coming soon.'),
          ),
          Expanded(
            child: Container(
              color: const Color(0xFFF4F6FA),
              child: SafeArea(
                child: RefreshIndicator(
                  onRefresh: _loadTimesheet,
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(24, 20, 24, 24),
                    children: [
                      _TimesheetHeaderPanel(
                        storeName: widget.session.storeName,
                        employee: widget.employee,
                        weekStart: _weekStart,
                        weekEnd: _weekEnd,
                        rowCount: tableRows.length,
                        totalHours: totalHours,
                        loading: _loading,
                        onPrevious: _loading ? null : () => _moveWeek(-1),
                        onNext: _loading ? null : () => _moveWeek(1),
                        onRefresh: _loading ? null : () => unawaited(_loadTimesheet()),
                      ),
                      const SizedBox(height: 18),
                      if (_loading) ...[
                        const LinearProgressIndicator(minHeight: 3),
                        const SizedBox(height: 14),
                      ],
                      if (_error != null) ...[
                        _ErrorCard(message: _error!),
                        const SizedBox(height: 14),
                      ],
                      if (!_loading && _payload != null && tableRows.isEmpty)
                        _EmptyTimesheetCard(
                          employeeName: widget.employee.fullName,
                          weekStart: _weekStart,
                          weekEnd: _weekEnd,
                        )
                      else if (tableRows.isNotEmpty)
                        _TimesheetDataTable(rows: tableRows)
                      else if (_rows.isNotEmpty)
                        _FallbackTimesheetDataTable(rows: _rows),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _TimesheetSideRail extends StatelessWidget {
  const _TimesheetSideRail({
    required this.employee,
    required this.onBackToPos,
    required this.onRefresh,
    required this.onChangePassword,
    required this.onWhoIsWorking,
    required this.onFinancials,
    required this.onInventory,
    required this.onSettings,
    this.onSync,
  });

  final Employee employee;
  final VoidCallback onBackToPos;
  final VoidCallback? onRefresh;
  final VoidCallback onChangePassword;
  final VoidCallback onWhoIsWorking;
  final VoidCallback onFinancials;
  final VoidCallback onInventory;
  final VoidCallback? onSync;
  final VoidCallback onSettings;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 76,
      color: const Color(0xFF06133A),
      child: SafeArea(
        child: Column(
          children: [
            const SizedBox(height: 10),
            PopupMenuButton<String>(
              tooltip: 'User menu',
              onSelected: (String value) {
                if (value == 'back') onBackToPos();
                if (value == 'refresh') onRefresh?.call();
                if (value == 'password') onChangePassword();
              },
              itemBuilder: (BuildContext context) => <PopupMenuEntry<String>>[
                PopupMenuItem<String>(
                  enabled: false,
                  child: Text('${employee.fullName} / ${employee.roleName}'),
                ),
                const PopupMenuDivider(),
                const PopupMenuItem<String>(value: 'back', child: Text('Back to POS')),
                PopupMenuItem<String>(
                  value: 'refresh',
                  enabled: onRefresh != null,
                  child: const Text('Refresh time sheet'),
                ),
                const PopupMenuItem<String>(value: 'password', child: Text('Change Password')),
              ],
              child: CircleAvatar(
                radius: 25,
                backgroundColor: const Color(0xFFE91E63),
                child: Text(
                  employee.shortName.substring(0, 1).toUpperCase(),
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 18),
                ),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              employee.shortName,
              textAlign: TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: Colors.white70, fontSize: 10),
            ),
            const SizedBox(height: 18),
            _RailItem(icon: Icons.groups, label: 'Who Is Working', onTap: onWhoIsWorking),
            _RailItem(icon: Icons.point_of_sale, label: 'POS', onTap: onBackToPos),
            _RailItem(icon: Icons.attach_money, label: 'Financials', onTap: onFinancials),
            _RailItem(icon: Icons.assessment, label: 'Inventory', onTap: onInventory),
            const Spacer(),
            _RailItem(icon: Icons.cloud_sync, label: 'Sync', onTap: onSync),
            _RailItem(icon: Icons.settings, label: 'Settings', onTap: onSettings),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }
}

class _TimesheetHeaderPanel extends StatelessWidget {
  const _TimesheetHeaderPanel({
    required this.storeName,
    required this.employee,
    required this.weekStart,
    required this.weekEnd,
    required this.rowCount,
    required this.totalHours,
    required this.loading,
    required this.onPrevious,
    required this.onNext,
    required this.onRefresh,
  });

  final String storeName;
  final Employee employee;
  final DateTime weekStart;
  final DateTime weekEnd;
  final int rowCount;
  final double totalHours;
  final bool loading;
  final VoidCallback? onPrevious;
  final VoidCallback? onNext;
  final VoidCallback? onRefresh;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: const Color(0xFFE2E8F0)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.06),
            blurRadius: 18,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  storeName,
                  style: const TextStyle(color: Color(0xFF64748B), fontSize: 13, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 4),
                Text(
                  'Weekly Time Sheet',
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                        color: const Color(0xFF0F172A),
                        fontWeight: FontWeight.w900,
                      ),
                ),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    _MetricPill(icon: Icons.person, label: employee.fullName),
                    _MetricPill(icon: Icons.verified, label: 'API Timesheet v2'),
                    _MetricPill(icon: Icons.table_rows, label: '$rowCount shift${rowCount == 1 ? '' : 's'}'),
                    _MetricPill(icon: Icons.schedule, label: '${_formatHours(totalHours)} hours'),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 14),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
            decoration: BoxDecoration(
              color: const Color(0xFFF8FAFC),
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: const Color(0xFFE2E8F0)),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                IconButton(
                  tooltip: 'Previous week',
                  onPressed: onPrevious,
                  icon: const Icon(Icons.chevron_left),
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  child: Column(
                    children: [
                      const Text('Payroll week', style: TextStyle(fontSize: 12, color: Color(0xFF64748B), fontWeight: FontWeight.w700)),
                      const SizedBox(height: 3),
                      Text(
                        '${_dateOnly(weekStart)}  →  ${_dateOnly(weekEnd)}',
                        style: const TextStyle(fontSize: 14, color: Color(0xFF0F172A), fontWeight: FontWeight.w800),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  tooltip: 'Next week',
                  onPressed: onNext,
                  icon: const Icon(Icons.chevron_right),
                ),
                const SizedBox(width: 4),
                IconButton(
                  tooltip: 'Refresh',
                  onPressed: onRefresh,
                  icon: loading
                      ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
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

class _MetricPill extends StatelessWidget {
  const _MetricPill({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: const Color(0xFFEFF6FF),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: const Color(0xFFBFDBFE)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: const Color(0xFF1565C0)),
          const SizedBox(width: 6),
          Text(label, style: const TextStyle(color: Color(0xFF1E3A8A), fontSize: 12, fontWeight: FontWeight.w800)),
        ],
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
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Row(
        children: [
          const CircleAvatar(
            radius: 28,
            backgroundColor: Color(0xFFFFF7ED),
            child: Icon(Icons.event_busy, color: Color(0xFFEA580C), size: 28),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Text(
              'No timesheet rows found for $employeeName from ${_dateOnly(weekStart)} to ${_dateOnly(weekEnd)}. Use the arrows to select another week.',
              style: const TextStyle(color: Color(0xFF334155), fontSize: 14, fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }
}

class _TimesheetTableRowData {
  const _TimesheetTableRowData({
    required this.storeName,
    required this.inDate,
    required this.actualIn,
    required this.roundedIn,
    required this.outDate,
    required this.actualOut,
    required this.roundedOut,
    required this.totalHours,
  });

  final String storeName;
  final String inDate;
  final String actualIn;
  final String roundedIn;
  final String outDate;
  final String actualOut;
  final String roundedOut;
  final String totalHours;

  double get hoursAsDouble => double.tryParse(totalHours) ?? 0;
}

List<_TimesheetTableRowData> _extractTimesheetTableRows(Map<String, dynamic>? payload) {
  if (payload == null) return <_TimesheetTableRowData>[];

  final List<_TimesheetTableRowData> rows = <_TimesheetTableRowData>[];

  void addMap(Map<String, dynamic> raw) {
    final Map<String, dynamic> lower = <String, dynamic>{};
    for (final MapEntry<String, dynamic> entry in raw.entries) {
      lower[entry.key.toLowerCase()] = entry.value;
    }

    String pick(List<String> keys, {String fallback = '-'}) {
      for (final String key in keys) {
        final Object? value = lower[key.toLowerCase()];
        if (value != null) {
          final String text = value.toString().trim();
          if (text.isNotEmpty && text.toLowerCase() != 'null') return text;
        }
      }
      return fallback;
    }

    final String inDate = pick(const ['in_date']);
    final String actualIn = pick(const ['actual_in_time']);
    final String actualOut = pick(const ['actual_out_time']);
    final String totalHours = pick(const ['total_hours']);

    if (inDate == '-' && actualIn == '-' && actualOut == '-' && totalHours == '-') return;

    rows.add(
      _TimesheetTableRowData(
        storeName: pick(const ['store_name']),
        inDate: inDate,
        actualIn: _formatTimeForDisplay(actualIn),
        roundedIn: _formatTimeForDisplay(pick(const ['rounded_in_time'])),
        outDate: pick(const ['out_date'], fallback: inDate),
        actualOut: _formatTimeForDisplay(actualOut),
        roundedOut: _formatTimeForDisplay(pick(const ['rounded_out_time'])),
        totalHours: totalHours,
      ),
    );
  }

  void walk(Object? value) {
    if (value == null) return;
    if (value is List) {
      for (final Object? item in value) {
        walk(item);
      }
      return;
    }
    if (value is Map) {
      final Map<String, dynamic> map = value.map((key, val) => MapEntry(key.toString(), val));
      final Set<String> keys = map.keys.map((String k) => k.toLowerCase()).toSet();
      final bool looksLikePayrollRow = keys.contains('actual_in_time') ||
          keys.contains('rounded_in_time') ||
          keys.contains('actual_out_time') ||
          keys.contains('rounded_out_time') ||
          keys.contains('total_hours');
      if (looksLikePayrollRow) addMap(map);
      if (map.containsKey('rows')) walk(map['rows']);
    }
  }

  if (payload['detailed_rows'] is List) walk(payload['detailed_rows']);
  if (rows.isEmpty && payload['employee_wise_detailed_report'] is List) {
    walk(payload['employee_wise_detailed_report']);
  }

  final Set<String> seen = <String>{};
  return rows.where((_TimesheetTableRowData row) {
    final String key =
        '${row.storeName}|${row.inDate}|${row.actualIn}|${row.roundedIn}|${row.outDate}|${row.actualOut}|${row.roundedOut}|${row.totalHours}';
    if (seen.contains(key)) return false;
    seen.add(key);
    return true;
  }).toList();
}


class _TimesheetColumnWidths {
  const _TimesheetColumnWidths({
    required this.store,
    required this.inDate,
    required this.actualIn,
    required this.roundedIn,
    required this.outDate,
    required this.actualOut,
    required this.roundedOut,
    required this.totalHours,
  });

  final double store;
  final double inDate;
  final double actualIn;
  final double roundedIn;
  final double outDate;
  final double actualOut;
  final double roundedOut;
  final double totalHours;

  static _TimesheetColumnWidths fromAvailableWidth(double availableWidth) {
    final double tableWidth = availableWidth < 860 ? 860 : availableWidth;
    return _TimesheetColumnWidths(
      store: tableWidth * 0.19,
      inDate: tableWidth * 0.12,
      actualIn: tableWidth * 0.11,
      roundedIn: tableWidth * 0.12,
      outDate: tableWidth * 0.12,
      actualOut: tableWidth * 0.11,
      roundedOut: tableWidth * 0.12,
      totalHours: tableWidth * 0.11,
    );
  }
}

Widget _tsHeader(String text, {bool left = false, required double width}) {
  return SizedBox(
    width: width,
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10),
      child: Align(
        alignment: left ? Alignment.centerLeft : Alignment.center,
        child: Text(
          text,
          textAlign: left ? TextAlign.left : TextAlign.center,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
      ),
    ),
  );
}

Widget _tsCell(String text, {bool left = false, required double width}) {
  return SizedBox(
    width: width,
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10),
      child: Align(
        alignment: left ? Alignment.centerLeft : Alignment.center,
        child: Text(
          text,
          textAlign: left ? TextAlign.left : TextAlign.center,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
      ),
    ),
  );
}

class _TimesheetDataTable extends StatelessWidget {
  const _TimesheetDataTable({required this.rows});

  final List<_TimesheetTableRowData> rows;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: const Color(0xFFE2E8F0)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.06),
            blurRadius: 18,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
            color: const Color(0xFF0D47A1),
            child: Row(
              children: [
                const Expanded(
                  child: Text(
                    'Timesheet records',
                    style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 15),
                  ),
                ),
                Text(
                  '${rows.length} shift${rows.length == 1 ? '' : 's'}',
                  style: const TextStyle(color: Colors.white70, fontWeight: FontWeight.w700),
                ),
              ],
            ),
          ),
          LayoutBuilder(
            builder: (BuildContext context, BoxConstraints constraints) {
              final double tableWidth = constraints.maxWidth < 860 ? 860 : constraints.maxWidth;
              final _TimesheetColumnWidths widths = _TimesheetColumnWidths.fromAvailableWidth(tableWidth);
              return SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: SizedBox(
                  width: tableWidth,
                  child: DataTable(
                    border: const TableBorder(
                      horizontalInside: BorderSide(color: Color(0xFFE5E7EB), width: 1),
                      verticalInside: BorderSide(color: Color(0xFFE5E7EB), width: 1),
                    ),
                    headingRowColor: const WidgetStatePropertyAll<Color>(Color(0xFF1E3A8A)),
                    headingTextStyle: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 13),
                    dataTextStyle: const TextStyle(color: Color(0xFF0F172A), fontSize: 13, fontWeight: FontWeight.w600),
                    headingRowHeight: 56,
                    dataRowMinHeight: 50,
                    dataRowMaxHeight: 58,
                    columnSpacing: 0,
                    horizontalMargin: 0,
                    columns: [
                      DataColumn(label: _tsHeader('Store name', left: true, width: widths.store)),
                      DataColumn(label: _tsHeader('In date', width: widths.inDate)),
                      DataColumn(label: _tsHeader('Actual in', width: widths.actualIn)),
                      DataColumn(label: _tsHeader('Rounded in', width: widths.roundedIn)),
                      DataColumn(label: _tsHeader('Out date', width: widths.outDate)),
                      DataColumn(label: _tsHeader('Actual out', width: widths.actualOut)),
                      DataColumn(label: _tsHeader('Rounded out', width: widths.roundedOut)),
                      DataColumn(label: _tsHeader('Total hours', width: widths.totalHours)),
                    ],
                    rows: List<DataRow>.generate(
                      rows.length,
                      (int index) {
                        final _TimesheetTableRowData row = rows[index];
                        return DataRow(
                          color: WidgetStatePropertyAll<Color>(index.isEven ? Colors.white : const Color(0xFFF8FAFC)),
                          cells: [
                            DataCell(_tsCell(row.storeName, left: true, width: widths.store)),
                            DataCell(_tsCell(row.inDate, width: widths.inDate)),
                            DataCell(_tsCell(row.actualIn, width: widths.actualIn)),
                            DataCell(_tsCell(row.roundedIn, width: widths.roundedIn)),
                            DataCell(_tsCell(row.outDate, width: widths.outDate)),
                            DataCell(_tsCell(row.actualOut, width: widths.actualOut)),
                            DataCell(_tsCell(row.roundedOut, width: widths.roundedOut)),
                            DataCell(_tsCell(_formatHours(row.hoursAsDouble), width: widths.totalHours)),
                          ],
                        );
                      },
                    ),
                  ),
                ),
              );
            },
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
            color: const Color(0xFFF8FAFC),
            child: Row(
              children: [
                const Expanded(
                  child: Text(
                    'Total payable hours for this week',
                    style: TextStyle(color: Color(0xFF334155), fontWeight: FontWeight.w800),
                  ),
                ),
                Text(
                  _formatHours(rows.fold<double>(0, (double sum, _TimesheetTableRowData row) => sum + row.hoursAsDouble)),
                  style: const TextStyle(color: Color(0xFF0D47A1), fontWeight: FontWeight.w900, fontSize: 16),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _FallbackTimesheetDataTable extends StatelessWidget {
  const _FallbackTimesheetDataTable({required this.rows});

  final List<TimesheetRow> rows;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      clipBehavior: Clip.antiAlias,
      child: LayoutBuilder(
        builder: (BuildContext context, BoxConstraints constraints) {
          final double tableWidth = constraints.maxWidth < 720 ? 720 : constraints.maxWidth;
          final double storeWidth = tableWidth * 0.24;
          final double dateWidth = tableWidth * 0.16;
          final double clockInWidth = tableWidth * 0.16;
          final double clockOutWidth = tableWidth * 0.16;
          final double hoursWidth = tableWidth * 0.14;
          final double wageWidth = tableWidth * 0.14;
          return SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: SizedBox(
              width: tableWidth,
              child: DataTable(
                border: const TableBorder(
                  horizontalInside: BorderSide(color: Color(0xFFE5E7EB), width: 1),
                  verticalInside: BorderSide(color: Color(0xFFE5E7EB), width: 1),
                ),
                headingRowColor: const WidgetStatePropertyAll<Color>(Color(0xFF1E3A8A)),
                headingTextStyle: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900),
                columnSpacing: 0,
                horizontalMargin: 0,
                columns: [
                  DataColumn(label: _tsHeader('Store name', left: true, width: storeWidth)),
                  DataColumn(label: _tsHeader('Date', width: dateWidth)),
                  DataColumn(label: _tsHeader('Clock in', width: clockInWidth)),
                  DataColumn(label: _tsHeader('Clock out', width: clockOutWidth)),
                  DataColumn(label: _tsHeader('Total hours', width: hoursWidth)),
                  DataColumn(label: _tsHeader('Wage', width: wageWidth)),
                ],
                rows: rows
                    .map(
                      (row) => DataRow(
                        cells: [
                          DataCell(_tsCell(row.store, left: true, width: storeWidth)),
                          DataCell(_tsCell(row.date, width: dateWidth)),
                          DataCell(_tsCell(row.clockIn, width: clockInWidth)),
                          DataCell(_tsCell(row.clockOut, width: clockOutWidth)),
                          DataCell(_tsCell(row.payableHours, width: hoursWidth)),
                          DataCell(_tsCell(row.totalWage, width: wageWidth)),
                        ],
                      ),
                    )
                    .toList(),
              ),
            ),
          );
        },
      ),
    );
  }
}

String _formatHours(double value) {
  if (value == value.roundToDouble()) return value.toStringAsFixed(0);
  return value.toStringAsFixed(2).replaceFirst(RegExp(r'0$'), '');
}

String _formatTimeForDisplay(String value) {
  if (value == '-' || value.trim().isEmpty) return value;
  final List<String> parts = value.split(':');
  if (parts.length < 2) return value;
  final int? hour = int.tryParse(parts[0]);
  final int? minute = int.tryParse(parts[1]);
  if (hour == null || minute == null) return value;
  final String suffix = hour >= 12 ? 'PM' : 'AM';
  final int hour12 = hour % 12 == 0 ? 12 : hour % 12;
  return '$hour12:${minute.toString().padLeft(2, '0')} $suffix';
}


class PrimaryLoginStore {
  static const String _keyPrimaryEmployeeId = 'primary_employee_id';

  static Future<void> save(Employee employee) async {
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.setInt(_keyPrimaryEmployeeId, employee.id);
  }

  static Future<int?> loadPrimaryEmployeeId() async {
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    return prefs.getInt(_keyPrimaryEmployeeId);
  }

  static Future<void> clear() async {
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.remove(_keyPrimaryEmployeeId);
  }
}

Employee? _findEmployeeById(List<Employee> employees, int id) {
  for (final Employee employee in employees) {
    if (employee.id == id) return employee;
  }
  return null;
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
