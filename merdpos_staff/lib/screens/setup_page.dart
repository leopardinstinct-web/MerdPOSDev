part of merdpos_staff;

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
  String? _activationGrant;

  @override
  void dispose() {
    _companyCodeController.dispose();
    _setupKeyController.dispose();
    super.dispose();
  }

  Future<void> _loadStores() async {
    if (_companyCodeController.text.trim().isEmpty ||
        _setupKeyController.text.trim().isEmpty) {
      setState(() => _error = 'Enter company code and setup key.');
      return;
    }
    setState(() {
      _loadingStores = true;
      _error = null;
      _client = null;
      _stores = <Map<String, dynamic>>[];
      _selectedStore = null;
      _activationGrant = null;
    });
    try {
      final Map<String, dynamic> payload = await Api.postJson(
        Uri.parse(kRequestActivationGrantUrl),
        <String, dynamic>{
          'client_code': _companyCodeController.text.trim(),
          'setup_key': _setupKeyController.text.trim(),
        },
      );
      if (payload['success'] != true)
        throw Exception(
          payload['error']?.toString() ?? 'Could not load stores.',
        );
      final Object? storesRaw = payload['stores'];
      if (storesRaw is! List) throw Exception('Invalid stores response.');
      final String grant = payload['activation_grant']?.toString() ?? '';
      if (grant.isEmpty) throw Exception('Activation grant missing.');
      if (!mounted) return;
      setState(() {
        _client = _asMap(payload['client']);
        _activationGrant = grant;
        _stores = storesRaw.map((e) => _asMap(e)).toList();
        if (_stores.isNotEmpty) _selectedStore = _stores.first;
      });
    } catch (e) {
      if (mounted) setState(() => _error = cleanError(e));
    } finally {
      if (mounted) setState(() => _loadingStores = false);
    }
  }

  Future<void> _activateSelectedStore() async {
    if (_client == null || _selectedStore == null || _activationGrant == null) return;
    setState(() {
      _activating = true;
      _error = null;
    });
    try {
      final SharedPreferences prefs = await SharedPreferences.getInstance();
      String? deviceUuid = prefs.getString('device_uuid');
      deviceUuid ??= const Uuid().v4();
      await prefs.setString('device_uuid', deviceUuid);
      final Map<String, dynamic> payload =
          await Api.postJson(Uri.parse(kActivateDeviceUrl), <String, dynamic>{
            'client_id': _toInt(_client!['id']),
            'store_id': _toInt(_selectedStore!['id']),
            'activation_grant': _activationGrant,
            'device_uuid': deviceUuid,
            'device_name': 'MerdPOS Staff App',
          });
      if (payload['success'] != true)
        throw Exception(
          payload['error']?.toString() ?? 'Device activation failed.',
        );
      final String token = payload['activation_token']?.toString() ?? '';
      if (token.isEmpty) throw Exception('Activation token missing.');
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
      if (mounted) setState(() => _error = cleanError(e));
    } finally {
      if (mounted) setState(() => _activating = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: _CenteredShell(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _LogoHeader(
              title: 'Connect this device',
              subtitle:
                  'Enter company code and setup key, then select the store.',
            ),
            const SizedBox(height: 24),
            TextField(
              controller: _companyCodeController,
              decoration: const InputDecoration(labelText: 'Company code'),
              textInputAction: TextInputAction.next,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _setupKeyController,
              decoration: const InputDecoration(labelText: 'Setup key'),
              obscureText: true,
              onSubmitted: (_) => _loadingStores ? null : _loadStores(),
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: _loadingStores ? null : _loadStores,
              icon: _busyIcon(_loadingStores, Icons.search),
              label: const Text('Load stores'),
            ),
            if (_stores.isNotEmpty) ...[
              const SizedBox(height: 16),
              DropdownButtonFormField<Map<String, dynamic>>(
                value: _selectedStore,
                decoration: const InputDecoration(labelText: 'Select store'),
                dropdownColor: BlueIce.surface,
                items: _stores.map((Map<String, dynamic> store) {
                  final String name =
                      store['store_name']?.toString() ?? 'Store';
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
                icon: _busyIcon(_activating, Icons.verified_user),
                label: const Text('Activate device'),
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: 12),
              _MessageCard(message: _error!, type: MessageType.error),
            ],
          ],
        ),
      ),
    );
  }
}
