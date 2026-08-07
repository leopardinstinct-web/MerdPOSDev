part of merdpos_staff;

typedef CatalogueFetcher =
    Future<Map<String, dynamic>> Function(
      Uri uri,
      Map<String, dynamic> body,
      String bearerToken,
    );

class CatalogueSyncException implements Exception {
  const CatalogueSyncException(this.code, this.message);
  final String code;
  final String message;
  @override
  String toString() => message;
}

class CatalogueSyncHealth {
  const CatalogueSyncHealth({
    required this.status,
    required this.stale,
    this.revision,
    this.committedAtUtc,
    this.errorCode,
    this.errorMessage,
  });
  final String status;
  final bool stale;
  final String? revision;
  final String? committedAtUtc;
  final String? errorCode;
  final String? errorMessage;

  bool get hasCatalogue => revision != null;

  factory CatalogueSyncHealth.fromMap(Map<String, Object?> row) =>
      CatalogueSyncHealth(
        status: row['last_attempt_status']?.toString() ?? 'never',
        stale: row['stale'] == 1,
        revision: row['snapshot_revision']?.toString(),
        committedAtUtc: row['committed_at_utc']?.toString(),
        errorCode: row['last_error_code']?.toString(),
        errorMessage: row['last_error_message']?.toString(),
      );
}

class CatalogueSync {
  static const String contractVersion = 'm2.catalogue.full.v1';
  static final RegExp _revisionPattern = RegExp(r'^sha256:[0-9a-f]{64}$');
  static final RegExp _decimalPattern = RegExp(r'^-?(?:0|[1-9]\d*)(?:\.\d+)?$');

  static Future<CatalogueSyncHealth> sync(
    AppSession session, {
    CatalogueFetcher? fetcher,
    Uri? endpoint,
    @visibleForTesting Database? databaseOverride,
  }) async {
    return CatalogueIncrementalSync.sync(
      session,
      fetcher: fetcher,
      endpoint: endpoint,
      databaseOverride: databaseOverride,
    );
  }

  static Future<CatalogueSyncHealth> fullSync(
    AppSession session, {
    CatalogueFetcher? fetcher,
    Uri? endpoint,
    @visibleForTesting Database? databaseOverride,
  }) async {
    final Database db = databaseOverride ?? await RetailDb.database;
    final String attemptAt = DateTime.now().toUtc().toIso8601String();
    try {
      final Map<String, dynamic> response = await (fetcher ?? _fetch)(
        endpoint ?? Uri.parse(kCatalogueSyncUrl),
        <String, dynamic>{
          'contract_version': contractVersion,
          'snapshot_type': 'full',
          'client_id': session.clientId,
          'store_id': session.storeId,
          'device_uuid': session.deviceUuid,
        },
        session.activationToken,
      );
      await applyResponse(
        db,
        response,
        expectedClientId: session.clientId,
        expectedStoreId: session.storeId,
        expectedDeviceUuid: session.deviceUuid,
        attemptAtUtc: attemptAt,
      );
    } catch (error) {
      final String code = error is CatalogueSyncException
          ? error.code
          : 'catalogue_sync_failed';
      final String message = error is CatalogueSyncException
          ? error.message
          : cleanError(error);
      await db.update('catalogue_sync_state', <String, Object?>{
        'last_attempt_at_utc': attemptAt,
        'last_attempt_status': 'failed',
        'last_error_code': code,
        'last_error_message': message,
        'stale': 1,
      }, where: 'singleton = 1');
      rethrow;
    }
    return health(db);
  }

  static Future<Map<String, dynamic>> _fetch(
    Uri uri,
    Map<String, dynamic> body,
    String bearerToken,
  ) => Api.postJson(uri, body, bearerToken: bearerToken);

  static Future<CatalogueSyncHealth> health([
    DatabaseExecutor? executor,
  ]) async {
    final DatabaseExecutor db = executor ?? await RetailDb.database;
    final List<Map<String, Object?>> rows = await db.query(
      'catalogue_sync_state',
      where: 'singleton = 1',
      limit: 1,
    );
    return CatalogueSyncHealth.fromMap(rows.single);
  }

