# M2.5 Flutter catalogue migration and initial inbound sync

Status: implemented on the M2.5 feature branch under the product-owner standing authority.

## Automatically adopted decisions

- SQLite schema version 2 is an in-place, additive migration. The v1 `products`
  table is renamed to `legacy_products`; it is retained for historical local-ID
  references. Existing `sales`, `sale_lines`, and `stock_movements` rows are not
  deleted or rewritten.
- The live catalogue uses the immutable server product ID as the SQLite primary
  key. Barcode aliases have their own exact-text table and never become product
  identity.
- New sale lines and stock movements retain their existing `product_id` payload
  while also snapshotting `server_product_id`. Sale lines additionally snapshot
  the exact catalogue price text, UOM, tax code, basis points, and tax-inclusive
  flag. Existing historical rows remain unchanged with null additive fields.
- Catalogue-owned categories, barcodes, effective prices, tax records,
  availability, stock snapshots, warnings, revision state, staging rows, and
  future tombstones are separate from outbound transaction tables.
- Full responses are contract/scope/type/decimal/UOM/referentially validated,
  staged, counted, and then applied in one SQLite transaction. The live revision
  advances only as the final transaction state update. Any error rolls back the
  replacement and preserves the last-good catalogue.
- Local sellability is defensive: server sellability must be true and lifecycle,
  store availability, resolved price, and resolved tax must all be valid. Missing
  price and missing tax are never defaulted.
- Authoritative money and quantities are stored as contract decimal strings.
  Floating-point fields remain derived compatibility/display values only.
- Authoritative stock balance is immutable during offline activity. Projected
  stock is queried as the accepted server snapshot plus pending local movements.
- Normal native startup performs no demo seeding. The former web fixture is
  available only with the explicit compile-time
  `MERDPOS_DEVELOPMENT_CATALOGUE_FIXTURES` flag.
- A full inbound sync starts non-blockingly after existing-session startup and
  after device activation. A download failure marks catalogue health stale and
  leaves login and ordinary offline use of the last-good snapshot available.
  Existing outbound sync behavior remains unchanged.

## Withheld or deferred decisions

- Incremental cursors, tombstone purge, stock convergence, UOM conversion, and
  broad checkout/UI redesign remain deferred to their approved later milestones.
- No M2.4 backend contract, authentication/authorization policy, production
  system, deployment, or live database behavior is changed.
