part of merdpos_staff;

class Api {
  static Future<void> Function()? onDeviceAuthorizationFailure;

  static Map<String, String> _headers([String? bearerToken]) =>
      <String, String>{
        'Content-Type': 'application/json; charset=utf-8',
        if (bearerToken != null) 'Authorization': 'Bearer $bearerToken',
      };

  static Future<Map<String, dynamic>> getJson(
    Uri uri, {
    String? bearerToken,
  }) async {
    final http.Response response = await http
        .get(uri, headers: _headers(bearerToken))
        .timeout(const Duration(seconds: 20));
    return _decode(
      response,
      handleDeviceAuthorizationFailure: bearerToken != null,
    );
  }

  static Future<Map<String, dynamic>> postJson(
    Uri uri,
    Map<String, dynamic> body, {
    String? bearerToken,
  }) async {
    final http.Response response = await http
        .post(
          uri,
          headers: _headers(bearerToken),
          body: jsonEncode(body),
        )
        .timeout(const Duration(seconds: 20));
    return _decode(
      response,
      handleDeviceAuthorizationFailure: bearerToken != null,
    );
  }

  static Map<String, dynamic> _decode(
    http.Response response, {
    required bool handleDeviceAuthorizationFailure,
  }) {
    final String text = utf8.decode(response.bodyBytes);
    try {
      final Object? decoded = jsonDecode(text);
      final Map<String, dynamic> payload = decoded is Map<String, dynamic>
          ? decoded
          : decoded is Map
          ? decoded.map((key, value) => MapEntry(key.toString(), value))
          : throw Exception('Invalid JSON response.');
      if (handleDeviceAuthorizationFailure &&
          isDeviceAuthorizationFailure(response.statusCode, payload)) {
        final Future<void> Function()? handler = onDeviceAuthorizationFailure;
        if (handler != null) unawaited(handler());
        throw const DeviceAuthorizationException();
      }
      return payload;
    } on DeviceAuthorizationException {
      rethrow;
    } catch (_) {
      throw Exception('Invalid server response (${response.statusCode}).');
    }
  }

  static bool isDeviceAuthorizationFailure(
    int statusCode,
    Map<String, dynamic> payload,
  ) {
    if (statusCode != 401) return false;
    return <String>{
      'device_unauthorized',
      'device_revoked',
      'token_expired',
    }.contains(payload['error_code']?.toString());
  }
}

class DeviceAuthorizationException implements Exception {
  const DeviceAuthorizationException();
  @override
  String toString() => 'Device authorization expired. Reactivation required.';
}
