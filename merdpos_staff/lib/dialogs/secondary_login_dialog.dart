part of merdpos_staff;

class SecondaryLoginDialog extends StatefulWidget {
  const SecondaryLoginDialog({super.key, required this.session, required this.primaryEmployee});
  final AppSession session;
  final Employee primaryEmployee;
  @override
  State<SecondaryLoginDialog> createState() => _SecondaryLoginDialogState();
}

class _SecondaryLoginDialogState extends State<SecondaryLoginDialog> {
  final TextEditingController _userIdController = TextEditingController();
  final TextEditingController _passwordController = TextEditingController();
  bool _loggingIn = false;
  String? _error;

  @override
  void dispose() {
    _userIdController.dispose();
    _passwordController.dispose();
    super.dispose();
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
      if (employee.id == widget.primaryEmployee.id) {
        setState(() => _error = 'This user is already the primary user.');
        return;
      }
      if (!mounted) return;
      Navigator.of(context).pop(employee);
    } catch (e) {
      if (mounted) setState(() => _error = cleanError(e));
    } finally {
      if (mounted) setState(() => _loggingIn = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Add secondary user'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(controller: _userIdController, decoration: const InputDecoration(labelText: 'USER_ID'), keyboardType: TextInputType.number, textInputAction: TextInputAction.next, enabled: !_loggingIn),
            const SizedBox(height: 12),
            TextField(controller: _passwordController, decoration: const InputDecoration(labelText: 'PASSWORD'), keyboardType: TextInputType.number, obscureText: true, enabled: !_loggingIn, onSubmitted: (_) => _login()),
            if (_error != null) ...[const SizedBox(height: 10), Text(_error!, style: const TextStyle(color: BlueIce.error))],
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: _loggingIn ? null : () => Navigator.of(context).pop(), child: const Text('Cancel')),
        FilledButton(onPressed: _loggingIn ? null : _login, child: _loggingIn ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Add user')),
      ],
    );
  }
}
