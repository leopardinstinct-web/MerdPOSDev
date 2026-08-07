import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:merdpos_staff/main.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  sqfliteFfiInit();
  databaseFactory = databaseFactoryFfi;

  late Database db;
  setUp(() async {
    db = await databaseFactoryFfi.openDatabase(
      inMemoryDatabasePath,
      options: OpenDatabaseOptions(
        version: RetailDb.schemaVersion,
        onConfigure: (database) => database.execute('PRAGMA foreign_keys = ON'),
        onCreate: (database, _) => RetailDb.createSchemaForTesting(database),
      ),
    );
  });
  tearDown(() => db.close());

  test(
    'clean full sync preserves canonical identity and exact catalogue values',
    () async {
      await _apply(db, _snapshot());

      final products = await db.query('products', orderBy: 'id');
      expect(products.map((row) => row['id']), <int>[
        1001,
        1002,
        1003,
        1004,
        1005,
        1006,
        1007,
        1008,
      ]);
      expect(products.first['resolved_price'], '7.1234');
      expect(products.first['tax_code'], 'GST');
      expect(products.first['tax_rate_basis_points'], 1000);
      expect(products.first['stock_balance'], '-2.500');
      expect(products.first['stock_revision'], 44);
      expect(products.first['unit_of_measure'], 'each');
      expect(products[1]['unit_of_measure'], 'kilogram');
      expect(products[2]['unit_of_measure'], 'litre');
      expect(products[3]['sellable'], 0); // missing price
      expect(products[4]['sellable'], 0); // archived and missing tax
      expect(products[5]['sellable'], 0); // disabled
      expect(products[6]['sellable'], 0); // unavailable at store
      expect(products[7]['sellable'], 0); // missing tax

      final barcodes = await db.query(
        'catalogue_barcodes',
        orderBy: 'server_id',
      );
      expect(barcodes.map((row) => row['barcode']), <String>[
        '0012345',
        'ALT-001',
        '009999',
      ]);
      expect(
        await _count(db, 'catalogue_barcodes', where: 'product_id=1003'),
        0,
      );
      expect((await db.query('catalogue_categories')).single['server_id'], 101);
      expect(
        (await db.query(
          'catalogue_effective_prices',
          where: 'server_id=501',
        )).single['unit_price'],
        '7.1234',
      );
      expect(
        (await db.query('catalogue_tax_codes')).map((row) => row['code']),
        contains('NO_TAX'),
      );
      expect((await CatalogueSync.health(db)).revision, startsWith('sha256:'));
    },
  );

  test(
    'replay is idempotent and complete snapshots replace atomically',
    () async {
      final first = _snapshot();
      await _apply(db, first);
      await _apply(db, first);
      expect(await _count(db, 'products'), 8);

      final malformed = _snapshot();
      (malformed['snapshot'] as Map<String, dynamic>)['products'] = <dynamic>[
        ...((malformed['snapshot'] as Map<String, dynamic>)['products']
            as List<dynamic>),
        <String, dynamic>{'id': 1001},
      ];
      await expectLater(
        _apply(db, malformed),
        throwsA(isA<CatalogueSyncException>()),
      );
      expect(await _count(db, 'products'), 8);
      expect(
        (await CatalogueSync.health(db)).revision,
        first['snapshot_revision'],
      );
    },
  );

  test(
    'database interruption rolls back and leaves the last-good catalogue',
    () async {
      final first = _snapshot();
      await _apply(db, first);
      await db.execute(
        "CREATE TRIGGER interrupt_catalogue BEFORE INSERT ON products WHEN NEW.id=1001 BEGIN SELECT RAISE(ABORT,'synthetic interruption'); END",
      );
      final second = _snapshot(revisionDigit: 'b');
      await expectLater(_apply(db, second), throwsA(anything));
      expect(await _count(db, 'products'), 8);
      expect(
        (await CatalogueSync.health(db)).revision,
        first['snapshot_revision'],
      );
    },
  );

  test(
    'malformed and unsupported responses preserve last-good catalogue',
    () async {
      final good = _snapshot();
      await _apply(db, good);
      final unsupported = _snapshot()
        ..['contract_version'] = 'm2.catalogue.full.v99';
      await expectLater(
        _apply(db, unsupported),
        throwsA(isA<CatalogueSyncException>()),
      );
      final malformed = _snapshot();
      ((malformed['snapshot'] as Map<String, dynamic>)['products']
                  as List<dynamic>)
              .first['stock']['balance'] =
          'NaN';
      await expectLater(
        _apply(db, malformed),
        throwsA(isA<CatalogueSyncException>()),
      );
      expect(await _count(db, 'products'), 8);
      expect(
        (await CatalogueSync.health(db)).revision,
        good['snapshot_revision'],
      );
    },
  );

  test(
    'failed inbound request marks stale state without replacing last-good data',
    () async {
      final good = _snapshot();
      await _apply(db, good);
      final AppSession session = AppSession(
        clientId: 1,
        clientName: 'Synthetic',
        storeId: 11,
        storeName: 'Test Store',
        deviceUuid: 'synthetic-device',
        activationToken: 'synthetic-token',
      );
      await expectLater(
        CatalogueSync.sync(
          session,
          databaseOverride: db,
          endpoint: Uri(scheme: 'local', host: 'catalogue'),
          fetcher: (_, __, ___) async => throw Exception('synthetic offline'),
        ),
        throwsA(anything),
      );
      final health = await CatalogueSync.health(db);
      expect(health.stale, isTrue);
      expect(health.status, 'failed');
      expect(health.revision, good['snapshot_revision']);
      expect(await _count(db, 'products'), 8);
    },
  );

  test(
    'v1 migration preserves sales, sale lines, movements, identifiers and status',
    () async {
      await db.close();
      final Directory directory = await Directory.systemTemp.createTemp(
        'merdpos-m25-',
      );
      final String path = '${directory.path}/retail.db';
      try {
        Database legacy = await databaseFactoryFfi.openDatabase(
          path,
          options: OpenDatabaseOptions(version: 1, onCreate: _createV1Fixture),
        );
        await legacy.close();
        legacy = await databaseFactoryFfi.openDatabase(
          path,
          options: OpenDatabaseOptions(
            version: 2,
            onConfigure: (database) =>
                database.execute('PRAGMA foreign_keys = ON'),
            onUpgrade: (database, oldVersion, _) =>
                RetailDb.migrateSchemaForTesting(database, oldVersion),
          ),
        );
        expect(await _count(legacy, 'legacy_products'), 1);
        expect(
          (await legacy.query('sales')).single['sale_number'],
          'LOCAL-KEEP',
        );
        expect((await legacy.query('sales')).single['sync_status'], 'pending');
        expect((await legacy.query('sale_lines')).single['product_id'], 7);
        expect(
          (await legacy.query('stock_movements')).single['reference'],
          'LOCAL-KEEP',
        );
        expect(
          (await legacy.query('stock_movements')).single['sync_status'],
          'pending',
        );
        expect(await _count(legacy, 'products'), 0);
        await _apply(legacy, _snapshot());
        expect(
          (await legacy.query('sales')).single['sale_number'],
          'LOCAL-KEEP',
        );
        expect(
          (await legacy.query('stock_movements')).single['sync_status'],
          'pending',
        );
        await legacy.close();
      } finally {
        await directory.delete(recursive: true);
      }
      db = await databaseFactoryFfi.openDatabase(inMemoryDatabasePath);
    },
  );

  test(
    'production mode never seeds demo catalogue and tests contain no production host',
    () async {
      expect(RetailDb.developmentCatalogueFixtures, isFalse);
      expect(await _count(db, 'products'), 0);
      final String source = await File(
        'test/catalogue_sync_test.dart',
      ).readAsString();
      expect(
        source,
        isNot(contains(<String>['app', 'merdpos', 'com'].join('.'))),
      );
      expect(source, isNot(contains(<String>['http', '://'].join())));
      expect(source, isNot(contains(<String>['https', '://'].join())));
    },
  );
}

