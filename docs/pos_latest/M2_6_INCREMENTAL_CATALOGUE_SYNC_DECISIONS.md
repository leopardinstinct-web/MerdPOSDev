# M2.6 incremental catalogue synchronization decisions

Status: implementation branch policy under standing product-owner authority.

## Automatically adopted

- The server issues random opaque cursor tokens backed by immutable,
  client/store-scoped snapshot rows. Internal database order is monotonic; token
  contents carry no tenant, sequence, or authorization information.
- Cursor snapshots are retained for at least 30 days and up to the newest 256
  per client/store. An unknown, expired, or wrong-scope cursor returns a stable
  `catalogue_cursor_expired` response and the Flutter client performs a full
  resynchronization.
- Incremental batches compare the accepted source snapshot with one immutable
  target snapshot. They are retained for 24 hours, are replayable, and use an
  opaque batch/page token plus a zero-based page index.
- Page size defaults to 100 events and is capped at 250. Entity ordering makes
  dependencies available before consumers and tombstones safe after dependent
  changes.
- Each page applies in one SQLite transaction. Replayed pages use idempotent
  upserts. The accepted cursor and target revision advance only with the final
  page commit.
- Missing server entities produce tombstone events. Product tombstones are
  retained locally and made non-sellable; no product row referenced by pending
  or historical transactions is hard-deleted. Other catalogue tombstones are
  retained as metadata and physical purge is deferred.
- Lifecycle tombstones supplied as product state remain ordinary product
  upserts, preserving full product snapshots and historical references.
- Incremental failure leaves pending sales and movements untouched. Committed
  earlier pages may be replayed safely from the old cursor after interruption.

## Deferred

- Broad stock convergence, acknowledgement outcomes, stock retry policy, and
  operational stale-age UI remain M2.7.
- Client tombstone purge remains deferred until server retention and historical
  reference evidence can prove deletion safe.
