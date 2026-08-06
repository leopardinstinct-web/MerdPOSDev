# M2 Catalogue Decisions — Approved Product Policy

Status: approved by the product owner on 2026-08-05.

This document is binding for M2 planning and contract definition. It does not
authorize code changes, database migration execution, production access, or
deployment. Existing Timesheet and payroll behavior remains excluded.

## 1. Canonical product identity

- The immutable server-generated product ID is canonical and client-scoped.
- SKU is optional and must be unique within the client when present.
- SKU normalization trims surrounding whitespace, preserves entered case for
  display, and enforces case-insensitive client-wide uniqueness. `ABC-123` and
  `abc-123` therefore identify the same SKU.
- Products may have zero, one, or multiple barcodes.
- Barcodes are aliases, not product identity. Barcode uniqueness is client-wide.
- Barcode normalization trims surrounding whitespace and preserves the
  remaining value exactly as text, including leading zeroes. Barcodes must
  never be parsed or stored as numbers. Exact client-wide uniqueness means
  `0012345` and `12345` are different barcodes.
- Sales retain the canonical product ID plus descriptive, barcode, price, and
  tax snapshots needed for historical reconstruction.

## 2. Product ownership

- The product master is client-global.
- Product identity, SKU, barcodes, name, description, category, and tax-code
  assignment are client-global master data.
- Stores may overlay availability, selling price, stock quantity, and reorder
  level only.
- Offline devices may not create or override catalogue master data in M2.

## 3. Categories

- Categories are client-global, flat, and identified by stable server IDs.
- Category hierarchy is not part of M2.
- Referenced categories must not be hard-deleted.
- Disabling a category prevents new assignment but does not automatically
  disable products already assigned to it.
- Historical and synchronized references must remain resolvable.

## 4. Pricing

- Prices are tax-inclusive and versioned with effective dates.
- Price precedence is:
  1. store promotion;
  2. client-wide promotion;
  3. store regular price;
  4. client-wide regular price.
- Price history must be retained. Completed sales retain the charged-price
  snapshot and are never repriced by later catalogue changes.
- Offline devices use effective pricing from the last successfully committed
  catalogue synchronization.

## 5. Tax

- Tax codes and rates are versioned and explicitly assigned to products.
- A `No Tax` code with a zero-percent rate is mandatory.
- Prices are tax-inclusive.
- Sale records snapshot tax code, tax rate, tax amount, and the tax-inclusive
  flag.
- Offline devices use tax rules from the last successfully committed catalogue
  synchronization, including already-synchronized future effective rules.

## 6. Stock

- A server-owned, store-specific stock ledger is authoritative.
- Devices calculate projected stock from the last accepted server balance plus
  pending local movements.
- Opening stock and corrections are auditable ledger movements, not silent
  balance replacement.
- Completed offline sales remain accepted during synchronization.
- If concurrent offline activity produces negative authoritative stock, the
  sale remains intact and the negative balance is flagged for review.
- Stock conflicts must not rewrite or reject a completed sale.

## 7. Product lifecycle

- Supported states are `active`, `disabled`, `archived`, and tombstoned.
- Active products may be sold when store availability and stock rules permit.
- Disabled and archived products are not sellable.
- Tombstones communicate removal through incremental synchronization while
  preserving historical references.
- Hard deletion is allowed only for erroneous, unused records with no
  historical references.
- Already-synchronized devices apply lifecycle changes after receiving and
  transactionally committing them. Completed sale snapshots remain unchanged.

## 8. Synchronization

- A clean device receives a full initial catalogue snapshot.
- Later synchronization uses an opaque, server-issued monotonic cursor.
- Pages apply transactionally and must be replay-safe and idempotent.
- The device advances its cursor only after the full page commits.
- Failed synchronization preserves the last working catalogue and cursor.
- Offline catalogue creation is prohibited for products, categories, barcodes,
  prices, tax rules, stock-master records, and other catalogue entities.

## Current implementation conflicts to resolve

