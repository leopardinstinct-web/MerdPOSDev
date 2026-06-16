part of merdpos_staff;

BoxDecoration _panelDecoration({double radius = 10}) => BoxDecoration(color: BlueIce.surface, borderRadius: BorderRadius.circular(radius), border: Border.all(color: BlueIce.border), boxShadow: const [BoxShadow(color: Color(0x73000000), blurRadius: 8, offset: Offset(0, 2))]);

Widget _busyIcon(bool busy, IconData icon) => busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : Icon(icon);

Map<String, dynamic> _asMap(Object? value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) return value.map((key, val) => MapEntry(key.toString(), val));
  return <String, dynamic>{};
}

Map<String, dynamic> _lowerKeyMap(Map<String, dynamic> map) => map.map((key, value) => MapEntry(key.toLowerCase(), value));

String? _firstString(Map<String, dynamic> map, List<String> keys) {
  for (final String key in keys) {
    final Object? value = map[key];
    if (value != null && value.toString().trim().isNotEmpty) return value.toString();
  }
  return null;
}

int _toInt(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse(value?.toString() ?? '') ?? 0;
}

String _dateOnly(DateTime dt) => '${dt.year.toString().padLeft(4, '0')}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')}';
String _timeOnly(DateTime dt) => '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}:${dt.second.toString().padLeft(2, '0')}';
DateTime startOfWeek(DateTime date) => DateTime(date.year, date.month, date.day).subtract(Duration(days: date.weekday - 1));

String _formatHours(double value) {
  if (value == value.roundToDouble()) return value.toStringAsFixed(0);
  return value.toStringAsFixed(2).replaceFirst(RegExp(r'0$'), '');
}

String _formatTimeForDisplay(String value) {
  if (value == '-' || value.trim().isEmpty) return value;
  final List<String> parts = value.split(':');
  if (parts.length < 2) return value;
  final int? hour = int.tryParse(parts[0]);
  final int? minute = int.tryParse(parts[1]);
  if (hour == null || minute == null) return value;
  final String suffix = hour >= 12 ? 'PM' : 'AM';
  final int hour12 = hour % 12 == 0 ? 12 : hour % 12;
  return '$hour12:${minute.toString().padLeft(2, '0')} $suffix';
}

String cleanError(Object error) => error.toString().replaceFirst('Exception: ', '').trim();
void _snack(BuildContext context, String message) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
