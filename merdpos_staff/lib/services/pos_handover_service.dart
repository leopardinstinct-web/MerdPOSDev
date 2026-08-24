part of merdpos_staff;

class PosHandoverService {
  static const String _queueKey = 'pending_pos_handovers_v1';

  static Future<void> queueAndSync(AppSession session, Employee previous, Employee replacement) async {
    final prefs = await SharedPreferences.getInstance();
    final List<dynamic> queue = jsonDecode(prefs.getString(_queueKey) ?? '[]') as List<dynamic>;
    queue.add(<String, dynamic>{
      'handover_id': const Uuid().v4(),
      'previous_employee_id': previous.id,
      'replacement_employee_id': replacement.id,
    });
    await prefs.setString(_queueKey, jsonEncode(queue));
    unawaited(flush(session));
  }

  static Future<void> flush(AppSession session) async {
    final prefs = await SharedPreferences.getInstance();
    final List<dynamic> queue = jsonDecode(prefs.getString(_queueKey) ?? '[]') as List<dynamic>;
    final List<dynamic> remaining = <dynamic>[];
    for (final dynamic raw in queue) {
      try {
        final item = Map<String, dynamic>.from(raw as Map);
        final response = await Api.postJson(Uri.parse(kPosHandoverUrl), item, bearerToken: session.activationToken);
        if (response['success'] != true) remaining.add(item);
      } catch (_) {
        remaining.add(raw);
      }
    }
    await prefs.setString(_queueKey, jsonEncode(remaining));
  }
}
