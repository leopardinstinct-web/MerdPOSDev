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
        onConfigure: (database) => database.execute('PRAGMA foreign_keys=ON'),
        onCreate: (database, _) => RetailDb.createSchemaForTesting(database),
      ),
    );
    await db.insert('catalogue_categories', <String, Object?>{
      'server_id': 1,
      'name': 'Synthetic',
      'status': 'active',
    });
    await db.insert('products', _product());
    await db.insert('sales', <String, Object?>{
      'id': 4,
      'sale_number': 'OFFLINE-4',
      'client_id': 1,
      'store_id': 11,
      'cashier_id': 3,
      'cashier_name': 'Synthetic',
      'subtotal': 5.0,
      'discount': 0.0,
      'tax': 0.0,
      'total': 5.0,
      'payment_method': 'cash',
      'status': 'completed',
      'sync_status': 'pending',
      'created_at': '2026-08-07T00:00:00Z',
    });
    await _movement(db, 7, '-2.125');
    await _movement(db, 8, '-1.000');
  });
  tearDown(() => db.close());

  test(
    'accepted duplicate and rejected outcomes converge independently',
    () async {
      final int synced = await RetailDb.applyStockSyncResponse(
        db,
        <String, dynamic>{
          'stock_contract_version': 'm2.stock.sync.v1',
          'synced_sales': 1,
          'movement_outcomes': <Map<String, dynamic>>[
            <String, dynamic>{
              'local_id': 7,
              'outcome': 'accepted',
              'server_movement_id': 70,
              'balance': <String, dynamic>{
                'quantity': '-2.125',
                'revision': 12,
                'negative_stock': true,
                'negative_stock_status': 'open',
              },
            },
            <String, dynamic>{
              'local_id': 8,
              'outcome': 'rejected',
              'error_code': 'stock_product_unavailable',
              'error': 'Stock product is unavailable.',
            },
          ],
        },
        submittedSaleIds: <int>[4],
        submittedMovementIds: <int>[7, 8],
      );

      expect(synced, 2);
      expect((await db.query('sales')).single['sync_status'], 'synced');
      final movements = await db.query('stock_movements', orderBy: 'id');
      expect(movements[0]['sync_status'], 'synced');
      expect(movements[0]['server_movement_id'], 70);
      expect(movements[1]['sync_status'], 'rejected');
      expect(movements[1]['quantity_decimal'], '-1.000');
      expect(movements[1]['rejection_code'], 'stock_product_unavailable');
      final product = (await db.query('products')).single;
      expect(product['stock_balance'], '-2.125');
      expect(product['stock_revision'], 12);
      expect(product['negative_stock_exception_json'], isNotNull);
      final health = await RetailDb.syncHealth(db);
      expect(health.status, 'attention');
      expect(health.pendingMovements, 0);
      expect(health.rejectedMovements, 1);
    },
  );

  test(
    'incomplete acknowledgement rolls back sale and movement updates',
    () async {
      expect(
        () => RetailDb.applyStockSyncResponse(
          db,
          <String, dynamic>{
            'stock_contract_version': 'm2.stock.sync.v1',
            'synced_sales': 1,
            'movement_outcomes': <Map<String, dynamic>>[
              <String, dynamic>{
                'local_id': 7,
                'outcome': 'duplicate',
                'server_movement_id': 70,
                'balance': <String, dynamic>{
                  'quantity': '-2.125',
                  'revision': 12,
                  'negative_stock': true,
                },
              },
            ],
          },
          submittedSaleIds: <int>[4],
          submittedMovementIds: <int>[7, 8],
        ),
        throwsFormatException,
      );
      expect((await db.query('sales')).single['sync_status'], 'pending');
      expect(
        (await db.query('stock_movements')).map((row) => row['sync_status']),
        everyElement('pending'),
      );
    },
  );

  test('payload preserves exact quantity text and pending records', () async {
    final payload = await RetailDb.pendingSyncPayload(
      AppSession(
        clientId: 1,
        clientName: 'Synthetic',
        storeId: 11,
        storeName: 'Synthetic',
        deviceUuid: 'device-a',
        activationToken: 'token',
      ),
      databaseOverride: db,
    );
    final movements = payload['stock_movements'] as List<dynamic>;
    expect(movements[0]['quantity_decimal'], '-2.125');
    expect(movements[1]['quantity_decimal'], '-1.000');
    expect((payload['sales'] as List<dynamic>).length, 1);
  });
}

Future<void> _movement(Database db, int id, String quantity) =>
    db.insert('stock_movements', <String, Object?>{
      'id': id,
      'product_id': 1001,
      'server_product_id': 1001,
      'movement_type': 'sale',
      'quantity': double.parse(quantity),
      'quantity_decimal': quantity,
      'reference': 'OFFLINE-$id',
      'note': 'Synthetic',
      'sync_status': 'pending',
      'created_at': '2026-08-07T00:00:00Z',
    });

Map<String, Object?> _product() => <String, Object?>{
  'id': 1001,
  'category_id': 1,
  'sku': 'SKU-1',
  'sku_normalized': 'SKU-1',
  'name': 'Synthetic Product',
  'description': null,
  'unit_of_measure': 'each',
  'lifecycle': 'active',
  'archived_at_utc': null,
  'tombstoned_at_utc': null,
  'primary_barcode': '0001',
  'category': 'Synthetic',
  'resolved_price': '5.00',
  'price': 5.0,
  'cost': 0.0,
  'stock_balance': '0.000',
  'stock': 0.0,
  'stock_revision': 0,
  'store_available': 1,
  'reorder_level': null,
  'tax_code_id': 1,
  'tax_code': 'NO_TAX',
  'tax_rate_version_id': 1,
  'tax_rate_basis_points': 0,
  'tax_inclusive': 1,
  'sellable': 1,
  'not_sellable_reasons_json': '[]',
  'negative_stock_exception_json': null,
  'active': 1,
  'updated_at': '2026-08-07T00:00:00Z',
};