  static Future<void> applyResponse(
    Database db,
    Map<String, dynamic> response, {
    required int expectedClientId,
    required int expectedStoreId,
    required String expectedDeviceUuid,
    String? attemptAtUtc,
  }) async {
    final _ValidatedCatalogue catalogue = _validate(
      response,
      expectedClientId,
      expectedStoreId,
      expectedDeviceUuid,
    );
    final String attempted =
        attemptAtUtc ?? DateTime.now().toUtc().toIso8601String();
    await db.transaction((Transaction txn) async {
      await _clearStage(txn);
      await _stage(txn, catalogue);
      await _validateStage(txn, catalogue);
      await _replaceLive(txn, catalogue, attempted);
      final List<Map<String, Object?>> violations = await txn.rawQuery(
        'PRAGMA foreign_key_check',
      );
      if (violations.isNotEmpty) {
        throw const CatalogueSyncException(
          'catalogue_referential_integrity',
          'Catalogue relationships are invalid.',
        );
      }
    });
  }

  static _ValidatedCatalogue _validate(
    Map<String, dynamic> response,
    int clientId,
    int storeId,
    String deviceUuid,
  ) {
    if (response['success'] != true) {
      throw CatalogueSyncException(
        response['error_code']?.toString() ?? 'catalogue_request_failed',
        response['error']?.toString() ?? 'Catalogue request failed.',
      );
    }
    if (response['contract_version'] != contractVersion ||
        response['snapshot_type'] != 'full') {
      throw const CatalogueSyncException(
        'unsupported_catalogue_contract',
        'Unsupported catalogue contract version.',
      );
    }
    final String revision = _string(response, 'snapshot_revision');
    if (!_revisionPattern.hasMatch(revision)) {
      throw const CatalogueSyncException(
        'invalid_snapshot_revision',
        'Invalid catalogue snapshot revision.',
      );
    }
    final Map<String, dynamic> snapshot = _map(response, 'snapshot');
    final Map<String, dynamic> context = _map(snapshot, 'context');
    if (_integer(_map(context, 'client'), 'id') != clientId ||
        _integer(_map(context, 'store'), 'id') != storeId ||
        _string(context, 'device_uuid') != deviceUuid) {
      throw const CatalogueSyncException(
        'catalogue_scope_mismatch',
        'Catalogue snapshot scope does not match this device.',
      );
    }
    final Map<String, dynamic> currency = _map(snapshot, 'currency');
    if (_integer(currency, 'money_scale') != 4 ||
        currency['tax_inclusive'] != true) {
      throw const CatalogueSyncException(
        'unsupported_money_contract',
        'Unsupported catalogue money contract.',
      );
    }
    final List<Map<String, dynamic>> categories = _maps(snapshot, 'categories');
    final List<Map<String, dynamic>> taxCodes = _maps(snapshot, 'tax_codes');
    final List<Map<String, dynamic>> taxRates = _maps(
      snapshot,
      'effective_tax_rates',
    );
    final List<Map<String, dynamic>> assignments = _maps(
      snapshot,
      'product_tax_assignments',
    );
    final List<Map<String, dynamic>> products = _maps(snapshot, 'products');
    final List<Map<String, dynamic>> warnings = _maps(snapshot, 'warnings');
    final Set<int> categoryIds = _uniqueIds(categories, 'category');
    final Set<int> taxCodeIds = _uniqueIds(taxCodes, 'tax code');
    final Set<int> productIds = _uniqueIds(products, 'product');
    final Set<int> barcodeIds = <int>{};
    final Set<String> barcodeTexts = <String>{};
    final Set<int> priceIds = <int>{};
    _uniqueIds(taxRates, 'tax rate');
    _uniqueIds(assignments, 'tax assignment');
    final Map<int, Map<String, dynamic>> ratesById =
        <int, Map<String, dynamic>>{
          for (final Map<String, dynamic> rate in taxRates)
            _integer(rate, 'id'): rate,
        };
    final Map<int, Map<String, dynamic>> assignmentsByProduct =
        <int, Map<String, dynamic>>{
          for (final Map<String, dynamic> assignment in assignments)
            _integer(assignment, 'product_id'): assignment,
        };
    for (final Map<String, dynamic> rate in taxRates) {
      if (!taxCodeIds.contains(_integer(rate, 'tax_code_id'))) _invalidRef();
      _integer(rate, 'rate_basis_points');
      _string(rate, 'effective_from_utc');
    }
    for (final Map<String, dynamic> assignment in assignments) {
      if (!taxCodeIds.contains(_integer(assignment, 'tax_code_id')) ||
          !productIds.contains(_integer(assignment, 'product_id')))
        _invalidRef();
    }
    for (final Map<String, dynamic> product in products) {
      final Object? categoryId = product['category_id'];
      if (categoryId != null &&
          (categoryId is! int || !categoryIds.contains(categoryId)))
        _invalidRef();
      final String unit = _string(product, 'unit_of_measure');
      if (!const <String>{'each', 'kilogram', 'litre'}.contains(unit)) {
        throw const CatalogueSyncException(
          'invalid_unit_of_measure',
          'Catalogue contains an unsupported unit of measure.',
        );
      }
      final String lifecycle = _string(product, 'lifecycle');
      if (!const <String>{
        'active',
        'disabled',
        'archived',
        'tombstoned',
      }.contains(lifecycle)) {
        throw const CatalogueSyncException(
          'invalid_lifecycle',
          'Catalogue contains an invalid lifecycle state.',
        );
      }
      for (final Map<String, dynamic> barcode in _maps(product, 'barcodes')) {
        final int id = _integer(barcode, 'id');
        final String text = _string(barcode, 'barcode', allowEmpty: false);
        if (barcode['is_primary'] is! bool) _invalid('barcode primary flag');
        if (!barcodeIds.add(id) || !barcodeTexts.add(text)) {
          throw const CatalogueSyncException(
            'duplicate_barcode',
            'Catalogue contains duplicate barcode aliases.',
          );
        }
      }
      for (final Map<String, dynamic> price in _maps(
        product,
        'effective_prices',
      )) {
        if (!priceIds.add(_integer(price, 'id'))) {
          throw const CatalogueSyncException(
            'duplicate_price',
            'Catalogue contains duplicate price records.',
          );
        }
        _decimal(_string(price, 'unit_price'), 4, 'price');
      }
      final Map<String, dynamic>? resolvedPrice = _nullableMap(
        product['resolved_price'],
      );
      final List<Map<String, dynamic>> effectivePrices = _maps(
        product,
        'effective_prices',
      );
      if (resolvedPrice != null) {
        _decimal(_string(resolvedPrice, 'unit_price'), 4, 'price');
        if (effectivePrices.isEmpty ||
            _integer(resolvedPrice, 'id') !=
                _integer(effectivePrices.first, 'id')) {
          _invalid('resolved price precedence');
        }
      }
      final Map<String, dynamic>? resolvedTax = _nullableMap(
        product['resolved_tax'],
      );
      if (resolvedTax != null) {
        final int productId = _integer(product, 'id');
        final Map<String, dynamic>? assignment =
            assignmentsByProduct[productId];
        final Map<String, dynamic>? rate =
            ratesById[_integer(resolvedTax, 'tax_rate_version_id')];
        if (assignment == null ||
            rate == null ||
            _integer(assignment, 'id') !=
                _integer(resolvedTax, 'assignment_id') ||
            _integer(assignment, 'tax_code_id') !=
                _integer(resolvedTax, 'tax_code_id') ||
            _integer(rate, 'tax_code_id') !=
                _integer(resolvedTax, 'tax_code_id') ||
            _integer(rate, 'rate_basis_points') !=
                _integer(resolvedTax, 'rate_basis_points')) {
          _invalidRef();
        }
      }
      final Map<String, dynamic> stock = _map(product, 'stock');
      _decimal(_string(stock, 'balance'), 3, 'stock balance');
      _integer(stock, 'revision');
      final Map<String, dynamic> store = _map(product, 'store');
      if (store['available'] is! bool) _invalid('store availability');
      if (store['reorder_level'] != null)
        _decimal(store['reorder_level'].toString(), 3, 'reorder level');
      if (product['sellable'] is! bool ||
          product['not_sellable_reasons'] is! List)
        _invalid('sellability');
    }
    for (final Map<String, dynamic> warning in warnings) {
      if (warning['product_id'] != null &&
          !productIds.contains(_integer(warning, 'product_id')))
        _invalidRef();
      _string(warning, 'code');
    }
    return _ValidatedCatalogue(
      response: response,
      snapshot: snapshot,
      revision: revision,
      categories: categories,
      taxCodes: taxCodes,
      taxRates: taxRates,
      assignments: assignments,
      products: products,
      warnings: warnings,
    );
  }

