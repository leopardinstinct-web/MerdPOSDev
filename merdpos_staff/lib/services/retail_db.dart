part of merdpos_staff;

class RetailDb {
  static Database? _db;

  static final List<Map<String, Object?>> _webProducts =
      <Map<String, Object?>>[];
  static final List<Map<String, Object?>> _webSales = <Map<String, Object?>>[];
  static final List<Map<String, Object?>> _webSaleLines =
      <Map<String, Object?>>[];
  static final List<Map<String, Object?>> _webMovements =
      <Map<String, Object?>>[];
  static int _webProductId = 0;
  static int _webSaleId = 0;

  static Future<Database> get database async {
    if (kIsWeb) {
      throw UnsupportedError('SQLite is not available in Chrome.');
    }
    if (_db != null) return _db!;
    final String path = p.join(await getDatabasesPath(), 'merdpos_retail.db');
    _db = await openDatabase(path, version: 1, onCreate: _create);
    await _seed(_db!);
    return _db!;
  }

  static void _seedWeb() {
    if (_webProducts.isNotEmpty) return;
    final String now = DateTime.now().toIso8601String();
    final List<List<Object>> rows = <List<Object>>[
      <Object>['930000000001', 'Still Water 600ml', 'Drinks', 3.50, 1.20, 48.0],
      <Object>['930000000002', 'Cola 375ml', 'Drinks', 4.00, 1.60, 36.0],
      <Object>[
        '930000000003',
        'Energy Drink 500ml',
        'Drinks',
        6.50,
        3.20,
        24.0,
      ],
      <Object>['930000000004', 'Salted Chips 175g', 'Snacks', 5.50, 2.40, 20.0],
      <Object>[
        '930000000005',
        'Chocolate Bar',
        'Confectionery',
        3.20,
        1.10,
        42.0,
      ],
      <Object>['930000000006', 'Mint Gum', 'Confectionery', 2.80, 0.90, 30.0],
    ];
    for (final List<Object> row in rows) {
      _webProducts.add(<String, Object?>{
        'id': ++_webProductId,
        'barcode': row[0],
        'name': row[1],
        'category': row[2],
        'price': row[3],
        'cost': row[4],
        'stock': row[5],
        'active': 1,
        'updated_at': now,
      });
    }
  }

  static Future<void> _create(Database db, int version) async {
    await db.execute('''CREATE TABLE products(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      barcode TEXT NOT NULL UNIQUE,
      name TEXT NOT NULL,
      category TEXT NOT NULL DEFAULT 'General',
      price REAL NOT NULL DEFAULT 0,
      cost REAL NOT NULL DEFAULT 0,
      stock REAL NOT NULL DEFAULT 0,
      active INTEGER NOT NULL DEFAULT 1,
      updated_at TEXT NOT NULL
    )''');
    await db.execute('''CREATE TABLE sales(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      sale_number TEXT NOT NULL UNIQUE,
      client_id INTEGER NOT NULL,
      store_id INTEGER NOT NULL,
      cashier_id INTEGER NOT NULL,
      cashier_name TEXT NOT NULL,
      subtotal REAL NOT NULL,
      discount REAL NOT NULL DEFAULT 0,
      tax REAL NOT NULL DEFAULT 0,
      total REAL NOT NULL,
      payment_method TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'completed',
      sync_status TEXT NOT NULL DEFAULT 'pending',
      created_at TEXT NOT NULL
    )''');
    await db.execute('''CREATE TABLE sale_lines(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      sale_id INTEGER NOT NULL,
      product_id INTEGER NOT NULL,
      barcode TEXT NOT NULL,
      product_name TEXT NOT NULL,
      quantity REAL NOT NULL,
      unit_price REAL NOT NULL,
      unit_cost REAL NOT NULL,
      line_total REAL NOT NULL,
      FOREIGN KEY(sale_id) REFERENCES sales(id)
    )''');
    await db.execute('''CREATE TABLE stock_movements(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      product_id INTEGER NOT NULL,
      movement_type TEXT NOT NULL,
      quantity REAL NOT NULL,
      reference TEXT NOT NULL,
      note TEXT,
      sync_status TEXT NOT NULL DEFAULT 'pending',
      created_at TEXT NOT NULL
    )''');
  }

