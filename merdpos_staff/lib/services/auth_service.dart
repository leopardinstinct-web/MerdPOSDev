part of merdpos_staff;

class AuthService {
  static Future<Employee> login(AppSession session, String userId, String password) async {
    final Map<String, dynamic> payload = await Api.postJson(Uri.parse(kLoginUrl), <String, dynamic>{
      'client_id': session.clientId,
      'store_id': session.storeId,
      'device_uuid': session.deviceUuid,
      'activation_token': session.activationToken,
      'user_id': userId,
      'password': password,
    });
    if (payload['success'] != true) throw Exception(payload['error']?.toString() ?? 'Invalid login.');
    final Map<String, dynamic> employeeMap = _asMap(payload['employee'] ?? payload['data']);
    if (employeeMap.isEmpty) throw Exception('Login response missing employee.');
    return Employee.fromMap(employeeMap);
  }
}
