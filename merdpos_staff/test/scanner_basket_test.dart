import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:merdpos_staff/main.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  sqfliteFfiInit();
  databaseFactory = databaseFactoryFfi;

  group('HID scanner contract', () {
    test('Enter terminates exact leading-zero input', () {
      final ScannerInputBuffer scanner = ScannerInputBuffer();
      final DateTime at = DateTime.utc(2026, 8, 7);
      for (final String character in '000123'.split('')) {
        expect(scanner.add(character, at), isNull);
      }
      expect(scanner.add('\n', at), '000123');
    });

    test(
      'rapid duplicate device event is debounced but repeat scan is valid',
      () {
        final ScannerInputBuffer scanner = ScannerInputBuffer();
        final DateTime at = DateTime.utc(2026, 8, 7);
        String? scan(Duration offset) {
          for (final String character in 'ABC'.split('')) {
            scanner.add(character, at.add(offset));
          }
          return scanner.add('\n', at.add(offset));
        }

        expect(scan(Duration.zero), 'ABC');
        expect(scan(const Duration(milliseconds: 20)), isNull);
        expect(scan(const Duration(milliseconds: 100)), 'ABC');
      },
    );
  });

  group('basket rules', () {
    test('single and repeated scans increment and preserve alias used', () {
      final PosBasket basket = PosBasket();
      basket.add(_product(), barcodeUsed: '000123');
      basket.add(_product(), barcodeUsed: 'ALIAS-2');
      expect(basket.lines, hasLength(1));
      expect(basket.lines.single.quantity, 2);
      expect(basket.lines.single.barcodeUsed, '000123');
    });

    test('each requires whole quantity', () {
      final PosBasket basket = PosBasket()..add(_product(), barcodeUsed: '1');
      expect(
        () => basket.setQuantity(0, 1.5),
        throwsA(isA<BasketValidationException>()),
      );
      basket.setQuantity(0, 3);
      expect(basket.lines.single.quantity, 3);
    });

    test('kilogram and litre accept three decimals only', () {
      for (final String uom in <String>['kilogram', 'litre']) {
        final PosBasket basket = PosBasket()
          ..add(_product(uom: uom), barcodeUsed: '1');
        basket.setQuantity(0, 1.125);
        expect(basket.lines.single.quantity, 1.125);
        expect(
          () => basket.setQuantity(0, 1.1234),
          throwsA(isA<BasketValidationException>()),
        );
      }
    });

    test('exact gross total rounds only after quantity multiplication', () {
      final PosBasket basket = PosBasket()
        ..add(
          _product(price: '7.1234', uom: 'kilogram'),
          barcodeUsed: '1',
        );
      basket.setQuantity(0, 1.125);
      expect(basket.lineTotalExact(basket.lines.single), '8.01');
      expect(basket.totalExact, '8.01');
    });

    test('remove line and automatic promotion metadata remain visible', () {
      final PosBasket basket = PosBasket()
        ..add(_product(promotion: 'Weekend price'), barcodeUsed: '1');
      expect(basket.lines.single.product.promotionName, 'Weekend price');
      basket.removeAt(0);
      expect(basket.lines, isEmpty);
    });

    test('projected stock warnings do not prevent basket addition', () {
      final PosBasket basket = PosBasket()
        ..add(_product(stock: 0), barcodeUsed: '1');
      expect(basket.insufficientStock(basket.lines.single), isTrue);
      expect(basket.lines, hasLength(1));
    });

    test('catalogue invalid states provide explicit configuration errors', () {
      final cases = <RetailProduct, String>{
        _product(lifecycle: 'disabled'): 'disabled',
        _product(lifecycle: 'archived'): 'archived',
        _product(storeAvailable: false): 'unavailable',
        _product(price: ''): 'valid price',
        _product(taxCode: null): 'valid tax',
      };
      for (final MapEntry<RetailProduct, String> entry in cases.entries) {
        expect(productConfigurationError(entry.key), contains(entry.value));
      }
      expect(
        productConfigurationError(_product(taxCode: 'NO_TAX', taxRate: 0)),
        isNull,
      );
    });
  });

  test(
    'exact alias lookup preserves leading zeroes and excludes barcode-free products',
    () async {
      final Database db = await databaseFactoryFfi.openDatabase(
        inMemoryDatabasePath,
        options: OpenDatabaseOptions(
          version: RetailDb.schemaVersion,
          onConfigure: (database) => database.execute('PRAGMA foreign_keys=ON'),
          onCreate: (database, _) => RetailDb.createSchemaForTesting(database),
        ),
      );
      try {
        await db.insert('catalogue_categories', <String, Object?>{
          'server_id': 1,
          'name': 'Test',
          'status': 'active',
        });
        await _insertDbProduct(db, 10, 'With aliases');
        await _insertDbProduct(db, 11, 'Barcode free');
        await db.insert('catalogue_barcodes', <String, Object?>{
          'server_id': 1,
          'product_id': 10,
          'barcode': '000123',
          'is_primary': 1,
        });
        await db.insert('catalogue_barcodes', <String, Object?>{
          'server_id': 2,
          'product_id': 10,
          'barcode': 'ALIAS-2',
          'is_primary': 0,
        });

        expect((await RetailDb.productByExactBarcode('000123', db))?.id, 10);
        expect((await RetailDb.productByExactBarcode('ALIAS-2', db))?.id, 10);
        expect(await RetailDb.productByExactBarcode('123', db), isNull);
        expect(await RetailDb.productByExactBarcode('', db), isNull);
        final List<RetailProduct> catalogue =
            await RetailDb.posCatalogueProducts(db);
        expect(catalogue, hasLength(2));
        expect(
          catalogue.singleWhere((product) => product.id == 10).barcodeAliases,
          containsAll(<String>['000123', 'ALIAS-2']),
        );
      } finally {
        await db.close();
      }
    },
  );

  testWidgets(
    'manual barcode fallback restores scanner focus and exposes stale sync',
    (WidgetTester tester) async {
      await tester.binding.setSurfaceSize(const Size(1400, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: PosPage(
              session: AppSession(
                clientId: 1,
                clientName: 'Synthetic',
                storeId: 2,
                storeName: 'Offline Store',
                deviceUuid: 'device-test',
                activationToken: 'synthetic',
              ),
              cashier: const Employee(
                id: 3,
                fullName: 'Test Cashier',
                userId: '3',
                roleName: 'Staff',
                hourlyRate: '0',
              ),
              productSearch: (_) async => <RetailProduct>[],
              barcodeLookup: (_) async => null,
              healthLookup: () async => const RetailSyncHealth(
                status: 'failed',
                pendingMovements: 1,
                rejectedMovements: 0,
                lastErrorMessage: 'offline',
              ),
              catalogueHealthLookup: () async => const CatalogueSyncHealth(
                status: 'failed',
                stale: true,
                revision: 'synthetic-last-good',
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Catalogue stale • offline ready'), findsOneWidget);
      expect(FocusManager.instance.primaryFocus?.debugLabel, 'POS scanner');

      await tester.tap(find.text('Barcode'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('manual-barcode-field')),
        '000999',
      );
      await tester.tap(find.text('Add'));
      await tester.pumpAndSettle();

      expect(find.text('Unknown barcode: 000999'), findsOneWidget);
      expect(FocusManager.instance.primaryFocus?.debugLabel, 'POS scanner');
    },
  );

  testWidgets(
    'category search scanner and confirmed clear preserve basket semantics',
    (WidgetTester tester) async {
      await tester.binding.setSurfaceSize(const Size(1500, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      final List<RetailProduct> products = <RetailProduct>[
        _product(
          id: 1,
          name: 'Still Water',
          category: 'Drinks',
          barcode: '0001',
        ),
        _product(id: 2, name: 'Cola', category: 'Drinks', barcode: '0002'),
        _product(
          id: 3,
          name: 'Potato Chips',
          category: 'Snacks',
          barcode: '0003',
        ),
      ];
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: PosPage(
              session: AppSession(
                clientId: 1,
                clientName: 'Synthetic',
                storeId: 2,
                storeName: 'Test Store',
                deviceUuid: 'device-test',
                activationToken: 'synthetic',
              ),
              cashier: const Employee(
                id: 3,
                fullName: 'Test Cashier',
                userId: '3',
                roleName: 'Staff',
                hourlyRate: '0',
              ),
              productSearch: (_) async => products,
              barcodeLookup: (barcode) async => products
                  .where((product) => product.barcode == barcode)
                  .firstOrNull,
              healthLookup: () async => const RetailSyncHealth(
                status: 'healthy',
                pendingMovements: 0,
                rejectedMovements: 0,
              ),
              catalogueHealthLookup: () async => const CatalogueSyncHealth(
                status: 'success',
                stale: false,
                revision: 'synthetic',
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('product-1')), findsOneWidget);
      expect(find.byKey(const Key('product-2')), findsOneWidget);
      expect(find.byKey(const Key('product-3')), findsOneWidget);

      await tester.tap(find.byKey(const Key('category-Drinks')));
      await tester.pump();
      expect(find.byKey(const Key('product-1')), findsOneWidget);
      expect(find.byKey(const Key('product-2')), findsOneWidget);
      expect(find.byKey(const Key('product-3')), findsNothing);

      await tester.tap(find.byKey(const Key('product-1')));
      await tester.pump();
      expect(find.byKey(const Key('basket-lines')), findsOneWidget);
      expect(find.text('Still Water'), findsWidgets);
      expect(find.text(r'$2.50'), findsWidgets);

      await tester.tap(find.byKey(const Key('category-Snacks')));
      await tester.pump();
      expect(find.byKey(const Key('product-3')), findsOneWidget);
      expect(find.byKey(const Key('product-1')), findsNothing);
      expect(find.byKey(const Key('basket-lines')), findsOneWidget);

      await tester.tap(find.byKey(const Key('category-all')));
      await tester.pump();
      expect(find.byKey(const Key('product-1')), findsOneWidget);
      expect(find.byKey(const Key('product-3')), findsOneWidget);

      await tester.tap(find.byKey(const Key('category-Drinks')));
      await tester.pump();
      await tester.enterText(find.byKey(const Key('product-search')), 'Cola');
      await tester.pump();
      expect(find.byKey(const Key('product-2')), findsOneWidget);
      expect(find.byKey(const Key('product-1')), findsNothing);
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pumpAndSettle();
      expect(FocusManager.instance.primaryFocus?.debugLabel, 'POS scanner');

      for (final String character in '0002'.split('')) {
        await tester.sendKeyEvent(
          LogicalKeyboardKey(character.codeUnitAt(0)),
          character: character,
        );
      }
      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pumpAndSettle();
      expect(find.text('Cola added'), findsOneWidget);
      expect(find.text(r'$5.00'), findsOneWidget);

      await tester.tap(find.byKey(const Key('clear-basket')));
      await tester.pumpAndSettle();
      expect(find.text('Clear current order?'), findsOneWidget);
      await tester.tap(find.byKey(const Key('confirm-clear-basket')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('basket-lines')), findsNothing);
      expect(find.text(r'$0.00'), findsOneWidget);
      expect(FocusManager.instance.primaryFocus?.debugLabel, 'POS scanner');
      final OutlinedButton clear = tester.widget(
        find.byKey(const Key('clear-basket')),
      );
      expect(clear.onPressed, isNull);
    },
  );
}

RetailProduct _product({
  int id = 1,
  String name = 'Synthetic product',
  String category = 'Test',
  String barcode = '000123',
  String uom = 'each',
  String price = '2.50',
  double stock = 10,
  String lifecycle = 'active',
  bool storeAvailable = true,
  String? taxCode = 'GST',
  int? taxRate = 1000,
  String? promotion,
}) => RetailProduct(
  id: id,
  barcode: barcode,
  name: name,
  category: category,
  price: price.isEmpty ? 0 : double.parse(price),
  cost: 1,
  stock: stock,
  active: lifecycle == 'active',
  unitOfMeasure: uom,
  priceExact: price.isEmpty ? null : price,
  taxCode: taxCode,
  taxRateBasisPoints: taxRate,
  lifecycle: lifecycle,
  storeAvailable: storeAvailable,
  promotionName: promotion,
);

Future<void> _insertDbProduct(Database db, int id, String name) =>
    db.insert('products', <String, Object?>{
      'id': id,
      'category_id': 1,
      'sku': 'SKU-$id',
      'sku_normalized': 'SKU-$id',
      'name': name,
      'unit_of_measure': 'each',
      'lifecycle': 'active',
      'primary_barcode': '',
      'category': 'Test',
      'resolved_price': '2.50',
      'price': 2.5,
      'cost': 1,
      'stock_balance': '5.000',
      'stock': 5,
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