  static Future<void> _seed(Database db) async {
    final int count =
        Sqflite.firstIntValue(
          await db.rawQuery('SELECT COUNT(*) FROM products'),
        ) ??
        0;
    if (count > 0) return;
    final String now = DateTime.now().toIso8601String();
    final List<List<Object>> rows = <List<Object>>[
      <Object>['930000000001', 'Still Water 600ml', 'Drinks', 3.50, 1.20, 48],
      <Object>['930000000002', 'Cola 375ml', 'Drinks', 4.00, 1.60, 36],
      <Object>['930000000003', 'Energy Drink 500ml', 'Drinks', 6.50, 3.20, 24],
      <Object>['930000000004', 'Salted Chips 175g', 'Snacks', 5.50, 2.40, 20],
      <Object>[
        '930000000005',
        'Chocolate Bar',
        'Confectionery',
        3.20,
        1.10,
        42,
      ],
      <Object>['930000000006', 'Mint Gum', 'Confectionery', 2.80, 0.90, 30],
    ];
    final Batch batch = db.batch();
    for (final List<Object> row in rows) {
      batch.insert('products', <String, Object?>{
        'barcode': row[0],
        'name': row[1],
        'category': row[2],
        'price': row[3],
        'cost': row[4],
        'stock': row[5],
        'active': 1,
        'updated_at': now,
      });
    }
    await batch.commit(noResult: true);
  }

  static Future<List<RetailProduct>> searchProducts(String query) async {
    if (kIsWeb) {
      _seedWeb();
      final String q = query.trim().toLowerCase();
      return _webProducts
          .where((Map<String, Object?> row) => row['active'] == 1)
          .where(
            (Map<String, Object?> row) =>
                q.isEmpty ||
                row['name'].toString().toLowerCase().contains(q) ||
                row['barcode'].toString().toLowerCase().contains(q) ||
                row['category'].toString().toLowerCase().contains(q),
          )
          .map(RetailProduct.fromMap)
          .toList()
        ..sort((RetailProduct a, RetailProduct b) => a.name.compareTo(b.name));
    }
    final Database db = await database;
    final String q = query.trim();
    final List<Map<String, Object?>> rows = await db.query(
      'products',
      where: q.isEmpty
          ? 'active = 1'
          : 'active = 1 AND (name LIKE ? OR barcode LIKE ? OR category LIKE ?)',
      whereArgs: q.isEmpty ? null : <Object?>['%$q%', '%$q%', '%$q%'],
      orderBy: 'name ASC',
      limit: 100,
    );
    return rows.map(RetailProduct.fromMap).toList();
  }

  static Future<void> saveProduct({
    int? id,
    required String barcode,
    required String name,
    required String category,
    required double price,
    required double cost,
    required double stock,
  }) async {
    if (kIsWeb) {
      _seedWeb();
      final Map<String, Object?> values = <String, Object?>{
        'id': id ?? ++_webProductId,
        'barcode': barcode.trim(),
        'name': name.trim(),
        'category': category.trim().isEmpty ? 'General' : category.trim(),
        'price': price,
        'cost': cost,
        'stock': stock,
        'active': 1,
        'updated_at': DateTime.now().toIso8601String(),
      };
      if (id == null) {
        if (_webProducts.any(
          (Map<String, Object?> row) => row['barcode'] == barcode.trim(),
        )) {
          throw StateError('Barcode already exists.');
        }
        _webProducts.add(values);
      } else {
        final int index = _webProducts.indexWhere(
          (Map<String, Object?> row) => row['id'] == id,
        );
        if (index >= 0) _webProducts[index] = values;
      }
      return;
    }
    final Database db = await database;
    final Map<String, Object?> values = <String, Object?>{
      'barcode': barcode.trim(),
      'name': name.trim(),
      'category': category.trim().isEmpty ? 'General' : category.trim(),
      'price': price,
      'cost': cost,
      'stock': stock,
      'active': 1,
      'updated_at': DateTime.now().toIso8601String(),
    };
    if (id == null) {
      await db.insert(
        'products',
        values,
        conflictAlgorithm: ConflictAlgorithm.abort,
      );
    } else {
      await db.update(
        'products',
        values,
        where: 'id = ?',
        whereArgs: <Object?>[id],
      );
    }
  }

