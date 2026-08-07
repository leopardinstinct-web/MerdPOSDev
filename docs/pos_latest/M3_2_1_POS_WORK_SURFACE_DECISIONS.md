# M3.2.1 POS Work-Surface Refinement

Status: implemented in source as a UI/workflow-only refinement. M3.3 exact
checkout and tender behavior remains deferred.

## Adopted layout and workflow

- The existing persistent dark app navigation remains unchanged. Immediately
  beside it, the POS work surface is ordered Current Order, Categories, and
  Products.
- Current Order owns the scrollable basket body and a fixed total/action footer.
  Existing cash/card completion callbacks are preserved without new tender
  calculations or payment semantics.
- The compact order header shows neutral Current Order context and the existing
  meaningful `Instant` default. Notes and Customer are visibly disabled shells;
  no fake persistence or database fields were added.
- Categories are derived from the locally committed canonical catalogue. `All`
  is synthetic UI navigation only. Category and search filtering are local,
  offline, composable, and never mutate the basket.
- The POS catalogue reader exposes SKU and exact barcode aliases so local
  search retains M2 identity and leading-zero semantics without another table
  or schema migration.
- Product tiles reserve a compact media slot for later image work, while using
  a neutral icon now. Tiles expose name, exact price, stock/UOM and text-plus-
  colour configuration or promotion state.
- Clear is disabled for an empty basket and requires confirmation otherwise.
  It clears only the in-memory `PosBasket`; it does not call sale completion or
  write sale, stock, tender, or outbox records. Scanner focus is restored after
  the dialog.

## Preserved boundaries

- `ScannerInputBuffer`, `PosBasket`, UOM rules, exact line totals, projected
  stock visibility, canonical barcode resolution, and durable barcode-used
  snapshot behavior are unchanged.
- No discount, customer, note, held-order, void/refund, tender calculation,
  terminal, printer, receipt, sale-ingestion, SQL, or production behavior was
  introduced.
