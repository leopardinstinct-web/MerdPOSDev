# MERDPOS Portal (Alpha) — Cigarette Orders

## Product-owner decisions (2026-10-28)

- Rename the production Timesheet Portal **MERDPOS Portal (Alpha)** at https://app.merdpos.com/timesheet_portal/.
- Keep the existing Timesheets, PayRate, Start Time and Employee Setup CSV integrations and payroll behavior.
- Any authenticated USER or SUPER can choose any store: MX (Marrickville Xpress), ET (Enmore Tobacco), DT (Double Bay Tobacco), RT (Rosebay Tobacco).
- **No sales retrieval, scraping or historical-sales forecasting** in cigarette ordering.
- Maximum-stock ceilings from the existing **Inventory DBA** Google Sheet, **Inventory** tab: Y MX_Ceilings; Z ET_Ceilings; AA DT_Ceilings; AB RT_Ceilings.
- Unscanned cigarettes = zero stock. For each cigarette, order quantity = max(0, store ceiling - counted physical stock).
- Phone-camera barcode scanning records physical stock; barcode scanning or manual search adds tobacco accessories with explicit quantities.
- Use the existing product/barcode catalogue, and reuse the PHP portal CSV-reader architecture for universal reference-data access.
- When generation is built, save a Google Sheet and PDF in the owner's Google Drive; replicate supplied 2026-10-19 MX/DT/RT order PDF template fields and styling.
- SUPER later sees genuine status across four shops and downloads completed PDFs.

## Stage 1 — store selection (coded; release status must be verified)

- Authenticated orders.php; fixed four-store allowlist; all logged-in roles; CSRF-checked POST; PRG redirect; PHP-session persistence.
- Navigation and login branding changed to MERDPOS Portal (Alpha); timesheet calculations and source references unchanged.
- **No order files, stock entries, Sheets modifications, Drive writes or sales scraping at this stage.**
- Selection is session-only, not a durable submitted order.

## Stage 2 — stock counting (not implemented)

- Reuse includes/sheets.php transport for Inventory DBA CSV. Add exact barcode alias resolution, robust blanks handling, constrained cache lifetime.
- Read selected store's Y/Z/AA/AB ceiling; phone scan and manual search/count fallback.
- Missing scans default to zero when calculating orders; invalid/missing ceiling needs explicit business handling rather than inferred values.
- Draft persistence and editing must be designed before collecting counts.

## Stage 3 — accessories / preview (not implemented)

- Scan or manual search accessories; user-specified order quantities.
- Server-side preview with per-item replenishment, zero quantities excluded, duplicates resolved, accessories kept separate from cigarette ceilings.

## Stage 4 — Drive generation / SUPER status (not implemented)

- Scoped backend Drive integration (no client-side secrets); save Google Sheet + PDF with unique order ID, store, author, time, Drive IDs and duplicate-prevention.
- SUPER views actual generated-order timestamps and PDF links for all four stores.
- PDF uses existing template (date, store name, address, QR, Category / Bar Code / Product Name / Order Quantity).

## Track separation

The historical PHP portal (timesheet_portal/) serving the requested production URL is distinct from the Drupal Beta portal (namecheap_beta_live/timesheet_portal/). Stage 1 touches only the former.

## Existing Google Apps Script Tobacco Order interaction — inspected 2026-10-28

Reference URL (GET/read-only): https://script.google.com/macros/s/AKfycbwFZND6re-9rx80w0KTF7bFV8QpWg7mmKI8BsY9NlOnm2DLlGW33d5n-2fBsuAOgOzv/exec?storeName=Marrickville+Xpress&page=TobaccoOrder

Observations from the **published client-side userHtml**, not guessed from an image:

- Page has separate `Tobacco Products Details` and `Tobacco Accessories Details` tables.
- The old cigarette table is populated from `tobaccoList` with `ProductName`, `Store_Closing` and `Store_Order`. It is read-only in the client. **Do not transplant its older sales-based data/calculation** to Alpha.
- Accessories input `#tobaccoInput` supports barcode entry (Enter) or a keyboard-icon search mode. The published list consists of `Name` and `Code_1`.
- Barcode/Enter accepts an **exact** `Code_1` or exact case-insensitive product name. Manual search filters names and `Code_1` by case-insensitive substring and selecting a result uses the same add/update function. Unmatched input shows a temporary `Product not found` message.
- First scan/selection adds a row at **OrderQty = 1**. Repeating the same product **increments OrderQty by 1** without duplicating the row. The row is highlighted and scrolled into view; input focus returns after addition.
- Tapping a row's quantity opens the **product-labelled numeric keypad** to replace the value. A delete control removes the row. The old finalize path strips zero-quantity rows and calls `UpdateOrder(storeCode, [{Name, OrderQty}])`.
- **Do not reuse that old `UpdateOrder` call to generate Alpha orders.** Alpha's future finalization must save its own reviewed snapshot as Sheets/PDF in Google Drive.

Verified current **Inventory DBA → Inventory** columns:
- A `Category`, B `Name`, C:E `Code_1`/`Code_2`/`Code_3`, F:H `Code_Box_1`/`Code_Box_2`/`Code_Box_3`.
- Y:AB contain MX/ET/DT/RT stock ceilings; AE `TobaccoOrder` distinguishes `AUTO` from `MANUAL`.
- Live example: `B & H 20's (Classic, Blue)` is `TobaccoOrder=AUTO` with ceilings MX=4, ET=4, DT=10, RT=16; `BIC Plain Big` and `RAW Classic Single Wide` are `TobaccoOrder=MANUAL`, with no configured ceilings.
- Product names and barcodes are identifiers; preserve leading zeroes. Do not infer quantity conversion from a box barcode without a verified `Packaging` rule.

**Alpha interaction contract to implement:**
- On the **cigarette physical-count** step, a valid product scan or manual selection adds it with **on-hand count = 1**; a repeated scan/selection increments the count by one. Tapping the displayed count opens a numeric keypad for an exact correction; provide row highlighting, scroll-to-row and fast refocus. Products *never scanned* retain effective count zero.
- Derive cigarette **OrderQty** separately and server-side: `max(0, selectedStoreCeiling - countedPhysicalStock)`, never from sales. Show ceiling, counted stock and calculated order as separate fields. Never conflate the counted-stock quantity with the order quantity.
- On the **accessories** step, match the old Add/Increment/Keypad/Delete behaviour for manual products, with an explicit requested order quantity (first add 1, repeat +1).
- Recognize the Inventory Sheet's barcode aliases wherever unambiguous, not merely `Code_1`; require a documented policy for carton/box scanning and non-unique barcodes before implementing a conversion.
- This document records inspected reference UX and accepted Alpha requirements. These stages remain **not implemented** until code and runtime evidence demonstrate otherwise.
