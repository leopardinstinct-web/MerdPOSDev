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
  _CredentialField _activeField = _CredentialField.userId;

  TextEditingController get _activeController {
    return _activeField == _CredentialField.userId ? _userIdController : _passwordController;
  }

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
    if (_loggingIn) return;
    final String userId = _userIdController.text.trim();
    final String password = _passwordController.text.trim();
    if (userId.isEmpty || password.isEmpty) {
      setState(() {
        _activeField = userId.isEmpty ? _CredentialField.userId : _CredentialField.password;
        _error = 'Enter USER_ID and PASSWORD.';
      });
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

  void _selectField(_CredentialField field) {
    FocusScope.of(context).unfocus();
    if (!_loggingIn) setState(() => _activeField = field);
  }

  void _appendDigit(String digit) {
    if (_loggingIn) return;
    final TextEditingController controller = _activeController;
    final String next = '${controller.text}$digit';
    controller.value = TextEditingValue(text: next, selection: TextSelection.collapsed(offset: next.length));
    if (_error != null) setState(() => _error = null);
  }

  void _backspace() {
    if (_loggingIn) return;
    final TextEditingController controller = _activeController;
    if (controller.text.isEmpty) return;
    final String next = controller.text.substring(0, controller.text.length - 1);
    controller.value = TextEditingValue(text: next, selection: TextSelection.collapsed(offset: next.length));
  }

  void _clearActive() {
    if (_loggingIn) return;
    _activeController.clear();
  }

  void _openHome(Employee employee, [List<Employee>? employees]) {
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => HomePage(session: widget.session, primaryEmployee: employee, employees: employees ?? _employees)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final bool canUsePad = !_loading && !_loggingIn;
    return Scaffold(
      body: _CenteredShell(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const _CompactLogoHeader(),
            const SizedBox(height: 16),
            if (_loading) const LinearProgressIndicator(minHeight: 2),
            const SizedBox(height: 8),
            _NumericLoginField(
              controller: _userIdController,
              label: 'USER_ID',
              selected: _activeField == _CredentialField.userId,
              enabled: !_loggingIn,
              onSelected: () => _selectField(_CredentialField.userId),
            ),
            const SizedBox(height: 12),
            _NumericLoginField(
              controller: _passwordController,
              label: 'PASSWORD',
              selected: _activeField == _CredentialField.password,
              obscure: true,
              enabled: !_loggingIn,
              onSelected: () => _selectField(_CredentialField.password),
            ),
            const SizedBox(height: 16),
            _LoginNumericPad(
              enabled: canUsePad,
              onDigit: _appendDigit,
              onBackspace: _backspace,
              onClear: _clearActive,
            ),
            const SizedBox(height: 10),
            FilledButton.icon(onPressed: canUsePad ? _login : null, icon: _busyIcon(_loggingIn, Icons.login), label: const Text('Login')),
            if (_error != null) ...[const SizedBox(height: 12), _MessageCard(message: _error!, type: MessageType.error)],
          ],
        ),
      ),
    );
  }
}
