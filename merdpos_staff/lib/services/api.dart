part of merdpos_staff;

class Api {
  static Future<Map<String, dynamic>> getJson(Uri uri) async {
    final http.Response response = await http.get(uri).timeout(const Duration(seconds: 20));
    return _decode(response);
  }

  static Future<Map<String, dynamic>> postJson(Uri uri, Map<String, dynamic> body) async {
    final http.Response response = await http.post(uri, headers: const {'Content-Type': 'application/json; charset=utf-8'}, body: jsonEncode(body)).timeout(const Duration(seconds: 20));
    return _decode(response);
  }

  static Map<String, dynamic> _decode(http.Response response) {
    final String text = utf8.decode(response.bodyBytes);
    try {
      final Object? decoded = jsonDecode(text);
      if (decoded is Map<String, dynamic>) return decoded;
      if (decoded is Map) return decoded.map((key, value) => MapEntry(key.toString(), value));
      throw Exception('Invalid JSON response.');
    } catch (_) {
      throw Exception('Invalid server response (${response.statusCode}).');
    }
  }
}