  static Future<int> completeSale({
    required AppSession session,
    required Employee cashier,
    required List<BasketLine> lines,
    required String paymentMethod,
  }) async {
    if (lines.isEmpty) throw StateError('Basket is empty.');
    if (kIsWeb) {
      _seedWeb();
      for (final BasketLine line in lines) {
        final int index = _webProducts.indexWhere(
          (Map<String, Object?> row) => row['id'] == line.product.id,
        );
        if (index < 0) throw StateError('Product no longer exists.');
        final double stock = (_webProducts[index]['stock'] as num).toDouble();
        if (stock < line.quantity)
          throw StateError('Not enough stock for ${line.product.name}.');
      }
      final DateTime now = DateTime.now();
      final int saleId = ++_webSaleId;
      final String saleNumber = 'S${now.millisecondsSinceEpoch}';
      final double total = lines.fold<double>(
        0,
        (double sum, BasketLine line) => sum + line.total,
      );
      _webSales.add(<String, Object?>{
        'id': saleId,
        'sale_number': saleNumber,
        'client_id': session.clientId,
        'store_id': session.storeId,
        'cashier_id': cashier.id,
        'cashier_name': cashier.fullName,
        'subtotal': total,
        'discount': 0.0,
        'tax': 0.0,
        'total': total,
        'payment_method': paymentMethod,
        'status': 'completed',
        'sync_status': 'pending',
        'created_at': now.toIso8601String(),
      });
      for (final BasketLine line in lines) {
        final int index = _webProducts.indexWhere(
          (Map<String, Object?> row) => row['id'] == line.product.id,
        );
        _webProducts[index]['stock'] =
            (_webProducts[index]['stock'] as num).toDouble() - line.quantity;
        _webSaleLines.add(<String, Object?>{
          'sale_id': saleId,
          'product_id': line.product.id,
          'barcode': line.product.barcode,
          'product_name': line.product.name,
          'quantity': line.quantity,
          'unit_price': line.product.price,
          'unit_cost': line.product.cost,
          'line_total': line.total,
        });
        _webMovements.add(<String, Object?>{
          'product_id': line.product.id,
          'movement_type': 'sale',
          'quantity': -line.quantity,
          'reference': saleNumber,
          'note': 'POS sale',
          'sync_status': 'pending',
          'created_at': now.toIso8601String(),
        });
      }
      return saleId;
    }
    final Database db = await database;
    return db.transaction((Transaction txn) async {
      for (final BasketLine line in lines) {
        final List<Map<String, Object?>> rows = await txn.query(
          'products',
          columns: <String>['stock'],
          where: 'id = ?',
          whereArgs: <Object?>[line.product.id],
          limit: 1,
        );
        if (rows.isEmpty) throw StateError('Product no longer exists.');
        final double stock = (rows.first['stock'] as num).toDouble();
        if (stock < line.quantity)
          throw StateError('Not enough stock for ${line.product.name}.');
      }
      final DateTime now = DateTime.now();
      final String saleNumber = 'S${now.millisecondsSinceEpoch}';
      final double total = lines.fold<double>(
        0,
        (double sum, BasketLine line) => sum + line.total,
      );
      final int saleId = await txn.insert('sales', <String, Object?>{
        'sale_number': saleNumber,
        'client_id': session.clientId,
        'store_id': session.storeId,
        'cashier_id': cashier.id,
        'cashier_name': cashier.fullName,
        'subtotal': total,
        'discount': 0,
        'tax': 0,
        'total': total,
        'payment_method': paymentMethod,
        'status': 'completed',
        'sync_status': 'pending',
        'created_at': now.toIso8601String(),
      });
      for (final BasketLine line in lines) {
        await txn.insert('sale_lines', <String, Object?>{
          'sale_id': saleId,
          'product_id': line.product.id,
          'barcode': line.product.barcode,
          'product_name': line.product.name,
          'quantity': line.quantity,
          'unit_price': line.product.price,
          'unit_cost': line.product.cost,
          'line_total': line.total,
        });
        await txn.rawUpdate(
          'UPDATE products SET stock = stock - ?, updated_at = ? WHERE id = ?',
          <Object?>[line.quantity, now.toIso8601String(), line.product.id],
        );
        await txn.insert('stock_movements', <String, Object?>{
          'product_id': line.product.id,
          'movement_type': 'sale',
          'quantity': -line.quantity,
          'reference': saleNumber,
          'note': 'POS sale',
          'sync_status': 'pending',
          'created_at': now.toIso8601String(),
        });
      }
      return saleId;
    });
  }

