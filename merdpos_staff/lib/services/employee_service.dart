part of merdpos_staff;

class EmployeeService {
  static Future<List<Employee>> loadEmployees(AppSession session) async {
    final Uri uri = Uri.parse(kGetEmployeesUrl).replace(queryParameters: {
      'client_id': session.clientId.toString(),
      'store_id': session.storeId.toString(),
      'activation_token': session.activationToken,
    });
    final Map<String, dynamic> payload = await Api.getJson(uri);
    if (payload['success'] != true) throw Exception(payload['error']?.toString() ?? 'Could not load employees.');
    final Object? raw = payload['employees'] ?? payload['data'];
    if (raw is! List) throw Exception('Invalid employees response.');
    return raw.map((e) => Employee.fromMap(_asMap(e))).toList();
  }
}
