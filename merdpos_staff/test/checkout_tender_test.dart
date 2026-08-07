import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:merdpos_staff/main.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  sqfliteFfiInit();
  databaseFactory = databaseFactoryFfi;

  group('tender planning', () {
    test('pure cash supports exact tender and deterministic change', () {
      final TenderPlan exact = TenderPlan(totalCents: 10000)
        ..addCash(10000, finalComponent: true);
      expect(exact.complete, isTrue);
      expect(exact.changeCents, 0);

      final TenderPlan change = TenderPlan(totalCents: 10000)
        ..addCash(12000, finalComponent: true);
      expect(change.complete, isTrue);
      expect(change.paidCents, 10000);
      expect(change.changeCents, 2000);
    });

    test('insufficient cash and incomplete split cannot complete', () {
      final TenderPlan plan = TenderPlan(totalCents: 10000)
        ..addCash(2900, finalComponent: false);
      expect(plan.complete, isFalse);
      expect(plan.remainingCents, 7100);
    });

    test('pure card equals total and card overpayment is rejected', () {
      final TenderPlan plan = TenderPlan(totalCents: 10000)..addCardRemaining();
      expect(plan.complete, isTrue);
      expect(plan.components.single.type, TenderType.cardRecorded);
      expect(
        () => TenderPlan(totalCents: 10000).addCard(10001),
        throwsA(isA<BasketValidationException>()),
      );
    });

    test('cash first split fills the exact remaining card amount', () {
      final TenderPlan plan = TenderPlan(totalCents: 10000)
        ..addCash(2900, finalComponent: false)
        ..addCardRemaining();
      expect(plan.complete, isTrue);
      expect(plan.components.map((item) => item.amountCents), <int>[
        2900,
        7100,
      ]);
      expect(plan.components.map((item) => item.type), <TenderType>[
        TenderType.cash,
        TenderType.cardRecorded,
      ]);
    });

    test('card first then final cash may return change', () {
      final TenderPlan plan = TenderPlan(totalCents: 10000)
        ..addCard(7000)
        ..addCash(4000, finalComponent: true);
      expect(plan.complete, isTrue);
      expect(plan.changeCents, 1000);
      expect(plan.paidCents, 10000);
    });

    test('components can be removed and replaced', () {
      final TenderPlan plan = TenderPlan(totalCents: 10000)
        ..addCash(2900, finalComponent: false)
        ..addCardRemaining();
      plan.removeAt(1);
      expect(plan.complete, isFalse);
      plan.addCard(7100);
      expect(plan.complete, isTrue);
    });

    test('negative zero and non-final cash overpayment are rejected', () {
      final TenderPlan plan = TenderPlan(totalCents: 10000);
      expect(() => plan.addCash(0, finalComponent: true), throwsA(anything));
      expect(() => plan.addCard(-1), throwsA(anything));
      expect(
        () => plan.addCash(10001, finalComponent: false),
        throwsA(anything),
      );
    });
  });

  group('exact checkout calculations', () {
    test('tax-inclusive amounts round half-up per line', () {
      final PosBasket basket = PosBasket()
        ..add(_product(price: '10.00', taxRate: 1000), barcodeUsed: '0001');
      final CheckoutAmounts amounts = CheckoutAmounts.fromBasket(basket);
      expect(amounts.totalCents, 1000);
      expect(amounts.taxCents, 91);
      expect(amounts.lines.single.netCents, 909);
    });

    test('NO_TAX retains zero tax', () {
      final PosBasket basket = PosBasket()
        ..add(
          _product(price: '10.00', taxCode: 'NO_TAX', taxRate: 0),
          barcodeUsed: '0001',
        );
      expect(CheckoutAmounts.fromBasket(basket).taxCents, 0);
    });

    test('resolved promotion and fractional UOM amounts remain exact', () {
      for (final String uom in <String>['kilogram', 'litre']) {
        final PosBasket basket = PosBasket()
          ..add(
            _product(
              price: '3.3333',
              uom: uom,
              promotionName: 'Synthetic promo',
            ),
            barcodeUsed: '0001',
          )
          ..setQuantity(0, 1.125);
        expect(CheckoutAmounts.fromBasket(basket).totalCents, 375);
      }
      final PosBasket each = PosBasket()
        ..add(_product(price: '2.00'), barcodeUsed: '0001')
        ..setQuantity(0, 2);
      expect(CheckoutAmounts.fromBasket(each).totalCents, 400);
    });

    test('configuration-invalid products are rejected', () {
      for (final RetailProduct product in <RetailProduct>[
        _product(lifecycle: 'disabled'),
        _product(lifecycle: 'archived'),
        _product(storeAvailable: false),
        _product(price: null),
        _product(taxCode: null),
      ]) {
        final PosBasket basket = PosBasket();
        expect(
          () => basket.add(product, barcodeUsed: '0001'),
          throwsA(isA<BasketValidationException>()),
        );
      }
    });
  });

  group('atomic durable checkout', () {
    late Database db;
    setUp(() async {
      db = await _open(inMemoryDatabasePath);
      await _seedProduct(db);
    });
    tearDown(() => db.close());

    test(
      'cash card and split tenders persist as separate ordered rows',
      () async {
        final TenderPlan plan = TenderPlan(totalCents: 10000)
          ..addCash(2900, finalComponent: false)
          ..addCardRemaining();
        final CheckoutCommitResult result = await _complete(
          db,
          plan: plan,
          product: _product(price: '100.00'),
        );
        final List<Map<String, Object?>> tenders = await db.query(
          'sale_tenders',
          where: 'sale_id=?',
          whereArgs: <Object?>[result.saleId],
          orderBy: 'sequence ASC',
        );
        expect(tenders, hasLength(2));
        expect(tenders.map((row) => row['tender_type']), <Object?>[
          'cash',
          'card_recorded',
        ]);
        expect(tenders.map((row) => row['amount_tendered']), <Object?>[
          '29.00',
          '71.00',
        ]);
        expect(tenders.map((row) => row['sequence']), <Object?>[1, 2]);
        expect((await db.query('sales')).single['payment_method'], 'split');
        expect(await _count(db, 'sale_lines'), 1);
        expect(await _count(db, 'stock_movements'), 1);
        expect(await _count(db, 'sale_outbox'), 1);
      },
    );

    test(
      'pure cash and pure card persist without an external card call',
      () async {
        final TenderPlan cash = TenderPlan(totalCents: 10000)
          ..addCash(12000, finalComponent: true);
        final CheckoutCommitResult cashResult = await _complete(
          db,
          plan: cash,
          product: _product(price: '100.00'),
        );
        expect(
          (await db.query(
            'sale_tenders',
            where: 'sale_id=?',
            whereArgs: <Object?>[cashResult.saleId],
          )).single['change_due'],
          '20.00',
        );

        final TenderPlan card = TenderPlan(totalCents: 10000)
          ..addCardRemaining();
        final CheckoutCommitResult cardResult = await _complete(
          db,
          plan: card,
          product: _product(price: '100.00'),
        );
        expect(
          (await db.query(
            'sale_tenders',
            where: 'sale_id=?',
            whereArgs: <Object?>[cardResult.saleId],
          )).single['tender_type'],
          'card_recorded',
        );
      },
    );

    test('duplicate checkout identity returns one aggregate', () async {
      const String uid = '11111111-2222-4333-8444-555555555555';
      final TenderPlan plan = TenderPlan(totalCents: 10000)
        ..addCash(2900, finalComponent: false)
        ..addCardRemaining();
      final CheckoutCommitResult first = await _complete(
        db,
        plan: plan,
        product: _product(price: '100.00'),
        saleUid: uid,
      );
      final CheckoutCommitResult retry = await _complete(
        db,
        plan: plan,
        product: _product(price: '100.00'),
        saleUid: uid,
      );
      expect(retry.saleId, first.saleId);
      expect(await _count(db, 'sales'), 1);
      expect(await _count(db, 'sale_tenders'), 2);
      expect(await _count(db, 'stock_movements'), 1);
      expect(await _count(db, 'sale_outbox'), 1);
    });

    test('conflicting reuse of checkout identity is rejected', () async {
      const String uid = '11111111-2222-4333-8444-555555555555';
      await _complete(
        db,
        plan: TenderPlan(totalCents: 10000)..addCardRemaining(),
        product: _product(price: '100.00'),
        saleUid: uid,
      );
      await expectLater(
        () => _complete(
          db,
          plan: TenderPlan(totalCents: 10000)
            ..addCash(10000, finalComponent: true),
          product: _product(price: '100.00'),
          saleUid: uid,
        ),
        throwsA(isA<StateError>()),
      );
      expect(await _count(db, 'sales'), 1);
      expect(await _count(db, 'sale_tenders'), 1);
      expect(await _count(db, 'stock_movements'), 1);
    });

    test('negative projected stock remains non-blocking', () async {
      final RetailProduct product = _product(price: '100.00', stock: 0);
      final PosBasket basket = PosBasket()..add(product, barcodeUsed: '0001');
      expect(basket.insufficientStock(basket.lines.single), isTrue);
      final CheckoutCommitResult result = await _complete(
        db,
        plan: TenderPlan(totalCents: 10000)..addCardRemaining(),
        product: product,
      );
      expect(result.saleId, greaterThan(0));
    });

    for (final MapEntry<String, String> failure in <String, String>{
      'sale-line': 'sale_lines',
      'tender': 'sale_tenders',
      'stock-movement': 'stock_movements',
      'outbound-state': 'sale_outbox',
    }.entries) {
      test('${failure.key} failure rolls back the entire checkout', () async {
        await db.execute('''CREATE TRIGGER fail_${failure.value}
        BEFORE INSERT ON ${failure.value} BEGIN
          SELECT RAISE(ABORT,'synthetic M3.3 failure');
        END''');
        final PosBasket basket = PosBasket()
          ..add(_product(price: '100.00'), barcodeUsed: '0001');
        final List<BasketLine> activeBasket = basket.lines;
        await expectLater(
          () => RetailDb.completeCheckout(
            session: _session,
            cashier: _cashier,
            lines: activeBasket,
            tenderPlan: TenderPlan(totalCents: 10000)..addCardRemaining(),
            databaseOverride: db,
          ),
          throwsA(anything),
        );
        expect(basket.lines, hasLength(1));
        expect(await _count(db, 'sales'), 0);
        expect(await _count(db, 'sale_lines'), 0);
        expect(await _count(db, 'sale_tenders'), 0);
        expect(await _count(db, 'stock_movements'), 0);
        expect(await _count(db, 'sale_outbox'), 0);
      });
    }

    test('completed pending split checkout survives restart exactly', () async {
      await db.close();
      final Directory directory = await Directory.systemTemp.createTemp(
        'merdpos-m33-',
      );
      final String path = '${directory.path}/retail.db';
      try {
        Database fileDb = await _open(path);
        await _seedProduct(fileDb);
        final CheckoutCommitResult result = await _complete(
          fileDb,
          plan: TenderPlan(totalCents: 10000)
            ..addCash(2900, finalComponent: false)
            ..addCardRemaining(),
          product: _product(price: '100.00'),
        );
        await fileDb.close();
        fileDb = await _open(path);
        expect(
          (await fileDb.query(
            'sales',
            where: 'id=?',
            whereArgs: <Object?>[result.saleId],
          )).single['sync_status'],
          'pending',
        );
        expect(
          await fileDb.query('sale_tenders', orderBy: 'sequence'),
          hasLength(2),
        );
        expect((await fileDb.query('sale_outbox')).single['state'], 'pending');
        await fileDb.close();
      } finally {
        await directory.delete(recursive: true);
        db = await _open(inMemoryDatabasePath);
      }
    });

    test(
      'v5 single tender migrates additively to ordered tender rows',
      () async {
        await db.close();
        final Directory directory = await Directory.systemTemp.createTemp(
          'merdpos-m33-migration-',
        );
        final String path = '${directory.path}/retail.db';
        try {
          Database legacy = await _open(path);
          await legacy.execute('ALTER TABLE sale_tenders RENAME TO tender_v6');
          await legacy.execute('''CREATE TABLE sale_tenders(
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
          await legacy.execute('DROP TABLE tender_v6');
          await legacy.execute('PRAGMA user_version=5');
          await legacy.close();

          legacy = await _open(path);
          final List<Map<String, Object?>> columns = await legacy.rawQuery(
            'PRAGMA table_info(sale_tenders)',
          );
          expect(columns.map((row) => row['name']), contains('sequence'));
          expect(
            columns.map((row) => row['name']),
            contains('external_reference'),
          );
          await legacy.close();
        } finally {
          await directory.delete(recursive: true);
          db = await _open(inMemoryDatabasePath);
        }
      },
    );
  });
}

final AppSession _session = AppSession(
  clientId: 1,
  clientName: 'Synthetic',
  storeId: 11,
  storeName: 'Synthetic Store',
  deviceUuid: 'device-m33',
  activationToken: 'synthetic-token',
);

const Employee _cashier = Employee(
  id: 9001,
  fullName: 'Synthetic Cashier',
  userId: '9001',
  roleName: 'Staff',
  hourlyRate: '0',
);

Future<CheckoutCommitResult> _complete(
  Database db, {
  required TenderPlan plan,
  required RetailProduct product,
  String? saleUid,
}) => RetailDb.completeCheckout(
  session: _session,
  cashier: _cashier,
  lines: <BasketLine>[BasketLine(product: product, barcodeUsed: '0001')],
  tenderPlan: plan,
  saleUid: saleUid,
  databaseOverride: db,
);

RetailProduct _product({
  String? price = '100.00',
  String? taxCode = 'GST',
  int? taxRate = 1000,
  String uom = 'each',
  String lifecycle = 'active',
  bool storeAvailable = true,
  String? promotionName,
  double stock = 10,
}) => RetailProduct(
  id: 1001,
  barcode: '0001',
  name: 'Synthetic Product',
  category: 'Synthetic',
  price: price == null ? 0 : double.parse(price),
  cost: 2,
  stock: stock,
  active: lifecycle != 'disabled',
  unitOfMeasure: uom,
  priceExact: price,
  taxCode: taxCode,
  taxRateBasisPoints: taxRate,
  taxRateVersionId: 1,
  lifecycle: lifecycle,
  storeAvailable: storeAvailable,
  sellable: true,
  priceType: promotionName == null ? 'base' : 'promotion',
  priceVersionId: 8,
  promotionName: promotionName,
  campaignReference: promotionName == null ? null : 'PROMO-8',
  sku: 'SKU-M33',
);

Future<Database> _open(String path) => databaseFactoryFfi.openDatabase(
  path,
  options: OpenDatabaseOptions(
    version: RetailDb.schemaVersion,
    onConfigure: (database) => database.execute('PRAGMA foreign_keys=ON'),
    onCreate: (database, _) => RetailDb.createSchemaForTesting(database),
    onUpgrade: (database, oldVersion, _) =>
        RetailDb.migrateSchemaForTesting(database, oldVersion),
  ),
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
    'sku': 'SKU-M33',
    'sku_normalized': 'SKU-M33',
    'name': 'Synthetic Product',
    'unit_of_measure': 'each',
    'lifecycle': 'active',
    'primary_barcode': '0001',
    'category': 'Synthetic',
    'resolved_price': '100.00',
    'price': 100.0,
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
