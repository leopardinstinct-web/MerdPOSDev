library merdpos_staff;

import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

part 'theme.dart';
part 'models/app_session.dart';
part 'models/employee.dart';
part 'models/timesheet_row.dart';
part 'services/api.dart';
part 'services/auth_service.dart';
part 'services/employee_service.dart';
part 'services/primary_login_store.dart';
part 'services/timesheet_parser.dart';
part 'screens/setup_page.dart';
part 'screens/login_page.dart';
part 'screens/home_page.dart';
part 'screens/timesheet_page.dart';
part 'dialogs/change_password_dialog.dart';
part 'dialogs/secondary_login_dialog.dart';
part 'widgets/pos_side_rail.dart';
part 'widgets/timesheet_table.dart';
part 'widgets/common_widgets.dart';
part 'utils/helpers.dart';

// MERDPOS / POS LATEST - v16 modular hashed-login Blue Ice
// GitHub current app upgraded into modules for easier future changes.
// Backend login is verified by backend/api/login.php so password_hash() works.
// get_employees.php must NOT return login_password or pin_code.
// UI follows the Blue Ice design tokens from DESIGN_TOKENS.md.
const String kApiBaseUrl = 'https://app.merdpos.com/api';
const String kGetStoresUrl = '$kApiBaseUrl/get_stores.php';
const String kActivateDeviceUrl = '$kApiBaseUrl/activate_device.php';
const String kGetEmployeesUrl = '$kApiBaseUrl/get_employees.php';
const String kLoginUrl = '$kApiBaseUrl/login.php';
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
      theme: blueIceTheme(),
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
    if (_loading) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    if (_session == null) return SetupPage(onConfigured: _onConfigured);
    return LoginPage(session: _session!, onResetSetup: _clearSetup);
  }
}