  static Future<List<RetailSale>> sales({int limit = 100}) async {
    if (kIsWeb) {
      final List<Map<String, Object?>> rows = List<Map<String, Object?>>.from(
        _webSales.reversed.take(limit),
      );
      return rows.map(RetailSale.fromMap).toList();
    }
    final Database db = await database;
    final List<Map<String, Object?>> rows = await db.query(
      'sales',
      orderBy: 'created_at DESC',
      limit: limit,
    );
    return rows.map(RetailSale.fromMap).toList();
  }

  static Future<Map<String, double>> financialSummary() async {
    if (kIsWeb) {
      final DateTime today = DateTime.now();
      final DateTime start = DateTime(today.year, today.month, today.day);
      final List<Map<String, Object?>> salesToday = _webSales
          .where(
            (Map<String, Object?> row) =>
                row['status'] == 'completed' &&
                DateTime.parse(
                  row['created_at'].toString(),
                ).isAfter(start.subtract(const Duration(microseconds: 1))),
          )
          .toList();
      final double revenue = salesToday.fold<double>(
        0,
        (double sum, Map<String, Object?> row) =>
            sum + (row['total'] as num).toDouble(),
      );
      double margin = 0;
      for (final Map<String, Object?> line in _webSaleLines) {
        if (salesToday.any(
          (Map<String, Object?> sale) => sale['id'] == line['sale_id'],
        )) {
          margin +=
              ((line['unit_price'] as num).toDouble() -
                  (line['unit_cost'] as num).toDouble()) *
              (line['quantity'] as num).toDouble();
        }
      }
      return <String, double>{
        'revenue': revenue,
        'transactions': salesToday.length.toDouble(),
        'margin': margin,
      };
    }
    final Database db = await database;
    final DateTime today = DateTime.now();
    final String start = DateTime(
      today.year,
      today.month,
      today.day,
    ).toIso8601String();
    final List<Map<String, Object?>> rows = await db.rawQuery(
      '''SELECT COALESCE(SUM(total),0) revenue,
      COUNT(*) transactions FROM sales WHERE status = 'completed' AND created_at >= ?''',
      <Object?>[start],
    );
    final List<Map<String, Object?>> marginRows = await db.rawQuery(
      '''SELECT COALESCE(SUM((sl.unit_price-sl.unit_cost)*sl.quantity),0) margin
      FROM sale_lines sl JOIN sales s ON s.id=sl.sale_id WHERE s.status='completed' AND s.created_at >= ?''',
      <Object?>[start],
    );
    return <String, double>{
      'revenue': (rows.first['revenue'] as num).toDouble(),
      'transactions': (rows.first['transactions'] as num).toDouble(),
      'margin': (marginRows.first['margin'] as num).toDouble(),
    };
  }

