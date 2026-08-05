part of merdpos_staff;

abstract interface class SecureValueStore {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class FlutterSecureValueStore implements SecureValueStore {
  FlutterSecureValueStore([FlutterSecureStorage? storage])
    : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);
}

abstract interface class LegacyTokenStore {
  Future<String?> readToken();
  Future<void> writeToken(String token);
  Future<bool> requiresReactivation();
  Future<void> setRequiresReactivation(bool required);
}

class SharedPreferencesLegacyTokenStore implements LegacyTokenStore {
  static const String tokenKey = 'activation_token';
  static const String reactivationKey = 'session_requires_reactivation';

  @override
  Future<String?> readToken() async =>
      (await SharedPreferences.getInstance()).getString(tokenKey);

  @override
  Future<void> writeToken(String token) async {
    await (await SharedPreferences.getInstance()).setString(tokenKey, token);
  }

  @override
  Future<bool> requiresReactivation() async =>
      (await SharedPreferences.getInstance()).getBool(reactivationKey) ?? false;

  @override
  Future<void> setRequiresReactivation(bool required) async {
    await (await SharedPreferences.getInstance()).setBool(
      reactivationKey,
      required,
    );
  }
}

enum DeviceTokenState { secure, migrated, legacyFallback, missing, corrupt }

class DeviceTokenResult {
  const DeviceTokenResult(this.state, [this.token]);
  final DeviceTokenState state;
  final String? token;
}

class DeviceTokenStore {
  DeviceTokenStore({SecureValueStore? secure, LegacyTokenStore? legacy})
    : secure = secure ?? FlutterSecureValueStore(),
      legacy = legacy ?? SharedPreferencesLegacyTokenStore();

  static const String secureTokenKey = 'device_activation_token_v1';
  final SecureValueStore secure;
  final LegacyTokenStore legacy;

  bool _valid(String? value) =>
      value != null && RegExp(r'^[a-fA-F0-9]{64}$').hasMatch(value);

  Future<DeviceTokenResult> load() async {
    if (await legacy.requiresReactivation()) {
      return const DeviceTokenResult(DeviceTokenState.missing);
    }
    String? secureToken;
    try {
      secureToken = await secure.read(secureTokenKey);
    } catch (_) {
      final String? legacyToken = await legacy.readToken();
      return _valid(legacyToken)
          ? DeviceTokenResult(DeviceTokenState.legacyFallback, legacyToken)
          : DeviceTokenResult(
              legacyToken == null
                  ? DeviceTokenState.missing
                  : DeviceTokenState.corrupt,
            );
    }
    if (secureToken != null) {
      return _valid(secureToken)
          ? DeviceTokenResult(DeviceTokenState.secure, secureToken)
          : const DeviceTokenResult(DeviceTokenState.corrupt);
    }
    final String? legacyToken = await legacy.readToken();
    if (!_valid(legacyToken)) {
      return DeviceTokenResult(
        legacyToken == null
            ? DeviceTokenState.missing
            : DeviceTokenState.corrupt,
      );
    }
    try {
      await secure.write(secureTokenKey, legacyToken!);
      final String? verified = await secure.read(secureTokenKey);
      if (verified == legacyToken) {
        return DeviceTokenResult(DeviceTokenState.migrated, legacyToken);
      }
    } catch (_) {
      // Preserve and use the approved compatibility value.
    }
    return DeviceTokenResult(DeviceTokenState.legacyFallback, legacyToken);
  }

  Future<void> save(String token) async {
    if (!_valid(token)) throw StateError('Invalid activation token.');
    await secure.write(secureTokenKey, token);
    final String? verified = await secure.read(secureTokenKey);
    if (verified != token) {
      throw StateError('Secure token verification failed.');
    }
    await legacy.writeToken(token);
    await legacy.setRequiresReactivation(false);
  }

  Future<void> deleteSecureToken() => secure.delete(secureTokenKey);

  Future<void> requireReactivation() => legacy.setRequiresReactivation(true);
}
