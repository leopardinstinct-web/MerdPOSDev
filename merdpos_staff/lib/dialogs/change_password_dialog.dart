part of merdpos_staff;

class ChangePasswordDialog extends StatefulWidget {
  const ChangePasswordDialog({super.key, required this.session, required this.employee});
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
      setState(() => _error = 'Enter current password, new password, and confirmation.');
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
      final Map<String, dynamic> payload = await Api.postJson(Uri.parse(kChangePasswordUrl), <String, dynamic>{
        'client_id': widget.session.clientId,
        'store_id': widget.session.storeId,
        'device_uuid': widget.session.deviceUuid,
        'employee_id': widget.employee.id,
        'old_password': oldPassword,
        'new_password': newPassword,
      }, bearerToken: widget.session.activationToken);
      if (payload['success'] != true) throw Exception(payload['error']?.toString() ?? 'Password change failed.');
      if (!mounted) return;
      Navigator.of(context).pop();
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Password changed successfully. Use the new password next login.')));
    } catch (e) {
      if (mounted) setState(() => _error = cleanError(e));
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
            TextField(controller: _oldPasswordController, decoration: const InputDecoration(labelText: 'Current password'), keyboardType: TextInputType.number, obscureText: true, enabled: !_saving),
            const SizedBox(height: 12),
            TextField(controller: _newPasswordController, decoration: const InputDecoration(labelText: 'New numeric password'), keyboardType: TextInputType.number, obscureText: true, enabled: !_saving),
            const SizedBox(height: 12),
            TextField(controller: _confirmPasswordController, decoration: const InputDecoration(labelText: 'Confirm new password'), keyboardType: TextInputType.number, obscureText: true, enabled: !_saving, onSubmitted: (_) => _saving ? null : _save()),
            if (_error != null) ...[const SizedBox(height: 10), Text(_error!, style: const TextStyle(color: BlueIce.error))],
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: _saving ? null : () => Navigator.of(context).pop(), child: const Text('Cancel')),
        FilledButton(onPressed: _saving ? null : _save, child: _saving ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Change password')),
      ],
    );
  }
}