  static Future<void> adjustStock(
    int productId,
    double delta,
    String note,
  ) async {
    if (kIsWeb) {
      _seedWeb();
      final int index = _webProducts.indexWhere(
        (Map<String, Object?> row) => row['id'] == productId,
      );
      if (index < 0) throw StateError('Product not found.');
      _webProducts[index]['stock'] =
          (_webProducts[index]['stock'] as num).toDouble() + delta;
      _webMovements.add(<String, Object?>{
        'product_id': productId,
        'movement_type': 'adjustment',
        'quantity': delta,
        'reference': const Uuid().v4(),
        'note': note,
        'sync_status': 'pending',
        'created_at': DateTime.now().toIso8601String(),
      });
      return;
    }
    final Database db = await database;
    await db.transaction((Transaction txn) async {
      await txn.rawUpdate(
        'UPDATE products SET stock = stock + ?, updated_at = ? WHERE id = ?',
        <Object?>[delta, DateTime.now().toIso8601String(), productId],
      );
      await txn.insert('stock_movements', <String, Object?>{
        'product_id': productId,
        'movement_type': 'adjustment',
        'quantity': delta,
        'reference': const Uuid().v4(),
        'note': note,
        'sync_status': 'pending',
        'created_at': DateTime.now().toIso8601String(),
      });
    });
  }

  static Future<Map<String, dynamic>> pendingSyncPayload(
    AppSession session,
  ) async {
    if (kIsWeb) {
      final List<Map<String, Object?>> pendingSales = _webSales
          .where((Map<String, Object?> row) => row['sync_status'] == 'pending')
          .map((Map<String, Object?> sale) {
            final Map<String, Object?> copy = Map<String, Object?>.from(sale);
            copy['lines'] = _webSaleLines
                .where(
                  (Map<String, Object?> line) => line['sale_id'] == sale['id'],
                )
                .toList();
            return copy;
          })
          .toList();
      final List<Map<String, Object?>> movements = _webMovements
          .where((Map<String, Object?> row) => row['sync_status'] == 'pending')
          .toList();
      return <String, dynamic>{
        'client_id': session.clientId,
        'store_id': session.storeId,
        'device_uuid': session.deviceUuid,
        'sales': pendingSales,
        'stock_movements': movements,
      };
    }
    final Database db = await database;
    final List<Map<String, Object?>> salesRows = await db.query(
      'sales',
      where: 'sync_status = ?',
      whereArgs: <Object?>['pending'],
    );
    final List<Map<String, dynamic>> sales = <Map<String, dynamic>>[];
    for (final Map<String, Object?> sale in salesRows) {
      final List<Map<String, Object?>> lines = await db.query(
        'sale_lines',
        where: 'sale_id = ?',
        whereArgs: <Object?>[sale['id']],
      );
      sales.add(<String, dynamic>{...sale, 'lines': lines});
    }
    final List<Map<String, Object?>> movements = await db.query(
      'stock_movements',
      where: 'sync_status = ?',
      whereArgs: <Object?>['pending'],
    );
    return <String, dynamic>{
      'client_id': session.clientId,
      'store_id': session.storeId,
      'device_uuid': session.deviceUuid,
      'sales': sales,
      'stock_movements': movements,
    };
  }

  static Future<int> sync(AppSession session) async {
    final Map<String, dynamic> payload = await pendingSyncPayload(session);
    final List<dynamic> sales = payload['sales'] as List<dynamic>;
    final List<dynamic> movements = payload['stock_movements'] as List<dynamic>;
    if (sales.isEmpty && movements.isEmpty) return 0;
    final Map<String, dynamic> response = await Api.postJson(
      Uri.parse(kRetailSyncUrl),
      payload,
      bearerToken: session.activationToken,
    );
    if (response['success'] != true)
      throw Exception(response['error']?.toString() ?? 'Retail sync failed.');
    if (kIsWeb) {
      for (final Map<String, Object?> sale in _webSales) {
        sale['sync_status'] = 'synced';
      }
      for (final Map<String, Object?> movement in _webMovements) {
        movement['sync_status'] = 'synced';
      }
      return sales.length + movements.length;
    }
    final Database db = await database;
    await db.transaction((Transaction txn) async {
      await txn.update(
        'sales',
        <String, Object?>{'sync_status': 'synced'},
        where: 'sync_status = ?',
        whereArgs: <Object?>['pending'],
      );
      await txn.update(
        'stock_movements',
        <String, Object?>{'sync_status': 'synced'},
        where: 'sync_status = ?',
        whereArgs: <Object?>['pending'],
      );
    });
    return sales.length + movements.length;
  }
}
