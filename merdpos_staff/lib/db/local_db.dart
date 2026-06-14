import 'package:path/path.dart';
import 'package:sqflite/sqflite.dart';

class LocalDb {
  static Database? _db;

  static Future<Database> get database async {
    if (_db != null) return _db!;

    final dbPath = await getDatabasesPath();
    final path = join(dbPath, 'merdpos_staff.db');

    _db = await openDatabase(
      path,
      version: 1,
      onCreate: (db, version) async {
        await db.execute('''
          CREATE TABLE employee_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            store_id INTEGER NOT NULL,
            employee_id INTEGER NOT NULL,
            user_name TEXT NOT NULL,
            store_name TEXT NOT NULL,
            log_type TEXT NOT NULL,
            log_date TEXT NOT NULL,
            log_time TEXT NOT NULL,
            log_datetime TEXT NOT NULL,
            device_uuid TEXT NOT NULL,
            local_log_id TEXT NOT NULL UNIQUE,
            sync_status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL
          )
        ''');
      },
    );

    return _db!;
  }

  static Future<int> insertEmployeeLog(Map<String, dynamic> log) async {
    final db = await database;
    return db.insert(
      'employee_logs',
      log,
      conflictAlgorithm: ConflictAlgorithm.ignore,
    );
  }

  static Future<List<Map<String, dynamic>>> getLogsForEmployee({
    required int employeeId,
  }) async {
    final db = await database;

    return db.query(
      'employee_logs',
      where: 'employee_id = ?',
      whereArgs: [employeeId],
      orderBy: 'log_datetime DESC',
    );
  }

  static Future<List<Map<String, dynamic>>> getAllLogs() async {
    final db = await database;

    return db.query(
      'employee_logs',
      orderBy: 'log_datetime DESC',
    );
  }

  static Future<List<Map<String, dynamic>>> getPendingLogs() async {
    final db = await database;

    return db.query(
      'employee_logs',
      where: 'sync_status = ?',
      whereArgs: ['pending'],
      orderBy: 'log_datetime ASC',
    );
  }

  static Future<void> markLogsSynced(List<String> localLogIds) async {
    final db = await database;

    for (final id in localLogIds) {
      await db.update(
        'employee_logs',
        {'sync_status': 'synced'},
        where: 'local_log_id = ?',
        whereArgs: [id],
      );
    }
  }
}