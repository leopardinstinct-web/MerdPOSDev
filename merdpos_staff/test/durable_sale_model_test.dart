import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:merdpos_staff/main.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  sqfliteFfiInit();
  databaseFactory = databaseFactoryFfi;

  late Database db;
  setUp(() async {
    db = await _open(inMemoryDatabasePath);
    await _seedProduct(db);
  });
  tearDown(() => db.close());

  test(
    'completed sale atomically persists durable identity and outbox',
    () async {
      final int saleId = await _complete(db, tender: 'cash');
      final sale = (await db.query(
        'sales',
        where: 'id=?',
        whereArgs: <Object?>[saleId],
      )).single;
      final lines = await db.query(
        'sale_lines',
        where: 'sale_id=?',
        whereArgs: <Object?>[saleId],
      );
      final tender = (await db.query(
        'sale_tenders',
        where: 'sale_id=?',
        whereArgs: <Object?>[saleId],
      )).single;
      final outbox = (await db.query(
        'sale_outbox',
        where: 'sale_id=?',
        whereArgs: <Object?>[saleId],
      )).single;
      final movement = (await db.query(
        'stock_movements',
        where: 'reference=?',
        whereArgs: <Object?>[sale['sale_uid']],
      )).single;

      expect(sale['sale_uid'], matches(_uuid));
      expect(sale['sale_number'], startsWith('S-'));
      expect(sale['device_uuid'], 'device-m31');
      expect(sale['occurred_at_utc'], endsWith('Z'));
      expect(sale['accepted_at_utc'], isNull);
      expect(sale['subtotal_decimal'], '7.12');
      expect(sale['total_decimal'], '7.12');
      expect(sale['currency_code'], 'AUD');
      expect(lines, hasLength(1));
      expect(lines.single['line_uid'], matches(_uuid));
      expect(lines.single['server_product_id'], 1001);
      expect(lines.single['barcode'], '000123-ALIAS');
      expect(lines.single['catalogue_unit_price'], '7.1234');
      expect(lines.single['unit_of_measure'], 'each');
      expect(tender['tender_uid'], matches(_uuid));
      expect(tender['tender_type'], 'cash');
      expect(tender['amount_due'], '7.12');
      expect(tender['amount_tendered'], '7.12');
      expect(tender['change_due'], '0.00');
      expect(outbox['state'], 'pending');
      expect(outbox['attempt_count'], 0);
      expect(movement['sync_status'], 'pending');
    },
  );

  test(
    'induced outbox failure rolls back sale lines tender and stock',
    () async {
      await db.execute('''CREATE TRIGGER induce_m31_failure
      BEFORE INSERT ON sale_outbox BEGIN
        SELECT RAISE(ABORT,'synthetic M3.1 failure');
      END''');

      await expectLater(() => _complete(db, tender: 'card'), throwsA(anything));
      expect(await _count(db, 'sales'), 0);
      expect(await _count(db, 'sale_lines'), 0);
      expect(await _count(db, 'sale_tenders'), 0);
      expect(await _count(db, 'sale_outbox'), 0);
      expect(await _count(db, 'stock_movements'), 0);
    },
  );

  test('completed and pending sale survives database restart', () async {
    await db.close();
    final Directory directory = await Directory.systemTemp.createTemp(
      'merdpos-m31-',
    );
    final String path = '${directory.path}/retail.db';
    try {
      Database fileDb = await _open(path);
      await _seedProduct(fileDb);
      final int saleId = await _complete(fileDb, tender: 'card');
      final String uid = (await fileDb.query(
        'sales',
        where: 'id=?',
        whereArgs: <Object?>[saleId],
      )).single['sale_uid'].toString();
      await fileDb.close();

      fileDb = await _open(path);
      expect((await fileDb.query('sales')).single['sale_uid'], uid);
      expect((await fileDb.query('sales')).single['sync_status'], 'pending');
      expect((await fileDb.query('sale_outbox')).single['state'], 'pending');
      expect(
        (await fileDb.query('sale_tenders')).single['tender_type'],
        'card_recorded',
      );
      await fileDb.close();
    } finally {
      await directory.delete(recursive: true);
      db = await _open(inMemoryDatabasePath);
    }
  });
}

final RegExp _uuid = RegExp(
  r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
);

Future<Database> _open(String path) => databaseFactoryFfi.openDatabase(
  path,
  options: OpenDatabaseOptions(
    version: RetailDb.schemaVersion,
    onConfigure: (database) => database.execute('PRAGMA foreign_keys=ON'),
    onCreate: (database, _) => RetailDb.createSchemaForTesting(database),
  ),
);

Future<int> _complete(Database db, {required String tender}) =>
    RetailDb.completeSale(
      session: AppSession(
        clientId: 1,
        clientName: 'Synthetic',
        storeId: 11,
        storeName: 'Synthetic Store',
        deviceUuid: 'device-m31',
        activationToken: 'synthetic-token',
      ),
      cashier: const Employee(
        id: 9001,
        fullName: 'Synthetic Cashier',
        userId: '9001',
        roleName: 'Staff',
        hourlyRate: '0',
      ),
      lines: <BasketLine>[
        BasketLine(
          product: const RetailProduct(
            id: 1001,
            barcode: '000123',
            name: 'Synthetic Product',
            category: 'Synthetic',
            price: 7.1234,
            cost: 2.0,
            stock: 10,
            active: true,
            unitOfMeasure: 'each',
            priceExact: '7.1234',
            taxCode: 'GST',
            taxRateBasisPoints: 1000,
          ),
          barcodeUsed: '000123-ALIAS',
        ),
      ],
      paymentMethod: tender,
      databaseOverride: db,
    );

Future<int> _count(Database db, String table) async =>
    (await db.rawQuery('SELECT COUNT(*) count FROM $table')).single['count']
        as int;

Future<void> _seedProduct(Database db) async {
  await db.insert('catalogue_categories', <String, Object?>{
    'server_id': 1,
    'name': 'Synthetic',
    'status': 'active',
  });
  await db.insert('products', <String, Object?>{
    'id': 1001,
    'category_id': 1,
    'sku': 'SKU-M31',
    'sku_normalized': 'SKU-M31',
    'name': 'Synthetic Product',
    'unit_of_measure': 'each',
    'lifecycle': 'active',
    'primary_barcode': '000123',
    'category': 'Synthetic',
    'resolved_price': '7.1234',
    'price': 7.1234,
    'cost': 2.0,
    'stock_balance': '10.000',
    'stock': 10.0,
    'stock_revision': 1,
    'store_available': 1,
    'tax_code_id': 1,
    'tax_code': 'GST',
    'tax_rate_version_id': 1,
    'tax_rate_basis_points': 1000,
    'tax_inclusive': 1,
    'sellable': 1,
    'not_sellable_reasons_json': '[]',
    'active': 1,
    'updated_at': '2026-08-07T00:00:00Z',
  });
}
