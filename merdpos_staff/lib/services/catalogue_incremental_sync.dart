part of merdpos_staff;

class CatalogueIncrementalSync {
  static const String contractVersion = 'm2.catalogue.incremental.v1';
  static const int pageSize = 100;

  static Future<CatalogueSyncHealth> sync(
    AppSession session, {
    CatalogueFetcher? fetcher,
    Uri? endpoint,
    @visibleForTesting Database? databaseOverride,
  }) async {
    final Database db = databaseOverride ?? await RetailDb.database;
    final Map<String, Object?> state = (await db.query(
      'catalogue_sync_state',
      where: 'singleton=1',
      limit: 1,
    )).single;
    final String? cursor = state['cursor_seed']?.toString();
    final String? snapshotJson = state['snapshot_json']?.toString();
    if (cursor == null ||
        cursor.isEmpty ||
        snapshotJson == null ||
        snapshotJson.isEmpty) {
      return CatalogueSync.fullSync(
        session,
        fetcher: fetcher,
        endpoint: endpoint,
        databaseOverride: db,
      );
    }
    final CatalogueFetcher request = fetcher ?? CatalogueSync._fetch;
    final Uri uri = endpoint ?? Uri.parse(kCatalogueSyncUrl);
    final String attemptAt = DateTime.now().toUtc().toIso8601String();
    try {
      return await _runBatch(db, session, request, uri, cursor, attemptAt);
    } on CatalogueSyncException catch (error) {
      if (error.code == 'catalogue_cursor_expired') {
        return CatalogueSync.fullSync(
          session,
          fetcher: fetcher,
          endpoint: endpoint,
          databaseOverride: db,
        );
      }
      await _recordFailure(db, attemptAt, error.code, error.message);
      rethrow;
    } catch (error) {
      await _recordFailure(
        db,
        attemptAt,
        'catalogue_sync_failed',
        cleanError(error),
      );
      rethrow;
    }
  }

  static Future<CatalogueSyncHealth> _runBatch(
    Database db,
    AppSession session,
    CatalogueFetcher fetcher,
    Uri endpoint,
    String cursor,
    String attemptAt,
  ) async {
    Map<String, dynamic> response = await fetcher(endpoint, <String, dynamic>{
      'contract_version': contractVersion,
      'client_id': session.clientId,
      'store_id': session.storeId,
      'device_uuid': session.deviceUuid,
      'cursor': cursor,
      'page_size': pageSize,
    }, session.activationToken);
    String? batchToken;
    for (int expectedPage = 0; expectedPage < 10000; expectedPage++) {
      final _IncrementalPage page = _validatePage(
        response,
        session,
        cursor,
        expectedPage,
        batchToken,
      );
      batchToken = page.batchToken;
      await _stagePage(db, page);
      if (!page.hasMore) {
        await _commitBatch(db, page, session, attemptAt);
        return CatalogueSync.health(db);
      }
      response = await fetcher(endpoint, <String, dynamic>{
        'contract_version': contractVersion,
        'client_id': session.clientId,
        'store_id': session.storeId,
        'device_uuid': session.deviceUuid,
        'batch_token': page.batchToken,
        'page_index': page.nextPageIndex,
      }, session.activationToken);
    }
    throw const CatalogueSyncException(
      'catalogue_page_limit',
      'Catalogue synchronization exceeded the safe page limit.',
    );
  }