- MySQL and SQLite product IDs are unrelated, while outbound sale sync assumes
  they identify the same product.
- Both schemas require one barcode and do not support barcode aliases or
  barcode-free products.
- SQLite stores category text rather than a stable category ID.
- Flutter has no tax model and currently records zero sale tax.
- SQLite contains one current price and no effective-dated price history.
- Server stock movements do not currently update the store inventory balance.
- Catalogue download, revisions, cursors, and tombstone delivery do not exist.
- Fresh Flutter databases automatically receive demonstration products.

## M2.2 effective pricing and tax contract

Approved by the product owner on 2026-08-05:

- Each client has one ISO 4217 base currency, initially `AUD`. M2.2 has no
  multi-currency sales. Catalogue unit prices use `DECIMAL(19,4)`, finalized
  sale amounts use `DECIMAL(19,2)`, and API monetary values are decimal strings.
- Tax is calculated from each tax-inclusive extended sale-line amount and
  rounded half-up to the currency minor unit per line. Transaction tax is the
  sum of the rounded line-tax amounts.
- Supported units are `each`, `kilogram`, and `litre`. `each` sale quantities
  are whole and positive; kilogram/litre quantities may use three decimals.
  Price means price per one declared product unit.
- Price and tax intervals use UTC half-open semantics:
  `effective_from_utc <= instant < effective_to_utc`; a null end is open-ended.
- Equal-scope price overlaps for the same client, product, optional store,
  price type, and effective interval are rejected. Creation time and record ID
  never break ties. Existing price precedence remains binding.
- Future and expired prices are retained. Cancelled prices retain cancellation
  time, actor, and reason and are never selected. Effective prices are not
  silently overwritten, and draft prices are not device-visible.
- Promotions require a name, creator, and creation time. Reason and external
  campaign reference are optional. Approval metadata may be reserved, but no
  approval workflow is part of M2.2.
- Cost versioning is excluded. Existing product cost, purchase-order unit cost,
  and sale-line unit-cost snapshots retain their current behavior.
- Tax codes have stable client-scoped IDs and unique client-scoped names/codes.
  Rate versions are effective-dated and stored as integer basis points
  (`1000 = 10.00%`). Lifecycle states are active, disabled, and archived;
  referenced codes/rates are never hard-deleted. Mandatory `NO_TAX` is fixed
  at zero and cannot be deleted or assigned a nonzero rate.
- Checkout is blocked when no valid effective price or explicitly assigned
  valid tax code/rate exists. There is no implicit zero price or `NO_TAX`
  fallback. Existing completed sales remain unchanged.
- Every completed sale line permanently snapshots canonical product ID,
  barcode used, product name, unit, quantity, catalogue unit price, effective
  price type/record, promotion or discount details, tax code/rate version and
  rate, tax-inclusive flag, net/tax/gross amounts, currency, existing cost, and
  authoritative sale time.

M2.2 establishes the additive schema and contract foundation only. Runtime
admin, API, Flutter, SQLite, checkout, stock-ledger, and catalogue-sync changes
require later separately approved milestones.

## M2.3 stock ledger and balance contract

Automatically adopted under the product-owner standing authority on
2026-08-06:

- `retail_stock_movements` remains the unchanged legacy upload record. The new
  authoritative shadow ledger uses a distinct table so legacy history is never
  reinterpreted or rewritten.
- Every accepted movement is immutable and has a signed quantity. Types are
  opening balance, sale, sale return, purchase receiving, supplier return,
  transfer out/in, wastage/damage, adjustment increase/decrease,
  reconciliation, and compensating reversal. These meanings are canonical for
  later writers and must not vary by API or device.
- One maintained balance exists per client/store/canonical-product. A locked
  insert transaction assigns balance-before, balance-after, and a monotonic
  store-product revision using server acceptance order. Device event time is
  audit data, never the sole ordering authority.
