part of merdpos_staff;

class RetailSyncHealth {
  const RetailSyncHealth({
    required this.status,
    required this.pendingMovements,
    required this.rejectedMovements,
    this.lastSuccessAtUtc,
    this.lastErrorMessage,
  });

  final String status;
  final int pendingMovements;
  final int rejectedMovements;
  final String? lastSuccessAtUtc;
  final String? lastErrorMessage;

  bool get needsAttention =>
      status == 'failed' || rejectedMovements > 0 || pendingMovements > 0;
}

class RetailDb {
  static const int schemaVersion = 5;
  static const bool developmentCatalogueFixtures = bool.fromEnvironment(
    'MERDPOS_DEVELOPMENT_CATALOGUE_FIXTURES',
    defaultValue: false,
  );
  static Database? _db;

  @visibleForTesting
  static Future<void> createSchemaForTesting(Database db) =>
      _create(db, schemaVersion);

  @visibleForTesting
  static Future<void> migrateSchemaForTesting(Database db, int oldVersion) =>
      _upgrade(db, oldVersion, schemaVersion);

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
    _db = await openDatabase(
      path,
      version: schemaVersion,
      onConfigure: (Database db) => db.execute('PRAGMA foreign_keys = ON'),
      onCreate: _create,
      onUpgrade: _upgrade,
    );
    if (developmentCatalogueFixtures) await _seed(_db!);
    return _db!;
  }

  static void _seedWeb() {
    if (!developmentCatalogueFixtures) return;
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
    await _createTransactionTables(db);
    await _createCatalogueTables(db);
    await _createIncrementalCatalogueTables(db);
    await _createStockSyncTables(db);
    await _createDurableSaleTables(db);
  }

  static Future<void> _createTransactionTables(DatabaseExecutor db) async {
    await db.execute('''CREATE TABLE legacy_products(
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
      created_at TEXT NOT NULL,
      sale_uid TEXT UNIQUE,
      device_uuid TEXT,
      occurred_at_utc TEXT,
      accepted_at_utc TEXT,
      server_sale_id INTEGER,
      currency_code TEXT,
      subtotal_decimal TEXT,
      manual_discount_decimal TEXT,
      tax_decimal TEXT,
      total_decimal TEXT,
      receipt_contract_version TEXT,
      sync_error_code TEXT,
      sync_error_message TEXT
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
      server_product_id INTEGER,
      catalogue_unit_price TEXT,
      unit_of_measure TEXT,
      tax_code TEXT,
      tax_rate_basis_points INTEGER,
      tax_inclusive INTEGER,
      line_uid TEXT,
      sku_snapshot TEXT,
      original_unit_price TEXT,
      price_type TEXT,
      price_version_id INTEGER,
      promotion_name TEXT,
      campaign_reference TEXT,
      automatic_promotion_json TEXT,
      manual_discount_amount TEXT,
      manual_discount_reason TEXT,
      manual_discount_actor_id TEXT,
      taxable_amount TEXT,
      net_amount TEXT,
      tax_amount TEXT,
      gross_amount TEXT,
      currency_code TEXT,
      tax_rate_version_id INTEGER,
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
      created_at TEXT NOT NULL,
      server_product_id INTEGER,
      quantity_decimal TEXT,
      server_movement_id INTEGER,
      acknowledged_at_utc TEXT,
      rejection_code TEXT,
      rejection_message TEXT
    )''');
  }

  static Future<void> _upgrade(
    Database db,
    int oldVersion,
    int newVersion,
  ) async {
    if (oldVersion > schemaVersion) {
      throw StateError(
        'Retail database schema $oldVersion is newer than supported schema $schemaVersion.',
      );
    }
    if (oldVersion < 2) {
      // openDatabase runs onUpgrade in its own transaction.
      await db.execute('ALTER TABLE products RENAME TO legacy_products');
      await db.execute(
        'ALTER TABLE sale_lines ADD COLUMN server_product_id INTEGER',
      );
      await db.execute(
        'ALTER TABLE sale_lines ADD COLUMN catalogue_unit_price TEXT',
      );
      await db.execute(
        'ALTER TABLE sale_lines ADD COLUMN unit_of_measure TEXT',
      );
      await db.execute('ALTER TABLE sale_lines ADD COLUMN tax_code TEXT');
      await db.execute(
        'ALTER TABLE sale_lines ADD COLUMN tax_rate_basis_points INTEGER',
      );
      await db.execute(
        'ALTER TABLE sale_lines ADD COLUMN tax_inclusive INTEGER',
      );
      await db.execute(
        'ALTER TABLE stock_movements ADD COLUMN server_product_id INTEGER',
      );
      await _createCatalogueTables(db);
    }
    if (oldVersion >= 2 && oldVersion < 3) {
      await db.execute(
        'ALTER TABLE catalogue_sync_state ADD COLUMN snapshot_json TEXT',
      );
    }
    if (oldVersion < 3) await _createIncrementalCatalogueTables(db);
    if (oldVersion < 4) {
      await db.execute(
        'ALTER TABLE stock_movements ADD COLUMN quantity_decimal TEXT',
      );
      await db.execute(
        'ALTER TABLE stock_movements ADD COLUMN server_movement_id INTEGER',
      );
      await db.execute(
        'ALTER TABLE stock_movements ADD COLUMN acknowledged_at_utc TEXT',
      );
      await db.execute(
        'ALTER TABLE stock_movements ADD COLUMN rejection_code TEXT',
      );
      await db.execute(
        'ALTER TABLE stock_movements ADD COLUMN rejection_message TEXT',
      );
      await _createStockSyncTables(db);
    }
    if (oldVersion < 5) {
      for (final String statement in <String>[
        'ALTER TABLE sales ADD COLUMN sale_uid TEXT',
        'ALTER TABLE sales ADD COLUMN device_uuid TEXT',
        'ALTER TABLE sales ADD COLUMN occurred_at_utc TEXT',
        'ALTER TABLE sales ADD COLUMN accepted_at_utc TEXT',
        'ALTER TABLE sales ADD COLUMN server_sale_id INTEGER',
        'ALTER TABLE sales ADD COLUMN currency_code TEXT',
        'ALTER TABLE sales ADD COLUMN subtotal_decimal TEXT',
        'ALTER TABLE sales ADD COLUMN manual_discount_decimal TEXT',
        'ALTER TABLE sales ADD COLUMN tax_decimal TEXT',
        'ALTER TABLE sales ADD COLUMN total_decimal TEXT',
        'ALTER TABLE sales ADD COLUMN receipt_contract_version TEXT',
        'ALTER TABLE sales ADD COLUMN sync_error_code TEXT',
        'ALTER TABLE sales ADD COLUMN sync_error_message TEXT',
        'ALTER TABLE sale_lines ADD COLUMN line_uid TEXT',
        'ALTER TABLE sale_lines ADD COLUMN sku_snapshot TEXT',
        'ALTER TABLE sale_lines ADD COLUMN original_unit_price TEXT',
        'ALTER TABLE sale_lines ADD COLUMN price_type TEXT',
        'ALTER TABLE sale_lines ADD COLUMN price_version_id INTEGER',
        'ALTER TABLE sale_lines ADD COLUMN promotion_name TEXT',
        'ALTER TABLE sale_lines ADD COLUMN campaign_reference TEXT',
        'ALTER TABLE sale_lines ADD COLUMN automatic_promotion_json TEXT',
        'ALTER TABLE sale_lines ADD COLUMN manual_discount_amount TEXT',
        'ALTER TABLE sale_lines ADD COLUMN manual_discount_reason TEXT',
        'ALTER TABLE sale_lines ADD COLUMN manual_discount_actor_id TEXT',
        'ALTER TABLE sale_lines ADD COLUMN taxable_amount TEXT',
        'ALTER TABLE sale_lines ADD COLUMN net_amount TEXT',
        'ALTER TABLE sale_lines ADD COLUMN tax_amount TEXT',
        'ALTER TABLE sale_lines ADD COLUMN gross_amount TEXT',
        'ALTER TABLE sale_lines ADD COLUMN currency_code TEXT',
        'ALTER TABLE sale_lines ADD COLUMN tax_rate_version_id INTEGER',
      ]) {
        await db.execute(statement);
      }
      await _createDurableSaleTables(db);
    }
  }

  static Future<void> _createCatalogueTables(DatabaseExecutor db) async {
    await db.execute('''CREATE TABLE catalogue_categories(
      server_id INTEGER PRIMARY KEY,
      name TEXT NOT NULL,
      status TEXT NOT NULL
    )''');
    await db.execute('''CREATE TABLE products(
      id INTEGER PRIMARY KEY,
      category_id INTEGER,
      sku TEXT,
      sku_normalized TEXT,
      name TEXT NOT NULL,
      description TEXT,
      unit_of_measure TEXT NOT NULL CHECK(unit_of_measure IN ('each','kilogram','litre')),
      lifecycle TEXT NOT NULL CHECK(lifecycle IN ('active','disabled','archived','tombstoned')),
      archived_at_utc TEXT,
      tombstoned_at_utc TEXT,
      primary_barcode TEXT NOT NULL DEFAULT '',
      category TEXT NOT NULL DEFAULT '',
      resolved_price TEXT,
      price REAL,
      cost REAL NOT NULL DEFAULT 0,
      stock_balance TEXT NOT NULL,
      stock REAL NOT NULL,
      stock_revision INTEGER NOT NULL,
      store_available INTEGER NOT NULL CHECK(store_available IN (0,1)),
      reorder_level TEXT,
      tax_code_id INTEGER,
      tax_code TEXT,
      tax_rate_version_id INTEGER,
      tax_rate_basis_points INTEGER,
      tax_inclusive INTEGER,
      sellable INTEGER NOT NULL CHECK(sellable IN (0,1)),
      not_sellable_reasons_json TEXT NOT NULL,
      negative_stock_exception_json TEXT,
      active INTEGER NOT NULL,
      updated_at TEXT NOT NULL,
      FOREIGN KEY(category_id) REFERENCES catalogue_categories(server_id)
    )''');
    await db.execute('''CREATE TABLE catalogue_barcodes(
      server_id INTEGER PRIMARY KEY,
      product_id INTEGER NOT NULL,
      barcode TEXT NOT NULL UNIQUE,
      is_primary INTEGER NOT NULL CHECK(is_primary IN (0,1)),
      FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
    )''');
    await db.execute('''CREATE TABLE catalogue_tax_codes(
      server_id INTEGER PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL, status TEXT NOT NULL
    )''');
    await db.execute('''CREATE TABLE catalogue_tax_rates(
      server_id INTEGER PRIMARY KEY, tax_code_id INTEGER NOT NULL,
      rate_basis_points INTEGER NOT NULL, effective_from_utc TEXT NOT NULL, effective_to_utc TEXT,
      FOREIGN KEY(tax_code_id) REFERENCES catalogue_tax_codes(server_id)
    )''');
    await db.execute('''CREATE TABLE catalogue_product_tax_assignments(
      server_id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, tax_code_id INTEGER NOT NULL,
      effective_from_utc TEXT NOT NULL, effective_to_utc TEXT,
      FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
      FOREIGN KEY(tax_code_id) REFERENCES catalogue_tax_codes(server_id)
    )''');
    await db.execute('''CREATE TABLE catalogue_effective_prices(
      server_id INTEGER PRIMARY KEY, product_id INTEGER NOT NULL, scope TEXT NOT NULL,
      store_id INTEGER, price_type TEXT NOT NULL, precedence_rank INTEGER NOT NULL,
      unit_price TEXT NOT NULL, currency_code TEXT NOT NULL,
      effective_from_utc TEXT NOT NULL, effective_to_utc TEXT,
      promotion_name TEXT, campaign_reference TEXT,
      FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
    )''');
    await db.execute('''CREATE TABLE catalogue_warnings(
      id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL, product_id INTEGER,
      details_json TEXT, FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
    )''');
    await db.execute('''CREATE TABLE catalogue_sync_state(
      singleton INTEGER PRIMARY KEY CHECK(singleton=1), contract_version TEXT,
      snapshot_revision TEXT, cursor_seed TEXT, snapshot_generated_at_utc TEXT,
      snapshot_json TEXT,
      committed_at_utc TEXT, last_attempt_at_utc TEXT, last_attempt_status TEXT NOT NULL,
      last_error_code TEXT, last_error_message TEXT, stale INTEGER NOT NULL DEFAULT 0
    )''');
    await db.execute(
      "INSERT INTO catalogue_sync_state(singleton,last_attempt_status,stale) VALUES(1,'never',0)",
    );
    await db.execute('''CREATE TABLE catalogue_tombstones(
      entity_type TEXT NOT NULL, server_id INTEGER NOT NULL, tombstoned_at_utc TEXT,
      purge_after_cursor TEXT, PRIMARY KEY(entity_type,server_id)
    )''');
    for (final String table in <String>[
      'categories',
      'products',
      'barcodes',
      'tax_codes',
      'tax_rates',
      'product_tax_assignments',
      'effective_prices',
      'warnings',
    ]) {
      await db.execute(
        'CREATE TABLE catalogue_stage_$table(payload_json TEXT NOT NULL)',
      );
    }
    await db.execute(
      'CREATE INDEX catalogue_barcodes_product_idx ON catalogue_barcodes(product_id)',
    );
    await db.execute(
      'CREATE INDEX catalogue_prices_product_idx ON catalogue_effective_prices(product_id,precedence_rank)',
    );
  }

  static Future<void> _createIncrementalCatalogueTables(
    DatabaseExecutor db,
  ) async {
    await db.execute('''CREATE TABLE catalogue_incremental_pages(
      batch_token TEXT NOT NULL,
      page_index INTEGER NOT NULL,
      page_count INTEGER NOT NULL,
      source_cursor TEXT NOT NULL,
      target_cursor TEXT,
      target_snapshot_revision TEXT NOT NULL,
      response_json TEXT NOT NULL,
      received_at_utc TEXT NOT NULL,
      PRIMARY KEY(batch_token,page_index)
    )''');
    await db.execute(
      'CREATE INDEX catalogue_incremental_source_idx ON catalogue_incremental_pages(source_cursor,batch_token)',
    );
  }

  static Future<void> _createStockSyncTables(DatabaseExecutor db) async {
    await db.execute('''CREATE TABLE retail_sync_state(
      singleton INTEGER PRIMARY KEY CHECK(singleton=1),
      last_attempt_at_utc TEXT,
      last_success_at_utc TEXT,
      last_attempt_status TEXT NOT NULL DEFAULT 'never',
      last_error_message TEXT,
      pending_movement_count INTEGER NOT NULL DEFAULT 0,
      rejected_movement_count INTEGER NOT NULL DEFAULT 0
    )''');
    await db.execute(
      "INSERT INTO retail_sync_state(singleton,last_attempt_status) VALUES(1,'never')",
    );
  }

  static Future<void> _createDurableSaleTables(DatabaseExecutor db) async {
    await db.execute(
      'CREATE UNIQUE INDEX IF NOT EXISTS sales_uid_idx ON sales(sale_uid) WHERE sale_uid IS NOT NULL',
    );
    await db.execute(
      'CREATE UNIQUE INDEX IF NOT EXISTS sale_lines_uid_idx ON sale_lines(sale_id,line_uid) WHERE line_uid IS NOT NULL',
    );
    await db.execute('''CREATE TABLE sale_tenders(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      tender_uid TEXT NOT NULL UNIQUE,
      sale_id INTEGER NOT NULL UNIQUE,
      tender_type TEXT NOT NULL CHECK(tender_type IN ('cash','card_recorded')),
      currency_code TEXT NOT NULL,
      amount_due TEXT NOT NULL,
      amount_tendered TEXT NOT NULL,
      change_due TEXT NOT NULL,
      recorded_at_utc TEXT NOT NULL,
      FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE RESTRICT
    )''');
    await db.execute('''CREATE TABLE sale_outbox(
      sale_id INTEGER PRIMARY KEY,
      state TEXT NOT NULL CHECK(state IN ('pending','sending','acknowledged','rejected')),
      attempt_count INTEGER NOT NULL DEFAULT 0,
      next_attempt_at_utc TEXT,
      last_attempt_at_utc TEXT,
      last_error_code TEXT,
      last_error_message TEXT,
      created_at_utc TEXT NOT NULL,
      acknowledged_at_utc TEXT,
      FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE RESTRICT
    )''');
  }

  static Future<void> _seed(Database db) async {
    final int count =
        Sqflite.firstIntValue(
          await db.rawQuery('SELECT COUNT(*) FROM products'),
        ) ??
        0;
    if (count > 0) return;
    // Development fixtures are intentionally not synthesized in SQLite.
    // Developers can opt in to the web-only fixture with the compile-time flag;
    // native development should use a synthetic M2.4 snapshot.
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
    final List<Map<String, Object?>> rows = await db.rawQuery(
      '''
      SELECT p.*,
        p.primary_barcode AS barcode,
        CAST(p.stock_balance AS REAL) + COALESCE((
          SELECT SUM(sm.quantity) FROM stock_movements sm
          WHERE sm.server_product_id=p.id AND sm.sync_status IN ('pending','rejected')
        ),0) AS stock
      FROM products p
      WHERE p.sellable=1 AND (
        ?='' OR p.name LIKE ? OR p.sku LIKE ? OR p.category LIKE ? OR EXISTS(
          SELECT 1 FROM catalogue_barcodes b WHERE b.product_id=p.id AND b.barcode LIKE ?
        )
      )
      ORDER BY p.name ASC LIMIT 100
    ''',
      <Object?>[q, '%$q%', '%$q%', '%$q%', '%$q%'],
    );
    return rows.map(RetailProduct.fromMap).toList();
  }

  static Future<RetailProduct?> productByExactBarcode(
    String barcode, [
    DatabaseExecutor? executor,
  ]) async {
    if (barcode.isEmpty) return null;
    if (kIsWeb) {
      _seedWeb();
      final matches = _webProducts.where(
        (row) => row['barcode']?.toString() == barcode,
      );
      return matches.isEmpty ? null : RetailProduct.fromMap(matches.first);
    }
    final DatabaseExecutor db = executor ?? await database;
    final List<Map<String, Object?>> rows = await db.rawQuery(
      '''
      SELECT p.*, b.barcode AS barcode,
        ep.price_type, ep.server_id AS price_version_id,
        ep.promotion_name, ep.campaign_reference,
        CAST(p.stock_balance AS REAL) + COALESCE((
          SELECT SUM(sm.quantity) FROM stock_movements sm
          WHERE sm.server_product_id=p.id
            AND sm.sync_status IN ('pending','rejected')
        ),0) AS stock
      FROM catalogue_barcodes b
      JOIN products p ON p.id=b.product_id
      LEFT JOIN catalogue_effective_prices ep ON ep.server_id=(
        SELECT ep2.server_id FROM catalogue_effective_prices ep2
        WHERE ep2.product_id=p.id
        ORDER BY ep2.precedence_rank ASC, ep2.server_id ASC LIMIT 1
      )
      WHERE b.barcode=?
      LIMIT 1
      ''',
      <Object?>[barcode],
    );
    return rows.isEmpty ? null : RetailProduct.fromMap(rows.single);
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
    throw UnsupportedError(
      'Catalogue products are server-owned and cannot be created locally.',
    );
    /* Legacy web-only implementation retained below for source compatibility.
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
    } */
  }

  static Future<int> completeSale({
    required AppSession session,
    required Employee cashier,
    required List<BasketLine> lines,
    required String paymentMethod,
    @visibleForTesting Database? databaseOverride,
  }) async {
    if (lines.isEmpty) throw StateError('Basket is empty.');
    final DateTime occurredAt = DateTime.now().toUtc();
    final Uuid uuid = const Uuid();
    final String saleUid = uuid.v4();
    final String dateCode =
        '${occurredAt.year.toString().padLeft(4, '0')}'
        '${occurredAt.month.toString().padLeft(2, '0')}'
        '${occurredAt.day.toString().padLeft(2, '0')}';
    final String saleNumber =
        'S-$dateCode-${saleUid.substring(0, 8).toUpperCase()}';
    final String tenderType = paymentMethod == 'card'
        ? 'card_recorded'
        : paymentMethod;
    if (tenderType != 'cash' && tenderType != 'card_recorded') {
      throw StateError('Unsupported tender type.');
    }
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
      final int saleId = ++_webSaleId;
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
        'created_at': occurredAt.toIso8601String(),
        'sale_uid': saleUid,
        'device_uuid': session.deviceUuid,
        'occurred_at_utc': occurredAt.toIso8601String(),
      });
      for (final BasketLine line in lines) {
        final int index = _webProducts.indexWhere(
          (Map<String, Object?> row) => row['id'] == line.product.id,
        );
        _webProducts[index]['stock'] =
            (_webProducts[index]['stock'] as num).toDouble() - line.quantity;
        _webSaleLines.add(<String, Object?>{
          'sale_id': saleId,
          'line_uid': uuid.v4(),
          'product_id': line.product.id,
          'barcode': line.barcodeUsed,
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
          'quantity_decimal': (-line.quantity).toStringAsFixed(3),
          'reference': saleNumber,
          'note': 'POS sale',
          'sync_status': 'pending',
          'created_at': occurredAt.toIso8601String(),
        });
      }
      return saleId;
    }
    final Database db = databaseOverride ?? await database;
    return db.transaction((Transaction txn) async {
      for (final BasketLine line in lines) {
        final List<Map<String, Object?>> rows = await txn.query(
          'products',
          columns: <String>['stock_balance'],
          where: 'id = ?',
          whereArgs: <Object?>[line.product.id],
          limit: 1,
        );
        if (rows.isEmpty) throw StateError('Product no longer exists.');
        final double serverStock = double.parse(
          rows.first['stock_balance'].toString(),
        );
        final List<Map<String, Object?>> pendingRows = await txn.rawQuery(
          "SELECT COALESCE(SUM(quantity),0) AS quantity FROM stock_movements WHERE server_product_id=? AND sync_status IN ('pending','rejected')",
          <Object?>[line.product.id],
        );
        final double pending = (pendingRows.single['quantity'] as num)
            .toDouble();
        final double stock = serverStock + pending;
        if (stock < line.quantity)
          throw StateError('Not enough stock for ${line.product.name}.');
      }
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
        'created_at': occurredAt.toIso8601String(),
        'sale_uid': saleUid,
        'device_uuid': session.deviceUuid,
        'occurred_at_utc': occurredAt.toIso8601String(),
        'currency_code': 'AUD',
        'subtotal_decimal': total.toStringAsFixed(2),
        'manual_discount_decimal': '0.00',
        'tax_decimal': '0.00',
        'total_decimal': total.toStringAsFixed(2),
        'receipt_contract_version': 'm3.receipt.v1',
      });
      for (final BasketLine line in lines) {
        await txn.insert('sale_lines', <String, Object?>{
          'sale_id': saleId,
          'line_uid': uuid.v4(),
          'product_id': line.product.id,
          'barcode': line.barcodeUsed,
          'product_name': line.product.name,
          'quantity': line.quantity,
          'unit_price': line.product.price,
          'unit_cost': line.product.cost,
          'line_total': line.total,
          'server_product_id': line.product.id,
          'catalogue_unit_price': line.product.priceExact,
          'unit_of_measure': line.product.unitOfMeasure,
          'tax_code': line.product.taxCode,
          'tax_rate_basis_points': line.product.taxRateBasisPoints,
          'tax_inclusive': 1,
          'original_unit_price': line.product.priceExact,
          'manual_discount_amount': '0.00',
          'taxable_amount': line.total.toStringAsFixed(2),
          'net_amount': null,
          'tax_amount': null,
          'gross_amount': line.total.toStringAsFixed(2),
          'currency_code': 'AUD',
        });
        await txn.insert('stock_movements', <String, Object?>{
          'product_id': line.product.id,
          'movement_type': 'sale',
          'quantity': -line.quantity,
          'quantity_decimal': (-line.quantity).toStringAsFixed(3),
          'reference': saleUid,
          'note': 'POS sale',
          'sync_status': 'pending',
          'created_at': occurredAt.toIso8601String(),
          'server_product_id': line.product.id,
        });
      }
      await txn.insert('sale_tenders', <String, Object?>{
        'tender_uid': uuid.v4(),
        'sale_id': saleId,
        'tender_type': tenderType,
        'currency_code': 'AUD',
        'amount_due': total.toStringAsFixed(2),
        'amount_tendered': total.toStringAsFixed(2),
        'change_due': '0.00',
        'recorded_at_utc': occurredAt.toIso8601String(),
      });
      await txn.insert('sale_outbox', <String, Object?>{
        'sale_id': saleId,
        'state': 'pending',
        'attempt_count': 0,
        'created_at_utc': occurredAt.toIso8601String(),
      });
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
        'quantity_decimal': delta.toStringAsFixed(3),
        'reference': const Uuid().v4(),
        'note': note,
        'sync_status': 'pending',
        'created_at': DateTime.now().toIso8601String(),
      });
      return;
    }
    final Database db = await database;
    await db.transaction((Transaction txn) async {
      await txn.insert('stock_movements', <String, Object?>{
        'product_id': productId,
        'movement_type': 'adjustment',
        'quantity': delta,
        'quantity_decimal': delta.toStringAsFixed(3),
        'reference': const Uuid().v4(),
        'note': note,
        'sync_status': 'pending',
        'created_at': DateTime.now().toIso8601String(),
        'server_product_id': productId,
      });
    });
  }

  static Future<Map<String, dynamic>> pendingSyncPayload(
    AppSession session, {
    @visibleForTesting Database? databaseOverride,
  }) async {
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
    final Database db = databaseOverride ?? await database;
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
    final List<Map<String, Object?>> exactMovements = movements
        .map(
          (Map<String, Object?> movement) => <String, Object?>{
            ...movement,
            'quantity_decimal':
                movement['quantity_decimal'] ??
                (movement['quantity'] as num).toDouble().toStringAsFixed(3),
          },
        )
        .toList();
    return <String, dynamic>{
      'client_id': session.clientId,
      'store_id': session.storeId,
      'device_uuid': session.deviceUuid,
      'sales': sales,
      'stock_movements': exactMovements,
    };
  }

  static Future<int> sync(AppSession session) async {
    final Map<String, dynamic> payload = await pendingSyncPayload(session);
    final List<dynamic> sales = payload['sales'] as List<dynamic>;
    final List<dynamic> movements = payload['stock_movements'] as List<dynamic>;
    if (sales.isEmpty && movements.isEmpty) return 0;
    Map<String, dynamic> response;
    try {
      response = await Api.postJson(
        Uri.parse(kRetailSyncUrl),
        payload,
        bearerToken: session.activationToken,
      );
    } catch (error) {
      if (!kIsWeb) {
        final Database db = await database;
        await db.update('retail_sync_state', <String, Object?>{
          'last_attempt_at_utc': DateTime.now().toUtc().toIso8601String(),
          'last_attempt_status': 'failed',
          'last_error_message': cleanError(error),
        }, where: 'singleton=1');
      }
      rethrow;
    }
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
    return applyStockSyncResponse(
      db,
      response,
      submittedSaleIds: sales
          .map((dynamic row) => (row as Map<String, dynamic>)['id'] as int)
          .toList(),
      submittedMovementIds: movements
          .map((dynamic row) => (row as Map<String, Object?>)['id'] as int)
          .toList(),
    );
  }

  @visibleForTesting
  static Future<int> applyStockSyncResponse(
    Database db,
    Map<String, dynamic> response, {
    required List<int> submittedSaleIds,
    required List<int> submittedMovementIds,
  }) async {
    if (response['stock_contract_version'] != 'm2.stock.sync.v1') {
      throw const FormatException('Unsupported stock sync contract.');
    }
    final Object? rawOutcomes = response['movement_outcomes'];
    if (rawOutcomes is! List) {
      throw const FormatException('Missing stock movement outcomes.');
    }
    if (response['synced_sales'] != submittedSaleIds.length) {
      throw const FormatException('Incomplete sale acknowledgement.');
    }
    final Set<int> submitted = submittedMovementIds.toSet();
    final Set<int> acknowledged = <int>{};
    final String now = DateTime.now().toUtc().toIso8601String();
    final int count = await db.transaction((Transaction txn) async {
      if (submittedSaleIds.isNotEmpty) {
        await txn.update(
          'sales',
          <String, Object?>{'sync_status': 'synced'},
          where:
              'sync_status = ? AND id IN (${List<String>.filled(submittedSaleIds.length, '?').join(',')})',
          whereArgs: <Object?>['pending', ...submittedSaleIds],
        );
      }
      int successful = submittedSaleIds.length;
      for (final Object? raw in rawOutcomes) {
        if (raw is! Map) throw const FormatException('Invalid stock outcome.');
        final Map<String, dynamic> outcome = Map<String, dynamic>.from(raw);
        final int? localId = outcome['local_id'] as int?;
        final String result = outcome['outcome']?.toString() ?? '';
        if (localId == null ||
            !submitted.contains(localId) ||
            !acknowledged.add(localId)) {
          throw const FormatException('Stock outcome does not match request.');
        }
        if (result == 'accepted' || result == 'duplicate') {
          final int? serverMovementId = outcome['server_movement_id'] as int?;
          final Object? rawBalance = outcome['balance'];
          if (serverMovementId == null ||
              serverMovementId <= 0 ||
              rawBalance is! Map) {
            throw const FormatException('Incomplete stock acknowledgement.');
          }
          await txn.update(
            'stock_movements',
            <String, Object?>{
              'sync_status': 'synced',
              'server_movement_id': serverMovementId,
              'acknowledged_at_utc': now,
              'rejection_code': null,
              'rejection_message': null,
            },
            where: 'id=? AND sync_status=?',
            whereArgs: <Object?>[localId, 'pending'],
          );
          successful++;
          final Map<String, dynamic> balance = Map<String, dynamic>.from(
            rawBalance,
          );
          final String quantity = balance['quantity']?.toString() ?? '';
          final int? revision = balance['revision'] as int?;
          if (!RegExp(r'^-?(?:0|[1-9]\d*)\.\d{3}$').hasMatch(quantity) ||
              revision == null ||
              revision < 0) {
            throw const FormatException('Invalid authoritative balance.');
          }
          final List<Map<String, Object?>> movement = await txn.query(
            'stock_movements',
            columns: <String>['server_product_id'],
            where: 'id=?',
            whereArgs: <Object?>[localId],
          );
          if (movement.isEmpty ||
              movement.single['server_product_id'] == null) {
            throw const FormatException(
              'Stock movement has no server product.',
            );
          }
          await txn.update(
            'products',
            <String, Object?>{
              'stock_balance': quantity,
              'stock': double.parse(quantity),
              'stock_revision': revision,
              'negative_stock_exception_json': balance['negative_stock'] == true
                  ? jsonEncode(balance)
                  : null,
            },
            where: 'id=?',
            whereArgs: <Object?>[movement.single['server_product_id']],
          );
        } else if (result == 'rejected') {
          await txn.update(
            'stock_movements',
            <String, Object?>{
              'sync_status': 'rejected',
              'acknowledged_at_utc': now,
              'rejection_code': outcome['error_code']?.toString(),
              'rejection_message': outcome['error']?.toString(),
            },
            where: 'id=? AND sync_status=?',
            whereArgs: <Object?>[localId, 'pending'],
          );
        } else {
          throw const FormatException('Unknown stock outcome.');
        }
      }
      if (acknowledged.length != submitted.length) {
        throw const FormatException('Incomplete stock acknowledgement.');
      }
      final int pending = Sqflite.firstIntValue(
        await txn.rawQuery(
          "SELECT COUNT(*) FROM stock_movements WHERE sync_status='pending'",
        ),
      )!;
      final int rejected = Sqflite.firstIntValue(
        await txn.rawQuery(
          "SELECT COUNT(*) FROM stock_movements WHERE sync_status='rejected'",
        ),
      )!;
      await txn.update('retail_sync_state', <String, Object?>{
        'last_attempt_at_utc': now,
        'last_success_at_utc': now,
        'last_attempt_status': rejected == 0 ? 'healthy' : 'attention',
        'last_error_message': null,
        'pending_movement_count': pending,
        'rejected_movement_count': rejected,
      }, where: 'singleton=1');
      return successful;
    });
    return count;
  }

  static Future<RetailSyncHealth> syncHealth([
    DatabaseExecutor? executor,
  ]) async {
    final DatabaseExecutor db = executor ?? await database;
    final List<Map<String, Object?>> rows = await db.query(
      'retail_sync_state',
      where: 'singleton=1',
      limit: 1,
    );
    final Map<String, Object?> row = rows.single;
    final List<Map<String, Object?>> counts = await db.rawQuery('''
      SELECT
        SUM(CASE WHEN sync_status='pending' THEN 1 ELSE 0 END) pending_count,
        SUM(CASE WHEN sync_status='rejected' THEN 1 ELSE 0 END) rejected_count
      FROM stock_movements
    ''');
    return RetailSyncHealth(
      status: row['last_attempt_status']?.toString() ?? 'never',
      pendingMovements: counts.single['pending_count'] as int? ?? 0,
      rejectedMovements: counts.single['rejected_count'] as int? ?? 0,
      lastSuccessAtUtc: row['last_success_at_utc']?.toString(),
      lastErrorMessage: row['last_error_message']?.toString(),
    );
  }
}
