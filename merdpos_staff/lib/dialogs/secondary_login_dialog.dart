part of merdpos_staff;

class SecondaryLoginDialog extends StatefulWidget {
  const SecondaryLoginDialog({super.key, required this.session, required this.primaryEmployee});
  final AppSession session;
  final Employee primaryEmployee;
  @override
  State<SecondaryLoginDialog> createState() => _SecondaryLoginDialogState();
}

enum SecondaryLoginChoice { additional, replacePrevious }

class SecondaryLoginResult {
  const SecondaryLoginResult(this.employee, this.choice);
  final Employee employee;
  final SecondaryLoginChoice choice;
}

class _SecondaryLoginDialogState extends State<SecondaryLoginDialog> {
  final TextEditingController _userIdController = TextEditingController();
  final TextEditingController _passwordController = TextEditingController();
  bool _loggingIn = false;
  String? _error;
  _CredentialField _activeField = _CredentialField.userId;
  Employee? _authenticatedEmployee;

  TextEditingController get _activeController {
    return _activeField == _CredentialField.userId ? _userIdController : _passwordController;
  }

  @override
  void dispose() {
    _userIdController.dispose();
    _passwordController.dispose();
    super.dispose();
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
      if (employee.id == widget.primaryEmployee.id) {
        setState(() => _error = 'This user is already the primary user.');
        return;
      }
      if (!mounted) return;
      setState(() => _authenticatedEmployee = employee);
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

  @override
  Widget build(BuildContext context) {
    final bool canUsePad = !_loggingIn;
    final Employee? authenticated = _authenticatedEmployee;
    return AlertDialog(
      title: Text(authenticated == null ? 'Sign in another user' : 'How should this user sign in?'),
      content: SingleChildScrollView(
        child: SizedBox(
          width: 360,
          child: authenticated != null ? Column(mainAxisSize: MainAxisSize.min, children: [
            Text('${authenticated.fullName} has been verified.'),
            const SizedBox(height: 10),
            Text('Keep ${widget.primaryEmployee.fullName} signed in, or report that they forgot to log out?'),
          ]) : Column(
            mainAxisSize: MainAxisSize.min,
            children: [
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
              const SizedBox(height: 14),
              _LoginNumericPad(
                enabled: canUsePad,
                onDigit: _appendDigit,
                onBackspace: _backspace,
                onClear: _clearActive,
              ),
              if (_error != null) ...[const SizedBox(height: 10), Text(_error!, style: const TextStyle(color: BlueIce.error))],
            ],
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: _loggingIn ? null : () => Navigator.of(context).pop(), child: const Text('Cancel')),
        if (authenticated != null) ...[
          OutlinedButton(onPressed: () => Navigator.of(context).pop(SecondaryLoginResult(authenticated, SecondaryLoginChoice.replacePrevious)), child: const Text('Previous user forgot')),
          FilledButton(onPressed: () => Navigator.of(context).pop(SecondaryLoginResult(authenticated, SecondaryLoginChoice.additional)), child: const Text('Add as additional user')),
        ] else FilledButton(
          onPressed: canUsePad ? _login : null,
          child: _loggingIn ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Continue'),
        ),
      ],
    );
  }
}