  static _IncrementalPage _validatePage(
    Map<String, dynamic> response,
    AppSession session,
    String cursor,
    int expectedPage,
    String? expectedBatch,
  ) {
    if (response['success'] != true) {
      throw CatalogueSyncException(
        response['error_code']?.toString() ?? 'catalogue_request_failed',
        response['error']?.toString() ??
            'Catalogue incremental request failed.',
      );
    }
    if (response['contract_version'] != contractVersion ||
        response['sync_type'] != 'incremental') {
      throw const CatalogueSyncException(
        'unsupported_catalogue_contract',
        'Unsupported incremental catalogue contract.',
      );
    }
    final Map<String, dynamic> context = CatalogueSync._map(
      response,
      'context',
    );
    if (CatalogueSync._integer(context, 'client_id') != session.clientId ||
        CatalogueSync._integer(context, 'store_id') != session.storeId ||
        CatalogueSync._string(context, 'device_uuid') != session.deviceUuid ||
        CatalogueSync._string(response, 'source_cursor') != cursor) {
      throw const CatalogueSyncException(
        'catalogue_scope_mismatch',
        'Incremental catalogue scope does not match this device.',
      );
    }
    final String batch = CatalogueSync._string(response, 'batch_token');
    if (expectedBatch != null && batch != expectedBatch) {
      throw const CatalogueSyncException(
        'catalogue_batch_mismatch',
        'Catalogue batch changed during synchronization.',
      );
    }
    final int pageIndex = CatalogueSync._integer(response, 'page_index');
    final int pageCount = CatalogueSync._integer(response, 'page_count');
    if (pageIndex != expectedPage || pageCount <= 0 || pageIndex >= pageCount) {
      throw const CatalogueSyncException(
        'invalid_catalogue_page',
        'Catalogue page sequence is invalid.',
      );
    }
    final Object? rawEvents = response['events'];
    if (rawEvents is! List || rawEvents.length > 250) {
      throw const CatalogueSyncException(
        'invalid_catalogue_page',
        'Catalogue page events are invalid.',
      );
    }
    final List<Map<String, dynamic>> events = rawEvents
        .map((Object? event) {
          final Map<String, dynamic>? mapped = CatalogueSync._nullableMap(
            event,
          );
          if (mapped == null) CatalogueSync._invalid('incremental event');
          final String entity = CatalogueSync._string(mapped, 'entity_type');
          final String operation = CatalogueSync._string(mapped, 'operation');
          if (!const <String>{
                'catalogue_meta',
                'category',
                'tax_code',
                'tax_rate',
                'product',
                'tax_assignment',
                'warning_set',
              }.contains(entity) ||
              !const <String>{
                'upsert',
                'tombstone',
                'replace',
              }.contains(operation)) {
            CatalogueSync._invalid('incremental event type');
          }
          CatalogueSync._integer(mapped, 'server_id');
          return mapped;
        })
        .toList(growable: false);
    final bool hasMore = response['has_more'] == true;
    final int? nextPage = response['next_page_index'] as int?;
    final String? nextCursor = response['next_cursor']?.toString();
    if (hasMore != (pageIndex < pageCount - 1) ||
        (hasMore && nextPage != pageIndex + 1) ||
        (!hasMore &&
            (nextPage != null || nextCursor == null || nextCursor.isEmpty))) {
      throw const CatalogueSyncException(
        'invalid_catalogue_page',
        'Catalogue continuation metadata is invalid.',
      );
    }
    final String revision = CatalogueSync._string(
      response,
      'target_snapshot_revision',
    );
    if (!CatalogueSync._revisionPattern.hasMatch(revision)) {
      throw const CatalogueSyncException(
        'invalid_snapshot_revision',
        'Invalid incremental target revision.',
      );
    }
    return _IncrementalPage(
      response: response,
      batchToken: batch,
      sourceCursor: cursor,
      targetCursor: nextCursor,
      targetRevision: revision,
      pageIndex: pageIndex,
      pageCount: pageCount,
      events: events,
      hasMore: hasMore,
      nextPageIndex: nextPage,
    );
  }

