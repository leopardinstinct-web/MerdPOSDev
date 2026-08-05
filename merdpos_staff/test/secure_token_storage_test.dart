import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:merdpos_staff/main.dart';
import 'package:shared_preferences/shared_preferences.dart';

class FakeSecureValueStore implements SecureValueStore {
  String? value;
  bool failRead = false;
  bool failWrite = false;

  @override
  Future<String?> read(String key) async {
    if (failRead) throw StateError('simulated secure read failure');
    return value;
  }

  @override
  Future<void> write(String key, String value) async {
    if (failWrite) throw StateError('simulated secure write failure');
    this.value = value;
  }

  @override
  Future<void> delete(String key) async => value = null;
}

class FakeLegacyTokenStore implements LegacyTokenStore {
  String? token;
  bool reactivation = false;

  @override
  Future<String?> readToken() async => token;

  @override
  Future<void> writeToken(String token) async => this.token = token;

  @override
  Future<bool> requiresReactivation() async => reactivation;

  @override
  Future<void> setRequiresReactivation(bool required) async =>
      reactivation = required;
}

void main() {
  final String tokenA = List<String>.filled(64, 'a').join();
  final String tokenB = List<String>.filled(64, 'b').join();

  test('secure token save, load, and delete verify round trips', () async {
    final FakeSecureValueStore secure = FakeSecureValueStore();
    final FakeLegacyTokenStore legacy = FakeLegacyTokenStore();
    final DeviceTokenStore store = DeviceTokenStore(
      secure: secure,
      legacy: legacy,
    );
    await store.save(tokenA);
    expect((await store.load()).token, tokenA);
    expect(legacy.token, tokenA, reason: 'legacy retained for two releases');
    await store.deleteSecureToken();
    expect(secure.value, isNull);
  });

  test('legacy token silently migrates and remains retained', () async {
    final FakeSecureValueStore secure = FakeSecureValueStore();
    final FakeLegacyTokenStore legacy = FakeLegacyTokenStore()..token = tokenA;
    final DeviceTokenResult result = await DeviceTokenStore(
      secure: secure,
      legacy: legacy,
    ).load();
    expect(result.state, DeviceTokenState.migrated);
    expect(result.token, tokenA);
    expect(secure.value, tokenA);
    expect(legacy.token, tokenA);
  });

  test('secure write failure preserves and uses the legacy token', () async {
    final FakeSecureValueStore secure = FakeSecureValueStore()..failWrite = true;
    final FakeLegacyTokenStore legacy = FakeLegacyTokenStore()..token = tokenA;
    final DeviceTokenResult result = await DeviceTokenStore(
      secure: secure,
      legacy: legacy,
    ).load();
    expect(result.state, DeviceTokenState.legacyFallback);
    expect(result.token, tokenA);
    expect(legacy.token, tokenA);
  });

  test('failed secure save does not replace the retained legacy token', () async {
    final FakeSecureValueStore secure = FakeSecureValueStore()..failWrite = true;
    final FakeLegacyTokenStore legacy = FakeLegacyTokenStore()..token = tokenA;
    final DeviceTokenStore store = DeviceTokenStore(
      secure: secure,
      legacy: legacy,
    );
    await expectLater(store.save(tokenB), throwsStateError);
    expect(legacy.token, tokenA);
  });

  test('secure read failure preserves the approved compatibility path', () async {
    final FakeSecureValueStore secure = FakeSecureValueStore()..failRead = true;
    final FakeLegacyTokenStore legacy = FakeLegacyTokenStore()..token = tokenA;
    final DeviceTokenResult result = await DeviceTokenStore(
      secure: secure,
      legacy: legacy,
    ).load();
    expect(result.state, DeviceTokenState.legacyFallback);
    expect(result.token, tokenA);
    expect(legacy.token, tokenA);
  });

  test('missing and corrupt secure tokens route away from a session', () async {
    final DeviceTokenResult missing = await DeviceTokenStore(
      secure: FakeSecureValueStore(),
      legacy: FakeLegacyTokenStore(),
    ).load();
    expect(missing.state, DeviceTokenState.missing);
    expect(missing.token, isNull);

    final FakeSecureValueStore corruptSecure = FakeSecureValueStore()
      ..value = 'corrupt';
    final DeviceTokenResult corrupt = await DeviceTokenStore(
      secure: corruptSecure,
      legacy: FakeLegacyTokenStore()..token = tokenB,
    ).load();
    expect(corrupt.state, DeviceTokenState.corrupt);
    expect(corrupt.token, isNull, reason: 'corrupt secure data must not downgrade');
  });

  test('expired or revoked authorization triggers controlled reactivation', () async {
    expect(
      Api.isDeviceAuthorizationFailure(401, <String, dynamic>{
        'error_code': 'device_unauthorized',
      }),
      isTrue,
    );
    expect(
      Api.isDeviceAuthorizationFailure(401, <String, dynamic>{
        'error_code': 'device_revoked',
      }),
      isTrue,
    );
    expect(
      Api.isDeviceAuthorizationFailure(500, <String, dynamic>{
        'error_code': 'device_unauthorized',
      }),
      isFalse,
    );
    final FakeLegacyTokenStore legacy = FakeLegacyTokenStore()..token = tokenA;
    final DeviceTokenStore store = DeviceTokenStore(
      secure: FakeSecureValueStore()..value = tokenA,
      legacy: legacy,
    );
    await store.requireReactivation();
    expect((await store.load()).state, DeviceTokenState.missing);
    expect(legacy.token, tokenA, reason: 'compatibility token is retained');
  });

  test('primary persistence and two-user session behavior remain unchanged', () async {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    final Employee primary = Employee.fromMap(<String, dynamic>{
      'id': 1,
      'full_name': 'Primary User',
      'user_id': '1001',
    });
    await PrimaryLoginStore.save(primary);
    expect(await PrimaryLoginStore.loadPrimaryEmployeeId(), 1);
    await PrimaryLoginStore.clear();
    expect(await PrimaryLoginStore.loadPrimaryEmployeeId(), isNull);

    final String homeSource = File('lib/screens/home_page.dart').readAsStringSync();
    expect(homeSource, contains('Maximum 2 users allowed at the same time.'));
    expect(homeSource, contains('_primaryEmployee=secondary'));
    expect(homeSource, contains('_secondaryEmployee=null'));
  });
}
