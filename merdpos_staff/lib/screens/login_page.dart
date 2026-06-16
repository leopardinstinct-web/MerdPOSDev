part of merdpos_staff;

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
  bool _loggingIn = false;
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
      if (mounted) setState(() => _error = cleanError(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _login() async {
    final String userId = _userIdController.text.trim();
    final String password = _passwordController.text.trim();
    if (userId.isEmpty || password.isEmpty) {
      setState(() => _error = 'Enter USER_ID and PASSWORD.');
      return;
    }
    setState(() {
      _loggingIn = true;
      _error = null;
    });
    try {
      final Employee employee = await AuthService.login(widget.session, userId, password);
      final List<Employee> merged = _mergeEmployee(_employees, employee);
      await PrimaryLoginStore.save(employee);
      if (!mounted) return;
      setState(() => _employees = merged);
      _openHome(employee, merged);
    } catch (e) {
      if (mounted) setState(() => _error = cleanError(e));
    } finally {
      if (mounted) setState(() => _loggingIn = false);
    }
  }

  void _openHome(Employee employee, [List<Employee>? employees]) {
    Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => HomePage(session: widget.session, primaryEmployee: employee, employees: employees ?? _employees)));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: _CenteredShell(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _LogoHeader(title: 'Staff Login', subtitle: '${widget.session.storeName} • ${_employees.length} active employees'),
            const SizedBox(height: 24),
            if (_loading) const LinearProgressIndicator(minHeight: 2),
            const SizedBox(height: 8),
            TextField(controller: _userIdController, decoration: const InputDecoration(labelText: 'USER_ID'), keyboardType: TextInputType.number, textInputAction: TextInputAction.next),
            const SizedBox(height: 12),
            TextField(controller: _passwordController, decoration: const InputDecoration(labelText: 'PASSWORD'), keyboardType: TextInputType.number, obscureText: true, onSubmitted: (_) => _login()),
            const SizedBox(height: 16),
            FilledButton.icon(onPressed: (_loading || _loggingIn) ? null : _login, icon: _busyIcon(_loggingIn, Icons.login), label: const Text('Login')),
            TextButton(onPressed: _loggingIn ? null : widget.onResetSetup, child: const Text('Reset setup')),
            if (_error != null) ...[const SizedBox(height: 12), _MessageCard(message: _error!, type: MessageType.error)],
          ],
        ),
      ),
    );
  }
}