  static Future<void> _stagePage(Database db, _IncrementalPage page) async {
    await db.transaction((Transaction txn) async {
      final List<Map<String, Object?>> existing = await txn.query(
        'catalogue_incremental_pages',
        where: 'batch_token=? AND page_index=?',
        whereArgs: <Object?>[page.batchToken, page.pageIndex],
        limit: 1,
      );
      final String encoded = jsonEncode(page.response);
      if (existing.isNotEmpty && existing.single['response_json'] != encoded) {
        throw const CatalogueSyncException(
          'catalogue_replay_mismatch',
          'A replayed catalogue page changed content.',
        );
      }
      if (existing.isEmpty) {
        await txn.insert('catalogue_incremental_pages', <String, Object?>{
          'batch_token': page.batchToken,
          'page_index': page.pageIndex,
          'page_count': page.pageCount,
          'source_cursor': page.sourceCursor,
          'target_cursor': page.targetCursor,
          'target_snapshot_revision': page.targetRevision,
          'response_json': encoded,
          'received_at_utc': DateTime.now().toUtc().toIso8601String(),
        });
      }
    });
  }

  static Future<void> _commitBatch(
    Database db,
    _IncrementalPage finalPage,
    AppSession session,
    String attemptAt,
  ) async {
    final List<Map<String, Object?>> rows = await db.query(
      'catalogue_incremental_pages',
      where: 'batch_token=?',
      whereArgs: <Object?>[finalPage.batchToken],
      orderBy: 'page_index ASC',
    );
    if (rows.length != finalPage.pageCount) {
      throw const CatalogueSyncException(
        'catalogue_page_incomplete',
        'Catalogue batch is incomplete.',
      );
    }
    for (int index = 0; index < rows.length; index++) {
      if (rows[index]['page_index'] != index ||
          rows[index]['page_count'] != finalPage.pageCount ||
          rows[index]['source_cursor'] != finalPage.sourceCursor ||
          rows[index]['target_snapshot_revision'] != finalPage.targetRevision) {
        throw const CatalogueSyncException(
          'catalogue_page_incomplete',
          'Catalogue batch metadata is inconsistent.',
        );
      }
    }
    final Map<String, Object?> state = (await db.query(
      'catalogue_sync_state',
      where: 'singleton=1',
      limit: 1,
    )).single;
    if (state['cursor_seed'] != finalPage.sourceCursor) {
      throw const CatalogueSyncException(
        'catalogue_cursor_changed',
        'Accepted catalogue cursor changed during synchronization.',
      );
    }
    final Object? decoded = jsonDecode(state['snapshot_json'].toString());
    if (decoded is! Map) {
      throw const CatalogueSyncException(
        'last_good_unavailable',
        'Last-good catalogue snapshot is unavailable.',
      );
    }
    final Map<String, dynamic> snapshot = decoded.map(
      (key, value) => MapEntry(key.toString(), value),
    );
    final List<Map<String, dynamic>> tombstones = <Map<String, dynamic>>[];
    for (final Map<String, Object?> row in rows) {
      final Map<String, dynamic> page = CatalogueSync._nullableMap(
        jsonDecode(row['response_json'].toString()),
      )!;
      for (final Map<String, dynamic> event in CatalogueSync._maps(
        page,
        'events',
      )) {
        _applyEvent(snapshot, event);
        if (event['operation'] == 'tombstone') {
          tombstones.add(<String, dynamic>{
            'entity_type': event['entity_type'],
            'server_id': event['server_id'],
            'tombstoned_at_utc': page['server_time_utc'],
            'purge_after_cursor': null,
          });
        }
      }
    }
    final String targetCursor = finalPage.targetCursor!;
    final Map<String, dynamic> fullEnvelope = <String, dynamic>{
      'success': true,
      'api': 'sync_catalogue.php',
      'contract_version': CatalogueSync.contractVersion,
      'snapshot_type': 'full',
      'snapshot_revision': finalPage.targetRevision,
      'cursor_seed': targetCursor,
      'server_time_utc': finalPage.response['server_time_utc'],
      'snapshot_generated_at_utc': finalPage.response['server_time_utc'],
      'snapshot': snapshot,
      '_incremental_tombstones': tombstones,
    };
    await CatalogueSync.applyResponse(
      db,
      fullEnvelope,
      expectedClientId: session.clientId,
      expectedStoreId: session.storeId,
      expectedDeviceUuid: session.deviceUuid,
      attemptAtUtc: attemptAt,
    );
    await db.delete(
      'catalogue_incremental_pages',
      where: 'batch_token=?',
      whereArgs: <Object?>[finalPage.batchToken],
    );
  }