  static Future<void> _clearStage(DatabaseExecutor txn) async {
    for (final String table in _stageTables) {
      await txn.delete('catalogue_stage_$table');
    }
  }

  static Future<void> _stage(
    DatabaseExecutor txn,
    _ValidatedCatalogue c,
  ) async {
    final Map<String, List<Map<String, dynamic>>> rows =
        <String, List<Map<String, dynamic>>>{
          'categories': c.categories,
          'products': c.products,
          'barcodes': c.products.expand((p) => _maps(p, 'barcodes')).toList(),
          'tax_codes': c.taxCodes,
          'tax_rates': c.taxRates,
          'product_tax_assignments': c.assignments,
          'effective_prices': c.products
              .expand((p) => _maps(p, 'effective_prices'))
              .toList(),
          'warnings': c.warnings,
        };
    for (final MapEntry<String, List<Map<String, dynamic>>> entry
        in rows.entries) {
      for (final Map<String, dynamic> row in entry.value) {
        await txn.insert('catalogue_stage_${entry.key}', <String, Object?>{
          'payload_json': jsonEncode(row),
        });
      }
    }
  }

  static Future<void> _validateStage(
    DatabaseExecutor txn,
    _ValidatedCatalogue c,
  ) async {
    for (final String table in _stageTables) {
      final int actual =
          Sqflite.firstIntValue(
            await txn.rawQuery('SELECT COUNT(*) FROM catalogue_stage_$table'),
          ) ??
          -1;
      final int expected = switch (table) {
        'categories' => c.categories.length,
        'products' => c.products.length,
        'barcodes' => c.products.fold<int>(
          0,
          (n, p) => n + _maps(p, 'barcodes').length,
        ),
        'tax_codes' => c.taxCodes.length,
        'tax_rates' => c.taxRates.length,
        'product_tax_assignments' => c.assignments.length,
        'effective_prices' => c.products.fold<int>(
          0,
          (n, p) => n + _maps(p, 'effective_prices').length,
        ),
        'warnings' => c.warnings.length,
        _ => -2,
      };
      if (actual != expected) {
        throw const CatalogueSyncException(
          'catalogue_staging_failed',
          'Catalogue staging validation failed.',
        );
      }
    }
  }