- Idempotency is scoped by client and store using a stable idempotency key, with
  an additional deterministic source record identity. A duplicate writer must
  look up and return the original movement/result after the uniqueness conflict;
  it must not create another stock effect. Timestamps are not idempotency keys.
- One opening movement is allowed per client/store/product. A later corrected
  baseline is a reviewed reconciliation or compensating reversal, never a
  second opening or silent overwrite.
- Valid late offline sales and other movements apply in accepted server order
  and may make the authoritative balance negative. The sale is not rejected or
  rewritten. An exception retains first/latest detection, lowest/latest
  balance, movement links, recovery time, acknowledgement, and resolution
  metadata. Becoming non-negative records recovery but does not silently close
  the exception; operational acknowledgement/resolution remains later scope.
- Adjustments and reconciliations require a reason. Actor fields are compatible
  with future role/approval policy but M2.3 adds no authorization workflow.
- Reversals exactly negate one same-tenant/store/product original movement.
  Only one direct reversal is allowed and posted movement rows cannot be edited
  or deleted. Further correction uses another explicit compensating movement.
- Transfers use one client-scoped identity with immutable source, destination,
  product, and quantity plus unique linked out/in ledger legs. The foundation
  reserves draft/dispatched/received/cancelled lifecycle data; runtime transfer
  workflow is deferred.
- `retail_store_inventory.quantity` is not assumed ledger-derived. Migration
  018 creates no opening movements, balances, or reconciliation candidates
  from it. A later separately approved process snapshots exact legacy and
  ledger values, reports differences, reviews provenance/cutover timing, and
  posts approved idempotent opening/reconciliation movements without changing
  IDs or historical sale/purchase rows.
- M2.3 is shadow-only. Existing API, admin, Flutter, SQLite, checkout,
  receiving, inventory, sync, prices, and tax behavior remain unchanged until
  separately approved integration and production cutover.

## M2.4 initial full catalogue API contract

Automatically adopted under the standing authority on 2026-08-06:

- `POST /api/sync_catalogue.php` supports exactly
  `m2.catalogue.full.v1` and `snapshot_type: full`. Incremental paging and cursor
  consumption are excluded.
- Existing device authorization is reused without policy changes. Request
  client/store/device identifiers are lookup inputs; the matched server-side
  device row is the authoritative snapshot scope.
- One UTC instant governs the whole snapshot. Ordering is deterministic by
  stable IDs and approved price precedence.
- All tenant products are included, including disabled, archived, tombstoned,
  barcode-free, and incomplete records. Problematic records are not silently
  omitted; they have `sellable: false` and machine-readable reasons.
- A resolved price requires a current published M2.2 candidate. A resolved tax
  requires a current published assignment/rate and active code. There is no
  implicit zero price or `NO_TAX` fallback.
- Store availability uses the existing tenant-safe inventory overlay row until
  a later approved availability model. Authoritative stock comes only from the
  M2.3 balance, with zero/revision zero when no ledger balance exists; legacy
  quantity is never substituted.
- Negative stock/exception metadata is exposed for operational awareness and
  does not invalidate a completed sale or make an otherwise sellable product
  unsellable.
- Money and quantities are exact decimal strings, barcodes are exact text, and
  timestamps are UTC with explicit `Z`.
- The content-derived snapshot revision excludes generation time and device
  UUID. The opaque seed is reserved for M2.6 compatibility but has no incremental
  semantics in M2.4.
- Download does not update device `last_sync`; a later device milestone must
  transactionally validate/commit the full response before advancing last-good
  state.

## Remaining contract details

The approved policy does not yet define several later contract rules:

- cursor/tombstone retention and expired-cursor full-resync behavior;
- stale-catalogue warning/blocking thresholds;
- role authorization for catalogue, pricing, tax, and stock corrections;
- the operational owner and final acknowledgement/resolution workflow for
  negative-stock exceptions.

These details must be resolved at the milestone where they affect externally
observable behavior. They must not be silently inferred from legacy columns.

SKU and barcode normalization are approved and no longer block M2.1 catalogue
identity and lifecycle implementation.
