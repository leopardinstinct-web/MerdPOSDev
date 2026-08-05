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

## Remaining contract details

The approved policy does not yet define several lower-level contract rules:

- currency code, monetary precision, and tax-rounding level;
- unit-of-measure and fractional-quantity policy;
- price/tax effective-time timezone and interval-boundary rules;
- prevention or deterministic resolution of overlapping prices at equal scope;
- cursor/tombstone retention and expired-cursor full-resync behavior;
- stale-catalogue warning/blocking thresholds;
- role authorization for catalogue, pricing, tax, and stock corrections;
- the operational owner and resolution workflow for negative-stock exceptions.

These details must be resolved at the milestone where they affect externally
observable behavior. They must not be silently inferred from legacy columns.

SKU and barcode normalization are approved and no longer block M2.1 catalogue
identity and lifecycle implementation.