Future<void> _apply(Database db, Map<String, dynamic> snapshot) =>
    CatalogueSync.applyResponse(
      db,
      snapshot,
      expectedClientId: 1,
      expectedStoreId: 11,
      expectedDeviceUuid: 'synthetic-device',
    );

Future<int> _count(DatabaseExecutor db, String table, {String? where}) async =>
    (await db.rawQuery(
          'SELECT COUNT(*) AS count FROM $table${where == null ? '' : ' WHERE $where'}',
        )).single['count']
        as int;

Future<void> _createV1Fixture(Database db, int _) async {
  await db.execute(
    'CREATE TABLE products(id INTEGER PRIMARY KEY AUTOINCREMENT,barcode TEXT NOT NULL UNIQUE,name TEXT NOT NULL,category TEXT NOT NULL DEFAULT \'General\',price REAL NOT NULL DEFAULT 0,cost REAL NOT NULL DEFAULT 0,stock REAL NOT NULL DEFAULT 0,active INTEGER NOT NULL DEFAULT 1,updated_at TEXT NOT NULL)',
  );
  await db.execute(
    'CREATE TABLE sales(id INTEGER PRIMARY KEY AUTOINCREMENT,sale_number TEXT NOT NULL UNIQUE,client_id INTEGER NOT NULL,store_id INTEGER NOT NULL,cashier_id INTEGER NOT NULL,cashier_name TEXT NOT NULL,subtotal REAL NOT NULL,discount REAL NOT NULL DEFAULT 0,tax REAL NOT NULL DEFAULT 0,total REAL NOT NULL,payment_method TEXT NOT NULL,status TEXT NOT NULL DEFAULT \'completed\',sync_status TEXT NOT NULL DEFAULT \'pending\',created_at TEXT NOT NULL)',
  );
  await db.execute(
    'CREATE TABLE sale_lines(id INTEGER PRIMARY KEY AUTOINCREMENT,sale_id INTEGER NOT NULL,product_id INTEGER NOT NULL,barcode TEXT NOT NULL,product_name TEXT NOT NULL,quantity REAL NOT NULL,unit_price REAL NOT NULL,unit_cost REAL NOT NULL,line_total REAL NOT NULL,FOREIGN KEY(sale_id) REFERENCES sales(id))',
  );
  await db.execute(
    'CREATE TABLE stock_movements(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER NOT NULL,movement_type TEXT NOT NULL,quantity REAL NOT NULL,reference TEXT NOT NULL,note TEXT,sync_status TEXT NOT NULL DEFAULT \'pending\',created_at TEXT NOT NULL)',
  );
  await db.insert('products', <String, Object?>{
    'id': 7,
    'barcode': '0007',
    'name': 'Legacy',
    'category': 'Old',
    'price': 1.0,
    'cost': 0.2,
    'stock': 3.0,
    'active': 1,
    'updated_at': '2026-01-01T00:00:00Z',
  });
  await db.insert('sales', <String, Object?>{
    'id': 9,
    'sale_number': 'LOCAL-KEEP',
    'client_id': 1,
    'store_id': 11,
    'cashier_id': 3,
    'cashier_name': 'Synthetic',
    'subtotal': 1.0,
    'discount': 0.0,
    'tax': 0.0,
    'total': 1.0,
    'payment_method': 'cash',
    'status': 'completed',
    'sync_status': 'pending',
    'created_at': '2026-01-01T00:00:00Z',
  });
  await db.insert('sale_lines', <String, Object?>{
    'id': 10,
    'sale_id': 9,
    'product_id': 7,
    'barcode': '0007',
    'product_name': 'Legacy',
    'quantity': 1.0,
    'unit_price': 1.0,
    'unit_cost': 0.2,
    'line_total': 1.0,
  });
  await db.insert('stock_movements', <String, Object?>{
    'id': 11,
    'product_id': 7,
    'movement_type': 'sale',
    'quantity': -1.0,
    'reference': 'LOCAL-KEEP',
    'note': 'preserve',
    'sync_status': 'pending',
    'created_at': '2026-01-01T00:00:00Z',
  });
}