  static void _applyEvent(
    Map<String, dynamic> snapshot,
    Map<String, dynamic> event,
  ) {
    final String entity = event['entity_type'].toString();
    final String operation = event['operation'].toString();
    final int id = event['server_id'] as int;
    if (entity == 'catalogue_meta') {
      if (operation != 'replace' || event['payload'] is! Map)
        CatalogueSync._invalid('catalogue metadata event');
      snapshot['currency'] = event['payload'];
      return;
    }
    if (entity == 'warning_set') {
      if (operation != 'replace' || event['payload'] is! List)
        CatalogueSync._invalid('warning event');
      snapshot['warnings'] = event['payload'];
      return;
    }
    final String key = switch (entity) {
      'category' => 'categories',
      'tax_code' => 'tax_codes',
      'tax_rate' => 'effective_tax_rates',
      'product' => 'products',
      'tax_assignment' => 'product_tax_assignments',
      _ => throw const CatalogueSyncException(
        'invalid_catalogue_event',
        'Unknown catalogue event.',
      ),
    };
    final List<dynamic> values = snapshot[key] as List<dynamic>;
    final int index = values.indexWhere(
      (value) => value is Map && value['id'] == id,
    );
    if (operation == 'upsert') {
      final Map<String, dynamic>? payload = CatalogueSync._nullableMap(
        event['payload'],
      );
      if (payload == null || payload['id'] != id)
        CatalogueSync._invalid('catalogue upsert payload');
      if (index < 0)
        values.add(payload);
      else
        values[index] = payload;
      values.sort(
        (left, right) => (left['id'] as int).compareTo(right['id'] as int),
      );
      return;
    }
    if (operation != 'tombstone')
      CatalogueSync._invalid('catalogue event operation');
    if (entity == 'product' && index >= 0) {
      final Map<String, dynamic> retained = CatalogueSync._nullableMap(
        values[index],
      )!;
      retained['lifecycle'] = 'tombstoned';
      retained['tombstoned_at_utc'] ??= DateTime.now()
          .toUtc()
          .toIso8601String();
      retained['resolved_tax'] = null;
      retained['sellable'] = false;
      final List<dynamic> reasons =
          retained['not_sellable_reasons'] as List<dynamic>;
      if (!reasons.contains('product_tombstoned'))
        reasons.add('product_tombstoned');
      values[index] = retained;
      return;
    }
    if (index >= 0) values.removeAt(index);
  }

  static Future<void> _recordFailure(
    Database db,
    String attempted,
    String code,
    String message,
  ) => db.update('catalogue_sync_state', <String, Object?>{
    'last_attempt_at_utc': attempted,
    'last_attempt_status': 'failed',
    'last_error_code': code,
    'last_error_message': message,
    'stale': 1,
  }, where: 'singleton=1');
}

class _IncrementalPage {
  const _IncrementalPage({
    required this.response,
    required this.batchToken,
    required this.sourceCursor,
    required this.targetCursor,
    required this.targetRevision,
    required this.pageIndex,
    required this.pageCount,
    required this.events,
    required this.hasMore,
    required this.nextPageIndex,
  });
  final Map<String, dynamic> response;
  final String batchToken;
  final String sourceCursor;
  final String? targetCursor;
  final String targetRevision;
  final int pageIndex;
  final int pageCount;
  final List<Map<String, dynamic>> events;
  final bool hasMore;
  final int? nextPageIndex;
}