  static Future<void> _replaceLive(
    Transaction txn,
    _ValidatedCatalogue c,
    String attempted,
  ) async {
    for (final String table in <String>[
      'catalogue_warnings',
      'catalogue_effective_prices',
      'catalogue_product_tax_assignments',
      'catalogue_tax_rates',
      'catalogue_barcodes',
      'products',
      'catalogue_tax_codes',
      'catalogue_categories',
    ]) {
      await txn.delete(table);
    }
    for (final Map<String, dynamic> row in c.categories) {
      await txn.insert('catalogue_categories', <String, Object?>{
        'server_id': _integer(row, 'id'),
        'name': _string(row, 'name'),
        'status': _string(row, 'status'),
      });
    }
    for (final Map<String, dynamic> row in c.taxCodes) {
      await txn.insert('catalogue_tax_codes', <String, Object?>{
        'server_id': _integer(row, 'id'),
        'code': _string(row, 'code'),
        'name': _string(row, 'name'),
        'status': _string(row, 'status'),
      });
    }
    for (final Map<String, dynamic> row in c.products) {
      final int id = _integer(row, 'id');
      final List<Map<String, dynamic>> barcodes = _maps(row, 'barcodes');
      final Map<String, dynamic>? primary = barcodes
          .cast<Map<String, dynamic>?>()
          .firstWhere(
            (b) => b?['is_primary'] == true,
            orElse: () => barcodes.isEmpty ? null : barcodes.first,
          );
      final Map<String, dynamic>? price = _nullableMap(row['resolved_price']);
      final Map<String, dynamic>? tax = _nullableMap(row['resolved_tax']);
      final Map<String, dynamic> store = _map(row, 'store');
      final Map<String, dynamic> stock = _map(row, 'stock');
      final String lifecycle = _string(row, 'lifecycle');
      final List<dynamic> reasons =
          row['not_sellable_reasons'] as List<dynamic>;
      final bool locallySellable =
          row['sellable'] == true &&
          lifecycle == 'active' &&
          store['available'] == true &&
          price != null &&
          tax != null;
      final int? categoryId = row['category_id'] as int?;
      final String categoryName = categoryId == null
          ? ''
          : c.categories
                .firstWhere((category) => category['id'] == categoryId)['name']
                .toString();
      final String? priceText = price?['unit_price']?.toString();
      final String stockText = _string(stock, 'balance');
      await txn.insert('products', <String, Object?>{
        'id': id,
        'category_id': categoryId,
        'sku': row['sku']?.toString(),
        'sku_normalized': row['sku_normalized']?.toString(),
        'name': _string(row, 'name'),
        'description': row['description']?.toString(),
        'unit_of_measure': _string(row, 'unit_of_measure'),
        'lifecycle': lifecycle,
        'archived_at_utc': row['archived_at_utc']?.toString(),
        'tombstoned_at_utc': row['tombstoned_at_utc']?.toString(),
        'primary_barcode': primary?['barcode']?.toString() ?? '',
        'category': categoryName,
        'resolved_price': priceText,
        'price': priceText == null ? null : double.parse(priceText),
        'stock_balance': stockText,
        'stock': double.parse(stockText),
        'stock_revision': _integer(stock, 'revision'),
        'store_available': store['available'] == true ? 1 : 0,
        'reorder_level': store['reorder_level']?.toString(),
        'tax_code_id': tax?['tax_code_id'] as int?,
        'tax_code': tax?['tax_code']?.toString(),
        'tax_rate_version_id': tax?['tax_rate_version_id'] as int?,
        'tax_rate_basis_points': tax?['rate_basis_points'] as int?,
        'tax_inclusive': tax == null
            ? null
            : (tax['tax_inclusive'] == true ? 1 : 0),
        'sellable': locallySellable ? 1 : 0,
        'not_sellable_reasons_json': jsonEncode(reasons),
        'negative_stock_exception_json': stock['negative_exception'] == null
            ? null
            : jsonEncode(stock['negative_exception']),
        'active': locallySellable ? 1 : 0,
        'updated_at': c.response['snapshot_generated_at_utc'].toString(),
      });
      for (final Map<String, dynamic> barcode in barcodes) {
        await txn.insert('catalogue_barcodes', <String, Object?>{
          'server_id': _integer(barcode, 'id'),
          'product_id': id,
          'barcode': _string(barcode, 'barcode'),
          'is_primary': barcode['is_primary'] == true ? 1 : 0,
        });
      }
      for (final Map<String, dynamic> effectivePrice in _maps(
        row,
        'effective_prices',
      )) {
        await txn.insert('catalogue_effective_prices', <String, Object?>{
          'server_id': _integer(effectivePrice, 'id'),
          'product_id': id,
          'scope': _string(effectivePrice, 'scope'),
          'store_id': effectivePrice['store_id'] as int?,
          'price_type': _string(effectivePrice, 'type'),
          'precedence_rank': _integer(effectivePrice, 'precedence'),
          'unit_price': _string(effectivePrice, 'unit_price'),
          'currency_code': _string(effectivePrice, 'currency_code'),
          'effective_from_utc': _string(effectivePrice, 'effective_from_utc'),
          'effective_to_utc': effectivePrice['effective_to_utc']?.toString(),
          'promotion_name': effectivePrice['promotion_name']?.toString(),
          'campaign_reference': effectivePrice['campaign_reference']
              ?.toString(),
        });
      }
    }
    for (final Map<String, dynamic> row in c.taxRates) {
      await txn.insert('catalogue_tax_rates', <String, Object?>{
        'server_id': _integer(row, 'id'),
        'tax_code_id': _integer(row, 'tax_code_id'),
        'rate_basis_points': _integer(row, 'rate_basis_points'),
        'effective_from_utc': _string(row, 'effective_from_utc'),
        'effective_to_utc': row['effective_to_utc']?.toString(),
      });
    }
    for (final Map<String, dynamic> row in c.assignments) {
      await txn.insert('catalogue_product_tax_assignments', <String, Object?>{
        'server_id': _integer(row, 'id'),
        'product_id': _integer(row, 'product_id'),
        'tax_code_id': _integer(row, 'tax_code_id'),
        'effective_from_utc': _string(row, 'effective_from_utc'),
        'effective_to_utc': row['effective_to_utc']?.toString(),
      });
    }
    for (final Map<String, dynamic> warning in c.warnings) {
      await txn.insert('catalogue_warnings', <String, Object?>{
        'code': _string(warning, 'code'),
        'product_id': warning['product_id'] as int?,
        'details_json': jsonEncode(warning),
      });
    }
    await _clearStage(txn);
    final Object? incrementalTombstones = c.response['_incremental_tombstones'];
    if (incrementalTombstones is List) {
      for (final Object? value in incrementalTombstones) {
        final Map<String, dynamic>? tombstone = _nullableMap(value);
        if (tombstone == null) continue;
        await txn.rawInsert(
          '''INSERT INTO catalogue_tombstones(entity_type,server_id,tombstoned_at_utc,purge_after_cursor)
             VALUES(?,?,?,?) ON CONFLICT(entity_type,server_id) DO UPDATE SET
             tombstoned_at_utc=excluded.tombstoned_at_utc,
             purge_after_cursor=excluded.purge_after_cursor''',
          <Object?>[
            tombstone['entity_type']?.toString(),
            tombstone['server_id'],
            tombstone['tombstoned_at_utc']?.toString(),
            tombstone['purge_after_cursor']?.toString(),
          ],
        );
      }
    }
    await txn.update('catalogue_sync_state', <String, Object?>{
      'contract_version': contractVersion,
      'snapshot_revision': c.revision,
      'cursor_seed': c.response['cursor_seed']?.toString(),
      'snapshot_generated_at_utc': c.response['snapshot_generated_at_utc']
          ?.toString(),
      'snapshot_json': jsonEncode(c.snapshot),
      'committed_at_utc': DateTime.now().toUtc().toIso8601String(),
      'last_attempt_at_utc': attempted,
      'last_attempt_status': 'success',
      'last_error_code': null,
      'last_error_message': null,
      'stale': 0,
    }, where: 'singleton = 1');
  }