Map<String, dynamic> _snapshot({String revisionDigit = 'a'}) {
  Map<String, dynamic> price(int id) => <String, dynamic>{
    'id': id,
    'scope': 'store',
    'store_id': 11,
    'type': 'promotion',
    'precedence': 1,
    'unit_price': '7.1234',
    'currency_code': 'AUD',
    'effective_from_utc': '2026-01-01T00:00:00.000000Z',
    'effective_to_utc': null,
    'promotion_name': 'Synthetic',
    'campaign_reference': null,
  };
  Map<String, dynamic> tax(
    int assignmentId,
    int codeId,
    String code,
    int rateId,
    int bps,
  ) => <String, dynamic>{
    'assignment_id': assignmentId,
    'tax_code_id': codeId,
    'tax_code': code,
    'tax_code_name': code == 'NO_TAX' ? 'No Tax' : 'Goods and Services Tax',
    'tax_rate_version_id': rateId,
    'rate_basis_points': bps,
    'tax_inclusive': true,
  };
  Map<String, dynamic> product({
    required int id,
    required String name,
    required String unit,
    required String lifecycle,
    required bool available,
    required Map<String, dynamic>? resolvedPrice,
    required Map<String, dynamic>? resolvedTax,
    required bool sellable,
    List<Map<String, dynamic>> barcodes = const <Map<String, dynamic>>[],
    List<String> reasons = const <String>[],
    String balance = '4.000',
    int revision = 1,
  }) => <String, dynamic>{
    'id': id,
    'category_id': 101,
    'sku': 'SKU-$id',
    'sku_normalized': 'sku-$id',
    'name': name,
    'description': null,
    'unit_of_measure': unit,
    'lifecycle': lifecycle,
    'archived_at_utc': lifecycle == 'archived'
        ? '2026-01-01T00:00:00.000000Z'
        : null,
    'tombstoned_at_utc': null,
    'barcodes': barcodes,
    'store': <String, dynamic>{
      'available': available,
      'reorder_level': '1.250',
    },
    'effective_prices': resolvedPrice == null
        ? <dynamic>[]
        : <dynamic>[resolvedPrice],
    'resolved_price': resolvedPrice,
    'resolved_tax': resolvedTax,
    'stock': <String, dynamic>{
      'balance': balance,
      'revision': revision,
      'negative_exception': balance.startsWith('-')
          ? <String, dynamic>{
              'status': 'open',
              'lowest_observed_balance': balance,
            }
          : null,
    },
    'sellable': sellable,
    'not_sellable_reasons': reasons,
  };
  Map<String, dynamic> gst(int productId) =>
      tax(8000 + productId, 201, 'GST', 301, 1000);
  Map<String, dynamic> noTax(int productId) =>
      tax(8000 + productId, 202, 'NO_TAX', 302, 0);
  return <String, dynamic>{
    'success': true,
    'api': 'sync_catalogue.php',
    'contract_version': 'm2.catalogue.full.v1',
    'snapshot_type': 'full',
    'snapshot_revision':
        'sha256:${List<String>.filled(64, revisionDigit).join()}',
    'cursor_seed': 'm2f1_synthetic',
    'server_time_utc': '2026-08-07T00:00:00.000000Z',
    'snapshot_generated_at_utc': '2026-08-07T00:00:00.000000Z',
    'snapshot': <String, dynamic>{
      'context': <String, dynamic>{
        'client': <String, dynamic>{
          'id': 1,
          'name': 'Synthetic',
          'status': 'active',
        },
        'store': <String, dynamic>{
          'id': 11,
          'name': 'Test Store',
          'code': 'TEST',
          'status': 'active',
        },
        'device_uuid': 'synthetic-device',
      },
      'currency': <String, dynamic>{
        'code': 'AUD',
        'money_scale': 4,
        'tax_inclusive': true,
      },
      'categories': <dynamic>[
        <String, dynamic>{
          'id': 101,
          'name': 'Synthetic Category',
          'status': 'active',
        },
      ],
      'tax_codes': <dynamic>[
        <String, dynamic>{
          'id': 201,
          'code': 'GST',
          'name': 'GST',
          'status': 'active',
        },
        <String, dynamic>{
          'id': 202,
          'code': 'NO_TAX',
          'name': 'No Tax',
          'status': 'active',
        },
      ],
      'effective_tax_rates': <dynamic>[
        <String, dynamic>{
          'id': 301,
          'tax_code_id': 201,
          'rate_basis_points': 1000,
          'effective_from_utc': '2026-01-01T00:00:00.000000Z',
          'effective_to_utc': null,
        },
        <String, dynamic>{
          'id': 302,
          'tax_code_id': 202,
          'rate_basis_points': 0,
          'effective_from_utc': '2026-01-01T00:00:00.000000Z',
          'effective_to_utc': null,
        },
      ],
      'product_tax_assignments': <dynamic>[
        for (final int id in <int>[1001, 1002, 1003, 1004, 1006, 1007])
          <String, dynamic>{
            'id': 8000 + id,
            'product_id': id,
            'tax_code_id': id == 1003 ? 202 : 201,
            'effective_from_utc': '2026-01-01T00:00:00.000000Z',
            'effective_to_utc': null,
          },
      ],
      'products': <dynamic>[
        product(
          id: 1001,
          name: 'Each product',
          unit: 'each',
          lifecycle: 'active',
          available: true,
          resolvedPrice: price(501),
          resolvedTax: gst(1001),
          sellable: true,
          balance: '-2.500',
          revision: 44,
          barcodes: <Map<String, dynamic>>[
            <String, dynamic>{
              'id': 401,
              'barcode': '0012345',
              'is_primary': true,
            },
            <String, dynamic>{
              'id': 402,
              'barcode': 'ALT-001',
              'is_primary': false,
            },
          ],
        ),
        product(
          id: 1002,
          name: 'Weight product',
          unit: 'kilogram',
          lifecycle: 'active',
          available: true,
          resolvedPrice: null,
          resolvedTax: gst(1002),
          sellable: false,
          reasons: <String>['missing_effective_price'],
          barcodes: <Map<String, dynamic>>[
            <String, dynamic>{
              'id': 403,
              'barcode': '009999',
              'is_primary': true,
            },
          ],
        ),
        product(
          id: 1003,
          name: 'Volume barcode-free',
          unit: 'litre',
          lifecycle: 'active',
          available: true,
          resolvedPrice: null,
          resolvedTax: noTax(1003),
          sellable: false,
          reasons: <String>['missing_effective_price'],
        ),
        product(
          id: 1004,
          name: 'Missing price',
          unit: 'each',
          lifecycle: 'active',
          available: true,
          resolvedPrice: null,
          resolvedTax: gst(1004),
          sellable: false,
          reasons: <String>['missing_effective_price'],
        ),
        product(
          id: 1005,
          name: 'Archived missing tax',
          unit: 'each',
          lifecycle: 'archived',
          available: false,
          resolvedPrice: null,
          resolvedTax: null,
          sellable: false,
          reasons: <String>[
            'product_archived',
            'store_unavailable',
            'missing_effective_price',
            'missing_effective_tax',
          ],
        ),
        product(
          id: 1006,
          name: 'Disabled',
          unit: 'each',
          lifecycle: 'disabled',
          available: true,
          resolvedPrice: price(506),
          resolvedTax: gst(1006),
          sellable: false,
          reasons: <String>['product_disabled'],
        ),
        product(
          id: 1007,
          name: 'Unavailable',
          unit: 'each',
          lifecycle: 'active',
          available: false,
          resolvedPrice: price(507),
          resolvedTax: gst(1007),
          sellable: false,
          reasons: <String>['store_unavailable'],
        ),
        product(
          id: 1008,
          name: 'Missing tax',
          unit: 'each',
          lifecycle: 'active',
          available: true,
          resolvedPrice: price(508),
          resolvedTax: null,
          sellable: false,
          reasons: <String>['missing_effective_tax'],
        ),
      ],
      'warnings': <dynamic>[
        <String, dynamic>{
          'code': 'missing_effective_price',
          'product_id': 1004,
        },
      ],
    },
  };
}
