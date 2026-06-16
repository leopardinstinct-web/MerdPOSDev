part of merdpos_staff;

class PrimaryLoginStore {
  static const String _keyPrimaryEmployeeId = 'primary_employee_id';
  static Future<void> save(Employee employee) async {
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.setInt(_keyPrimaryEmployeeId, employee.id);
  }
  static Future<int?> loadPrimaryEmployeeId() async => (await SharedPreferences.getInstance()).getInt(_keyPrimaryEmployeeId);
  static Future<void> clear() async => (await SharedPreferences.getInstance()).remove(_keyPrimaryEmployeeId);
}