  static const List<String> _stageTables = <String>[
    'categories',
    'products',
    'barcodes',
    'tax_codes',
    'tax_rates',
    'product_tax_assignments',
    'effective_prices',
    'warnings',
  ];

  static Set<int> _uniqueIds(List<Map<String, dynamic>> rows, String label) {
    final Set<int> ids = <int>{};
    for (final Map<String, dynamic> row in rows) {
      if (!ids.add(_integer(row, 'id'))) {
        throw CatalogueSyncException(
          'duplicate_catalogue_id',
          'Catalogue contains a duplicate $label ID.',
        );
      }
    }
    return ids;
  }

  static Map<String, dynamic> _map(Map<String, dynamic> map, String key) =>
      _nullableMap(map[key]) ??
      (throw CatalogueSyncException(
        'malformed_catalogue',
        'Catalogue field $key is invalid.',
      ));
  static Map<String, dynamic>? _nullableMap(Object? value) => value is Map
      ? value.map((key, item) => MapEntry(key.toString(), item))
      : null;
  static List<Map<String, dynamic>> _maps(
    Map<String, dynamic> map,
    String key,
  ) {
    final Object? value = map[key];
    if (value is! List) _invalid(key);
    return value
        .map(
          (item) =>
              _nullableMap(item) ??
              (throw CatalogueSyncException(
                'malformed_catalogue',
                'Catalogue list $key is invalid.',
              )),
        )
        .toList();
  }

