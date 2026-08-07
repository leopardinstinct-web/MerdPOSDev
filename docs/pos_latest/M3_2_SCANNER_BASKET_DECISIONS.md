# M3.2 Scanner and Basket — Implementation Decisions

Status: implemented in source. Production and hardware deployment are not
authorized.

## Automatically adopted

- Conventional HID/keyboard-wedge input uses Enter as the default terminator.
- Scanner input retains exact text. No trimming, numeric conversion, case
  folding, checksum rewriting, or embedded-price interpretation occurs.
- A 75 millisecond same-barcode event guard suppresses accidental duplicate
  scanner events while allowing intentional repeat scans after that interval.
- Scanner focus is requested on entry and recovered after barcode/quantity
  dialogs and checkout outcomes. Manual exact barcode entry remains available.
- Exact barcode lookup includes every canonical alias and does not synthesize
  identity for barcode-free products. Barcode-free items remain addable from
  catalogue search/product tiles.
- Repeated scans increment the existing canonical-product line. The first
  barcode used is retained on that basket line for the durable sale snapshot.
- `each` quantities are positive whole numbers. `kilogram` and `litre`
  quantities are positive values with at most three decimal places.
- Basket gross totals multiply exact M2 resolved price text by the normalized
  quantity and round half-up to currency precision only at the line boundary.
- Disabled, archived/tombstoned, unavailable, missing-price, missing-tax, and
  otherwise unsellable items produce explicit configuration feedback.
- Projected stock is last accepted server balance plus pending movements minus
  basket quantity. Low, insufficient, and negative states are visible only;
  M3.2 adds no checkout block or override policy.
- The POS work surface uses original MerdPOS light operational panels, strong
  total hierarchy, large touch targets, text-plus-colour statuses, and the
  existing dark persistent navigation.

## Explicit decisions withheld

- Projected-stock checkout blocking, manager override, and related audit rules.
- Manual discount limits, stacking, and permissions.
- Void/refund behavior.
- Printer, cash-drawer, and payment-terminal integration.
- Weighted or embedded-price barcode interpretation.

Checkout/tender calculation and completion changes remain M3.3. Durable sale
ingestion remains M3.4.
