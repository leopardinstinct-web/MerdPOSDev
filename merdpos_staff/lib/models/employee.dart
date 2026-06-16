part of merdpos_staff;

class Employee {
  const Employee({required this.id, required this.fullName, required this.userId, required this.roleName, required this.hourlyRate});
  final int id;
  final String fullName;
  final String userId;
  final String roleName;
  final String hourlyRate;
  String get shortName => fullName.trim().isEmpty ? 'User' : fullName.trim().split(RegExp(r'\s+')).first;
  String get initial => shortName.isEmpty ? 'U' : shortName.substring(0, 1).toUpperCase();

  factory Employee.fromMap(Map<String, dynamic> map) => Employee(
        id: _toInt(map['id'] ?? map['employee_id']),
        fullName: map['full_name']?.toString() ?? map['employee_name']?.toString() ?? map['name']?.toString() ?? 'Employee',
        userId: map['user_id']?.toString() ?? map['login_id']?.toString() ?? '',
        roleName: map['role_name']?.toString() ?? map['employee_type']?.toString() ?? map['role']?.toString() ?? 'Staff',
        hourlyRate: map['hourly_rate']?.toString() ?? '',
      );
}

List<Employee> _mergeEmployee(List<Employee> employees, Employee employee) {
  final List<Employee> result = <Employee>[];
  bool replaced = false;
  for (final Employee item in employees) {
    if (item.id == employee.id) {
      result.add(employee);
      replaced = true;
    } else {
      result.add(item);
    }
  }
  if (!replaced) result.add(employee);
  return result;
}

Employee? _findEmployeeById(List<Employee> employees, int id) {
  for (final Employee employee in employees) {
    if (employee.id == id) return employee;
  }
  return null;
}