  static String _string(
    Map<String, dynamic> map,
    String key, {
    bool allowEmpty = false,
  }) {
    final Object? value = map[key];
    if (value is! String || (!allowEmpty && value.isEmpty)) _invalid(key);
    return value;
  }

  static int _integer(Map<String, dynamic> map, String key) {
    final Object? value = map[key];
    if (value is! int) _invalid(key);
    return value;
  }

  static void _decimal(String value, int scale, String label) {
    if (!_decimalPattern.hasMatch(value)) _invalid(label);
    final int actualScale = value.contains('.')
        ? value.length - value.indexOf('.') - 1
        : 0;
    if (actualScale != scale) _invalid(label);
  }

  static Never _invalid(String field) => throw CatalogueSyncException(
    'malformed_catalogue',
    'Catalogue field $field is invalid.',
  );
  static Never _invalidRef() => throw const CatalogueSyncException(
    'catalogue_referential_integrity',
    'Catalogue relationships are invalid.',
  );
}

class _ValidatedCatalogue {
  const _ValidatedCatalogue({
    required this.response,
    required this.snapshot,
    required this.revision,
    required this.categories,
    required this.taxCodes,
    required this.taxRates,
    required this.assignments,
    required this.products,
    required this.warnings,
  });
  final Map<String, dynamic> response;
  final Map<String, dynamic> snapshot;
  final String revision;
  final List<Map<String, dynamic>> categories;
  final List<Map<String, dynamic>> taxCodes;
  final List<Map<String, dynamic>> taxRates;
  final List<Map<String, dynamic>> assignments;
  final List<Map<String, dynamic>> products;
  final List<Map<String, dynamic>> warnings;
}
