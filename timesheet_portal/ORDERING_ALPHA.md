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

## Stage 2 — stock counting and accessories draft (coded; release review/deployment pending, 2026-10-28)

- Reuse includes/sheets.php transport for Inventory DBA CSV. Add exact barcode alias resolution, robust blanks handling, constrained cache lifetime.
- Read selected store's Y/Z/AA/AB ceiling; phone scan and manual search/count fallback.
- Missing scans default to zero when calculating orders; invalid/missing ceiling needs explicit business handling rather than inferred values.
- Current implementation persists draft counts and a fixed Inventory DBA catalogue snapshot in the authenticated PHP session, isolated by store; subsequent Sheet edits do not silently reset counts. A new login session reloads the catalogue. Stock counts are not permanently submitted or restored after session expiry. Quantity editing and resetting are implemented.

## Stage 3 — accessory generation / final preview (partly coded; submission still not implemented)

- Scan or manual search accessories; explicit quantities, repeated +1 and keypad corrections are implemented as session drafts.
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
- This document records inspected reference UX and accepted Alpha requirements. Stage 2 draft-counting screens and session-only API now have local test evidence; order finalization, Google Drive saving and SUPER submission remain unimplemented.

## Packaging barcode quantity — product-owner decision 2026-10-28

- **Approved:** Scan of any matching `Code_Box_1`, `Code_Box_2` or `Code_Box_3` adds the corresponding product's positive integer `Packaging` (column AC) to its physical unit count; the raw box count must never be used as the unit count.
- `Code_1`, `Code_2`, `Code_3` scans each add **one unit**. Manually selecting a product adds **one unit** unless staff explicitly change its quantity in the keypad. Repeated scans accumulate the same respective increment.
- For example, if `Packaging=10`, one carton scan increases the cigarette on-hand count by 10; if the applicable ceiling is 16, calculated order quantity becomes 6. Counts are physical individual units; `Packaging` affects barcode increments, not the ceiling.
- If a box barcode is present but `Packaging` is missing, zero, nonintegral or negative, **do not silently count one**. Reject the conversion with a clear operator-visible error; manual quantity correction remains available.
- If a barcode maps to more than one distinct eligible product across either AUTO/MANUAL or both a unit and box identity, **do not guess**: require explicit product selection before changing quantities. Store codes and `TobaccoOrder` eligibility must still be enforced.
- A product with `TobaccoOrder=NO` must not be silently included in the Alpha tobacco ordering catalogue merely because its barcode is recognizable. `AUTO` cigarette physical counts and `MANUAL` accessories remain separate workflows.
- As of the live 2026-10-28 Inventory DBA audit: **121** of 728 product rows have a nonempty box barcode (F:H), and all 121 have positive integer `Packaging` values; all 121 currently have `TobaccoOrder=NO`. **No AUTO or MANUAL product currently has a box barcode populated.** Another 250 rows have positive packaging values but no box barcode; those must not trigger carton conversion on unit barcode scans. Eleven barcodes occur on multiple different product rows, all within the NO catalogue, and should be treated as ambiguous if brought into scope in future.
- Stage 2 implementation uses this rule. No eligible AUTO/MANUAL product currently has box barcodes in Inventory DBA, so the carton conversion is tested with a synthetic eligible product; an on-site carton scan remains untested.

## Combined Stage 3/4: Quantity steppers, durable review, approval and exports (2026-10-28)

- No separate Actions column. On-hand (AUTO) and accessories (MANUAL) use an inline **minus / editable integer textbox / plus** control; no Reset button. A zero quantity removes a selected row; unscanned AUTO stock still defaults to zero.
- Both USER and SUPER may select any store and generate a submitted order. A unique, durable `ORD-YYYYMMDD-XXXXXXXXXX` is stored in private SQLite outside the public document root with its pinned Inventory DBA catalogue, counted stock, ceiling snapshot, submitted author/time and immutable order-line preview.
- Multiple submissions per store and within one PHP login session are supported: after submitting an order the active count draft clears and a fresh nonce is issued. Replaying the old draft nonce is rejected.
- PENDING orders are editable **only by their submitting account**. Reopen count snapshot, change quantities, and resubmit the **same order ID**, with optimistic revision checks. `Start a new order` discards only the unsent active draft, never submitted records.
- SUPER sees genuinely submitted orders from all stores, can approve or reject with optional note. SUPER is enforced on the server; UI hiding alone is insufficient. Approval is atomic; APPROVED and REJECTED entries cannot be modified or reviewed twice.
- **PDF and XLSX files are generated only on SUPER approval**, from the server-approved immutable order snapshot. Both are stored outside the web root. Only SUPER may download approved files. USER can view own submission status and review remarks, but cannot download protected files.
- Excel barcodes and product names are written as text cells to preserve leading zeroes and prevent formula injection. PDF follows the supplied October 19, 2026 store order templates with date, store, address, category, barcode, product, order quantity and page footer. It cannot include the old Google share URL/QR without a new authorized Drive link.
- The live Inventory DBA contains many blank ceilings (example MX: 160 AUTO rows). These products are explicitly **excluded** from order quantity calculation (not assumed zero, not inferred); the UI warning and SUPER order record include the exclusion count. Valid store ceilings continue to use `max(0, ceiling - physically counted units)`. No sales scraping.
- Existing timesheet logic, login sheet links, and CSV parser behaviour remain unchanged.
- Current supported backend: private host SQLite + server-side PDF and XLSX. **Automatic creation of Google Drive files is not wired**: the production legacy PHP portal has no verified Google Drive write token/service account. Do not substitute browser credentials or call the old sales-based Apps Script. Google Drive publishing remains a separately blocked integration until a supported server-side authorization method is supplied. Never claim the exported files were saved to Drive.
- Local deterministic test `tests/order_approval_smoke.py` uses isolated temporary private storage/synthetic roles; tests multiple orders, edit pending, CSRF, reject/approve lock, SUPER-only PDF/XLSX, no Google data mutations. `tests/order_alpha_browser.py` tests mobile steppers and pending edit UX.

## Independent review fixes — 2026-10-28

- All PDF table text is now wrapped based on actual FPDF font metrics with row height reserved before rendering, repeated headers on page breaks, and fail-closed handling when one row cannot fit a page. Regression `tests/order_pdf_layout.py` checks 65 long-name rows across 17 pages, every product once and no overflow.
- If PHP crashes between approving-file promotion and SQLite transaction commit, a later SUPER approval attempt safely regenerates orphan files while the order is still PENDING and under a SQLite write lock. Approved orders cannot be modified or regenerated.
- Order history is **server-side paginated**, 40 entries per page, across all submitted orders with store and status filters. SUPER's pending counts per store are computed from the entire SQLite ledger, not the current page. Older pending and approved orders remain navigable/downloadable.

## 2026-10-28 production incident hotfix (PR follow-up)

- Live operator screenshot: `Order could not be saved. Please retry.` Server error log `Alpha order workflow: There is no active transaction` (at 11:31 and 11:55 UTC). Cause: PHP/PDO version used `exec('BEGIN IMMEDIATE')` but `PDO::commit()` / `inTransaction()` did not track the manually started SQLite transaction. All three affected mutations now pair raw SQLite `BEGIN IMMEDIATE` with raw `COMMIT` / `ROLLBACK`, preserving lock semantics and rollback on errors.
- SUPER may edit PENDING orders from any submitting USER (original author preserved). PENDING and REJECTED orders can be soft-deleted by SUPER only; APPROVED remain locked. Additive nullable `deleted_at`/`deleted_by` SQLite schema migration retains audit history; deleted records are excluded from active lists and actions.
- SUPER may mark a newly generated order as **QA TEST — not for supplier**. A test marker travels with the stored snapshot, visible in order history and printed prominently on approved PDF/XLSX. It is never a supplier submission, and no Inventory DBA data is modified.
- Expanded isolated tests: SUPER edits a USER's PENDING order, 2+ orders same session, approves/rejects, role auth, SUPER soft deletion (and approved lock), QA test order marker and deletion, outputs, SQLite rollback and mobile rendering. Never use synthetic data in production tests without explicit test marker.
- Release gate: independent immutable-SHA review, CI, staging and hash verification, private SQLite backup then production host deploy; finally exercise live SUPER UI with clearly tagged QA test orders only.

## PR #193 — production hotfix and live SUPER QA, 2026-10-28

Production root cause was confirmed through live PHP error_log: \`There is no active transaction\` from manual SQLite \`BEGIN IMMEDIATE\` combined with PDO \`commit\` / \`inTransaction\`. Corrected to SQL-level COMMIT/ROLLBACK on all order mutations. Additive, race-safe tombstone migration supports SUPER soft-delete of PENDING/REJECTED only; APPROVED stays locked. SUPER may edit any PENDING order while preserving submitting author. QA TEST status is set at initial submission, preserved through edits, shown in SUPER history and visibly stamped in approved PDF/XLSX. Live authenticated Kimi Web bridge QA on Rose Bay: rejected/deleted \`ORD-20261028-7DCC79CA5C\`, approved/locked \`ORD-20261028-188820D8B7\`. Both records are QA TEST, no supplier order. Private PDF three pages and XLSX independently opened and verified. No Google Drive save; no inventory or payroll data writes. Physical phone-camera acceptance and real separate USER account remain to be checked.


## 2026-10-28 — Namecheap-only approved order documents (supersedes Drive requirement)

The product owner chose private Namecheap-hosted PDF/XLSX storage instead of automatic Google Drive uploads. Do not turn on Drive publishing without a new explicit decision. The preexisting SUPER approval workflow and server-side private SQLite ledger remain authoritative.

- Newly approved exports use `.merdpos_alpha_orders/approved/<STORE>/<YYYY-MM-DD>/<ORDER_ID>.pdf` and `.xlsx`. Store comes from the stored order ID's SQLite record, and date from the validated order ID, never client input.
- Previous approved documents in the flat `approved/<ORDER_ID>.<ext>` location remain readable at their immutable original paths. No overwriting, migration or recreation of prior approved documents.
- Files remain outside the public document root and SUPER-only downloads continue via `api/order_download.php`, not web-server static URLs.
- Namecheap guarded deployment of `timesheet_portal/includes/order_export.php` verified via remote PHP lint and SHA-256 (`898121a22566268c41cfcf789dcd81af17ec5e5757dd1729a914188a3ac9a4c1`). No new real unmarked supplier-facing order was approved as part of that deployment.
- The earlier Google Drive integration source files were removed from the authoritative branch; no server-side Drive credentials are needed.


## 2026-10-28 full Inventory catalogue export repair and production order status

The previous `/gviz/tq?tqx=out:csv&sheet=Inventory` URL served only **82** data rows (71 AUTO, 0 MANUAL) while the underlying Inventory tab had **728** data rows (193 AUTO, 78 MANUAL). This caused missing accessories in three approved QA orders and stale per-session pinned catalogues. The exact `Inventory` sheet ID is 0. The Alpha reader now uses `/export?format=csv&gid=0`, an independently versioned cache key, and fails closed on fewer than 100 data rows or no eligible AUTO/MANUAL products. Source commit `954ed9d6bb748edd95a80cd908957a7ab3efcb18`; Namecheap guarded staged deploy passed remote PHP lint, full-catalogue smoke and live SHA-256 `bca9aaf875bb1eb6b55bc6e89f0e3125163fd87f4c0d1ea6fe18a4160963bb0e`.

The SUPER browser session's empty drafts were reset per store with the existing `action=new` API, which clears only that store's unsent draft and pinned catalogue. The reloaded Alpha catalogue contained 193 AUTO and 78 MANUAL products per store. The source plan and live catalogue were compared by exact product ID/name/type. **MX production order `ORD-20261028-C60C46680F` (33 lines, 11 MANUAL)** and **DT production order `ORD-20261028-4A3DF81270` (36 lines, 1 MANUAL)** were submitted and SUPER-approved using supplied stock counts, live store ceilings, and `test_order=false`. Both generated protected PDF/XLSX downloads (HTTP 200, %PDF/ZIP magic), were read from Namecheap private store/date locations and checked for absence of QA/test-copy language and single-line Category/Bar Code. Earlier approved QA records were not modified or reclassified.

**Rosebay RT remains blocked from non-test order approval:** four previously populated ceiling values are blank in current Inventory DBA — JPS 25's Red (previous 5), JPS 30's Red (previous 4), Winfield RYO 20g Red (previous 2) and Winfield RYO 25g Red (previous 2). These were not guessed, overwritten in Inventory DBA, or silently excluded from a newly approved production order. Remaining RT data and 10 MANUAL accessories match the supplied historical plan. Existing QA RT orders remain distinguishable as tests.

The precise PDF text `APPROVED TEST COPY - DO NOT FULFIL` remains for QA-classified immutable documents only. Genuine new production orders automatically show `SUPER approved` instead and have no QA footer. Do not remove QA classification from past test orders.


## 2026-10-28 product-owner correction: discontinued products and RT production output

**Authoritative owner decision, superseding the earlier "RT blocked / restore four ceilings" instruction:** Blank per-store stock ceilings are deliberate exclusions for discontinued products. **Never restore an old ceiling from a historical plan, infer a zero ceiling, or demand stock-count reconciliation for a discontinued SKU.** For AUTO cigarettes, include only current eligible products with a valid store ceiling; use `max(0, ceiling - counted stock)`. MANUAL accessories remain independent of AUTO ceilings and must use their explicitly requested quantities.

With the current full Inventory DBA catalogue (193 AUTO + 78 MANUAL), the authenticated SUPER Kimi bridge compared the owner's 43 RT source-plan entries against current product IDs/names/types. It excluded 11 discontinued/no-ceiling AUTO products, including JPS 25's Red, JPS 30's Red, Winfield RYO 20g Red and Winfield RYO 25g Red, and applied exactly 32 applicable count entries, including 10 MANUAL accessory quantities. A batch browser await timed out **after all 32 writes succeeded**; read-only session inspection recovered exact saved counts and confirmed there was no newly submitted non-test RT order. No counts were replayed.

RT order `ORD-20261028-DC0AB41C1C` was then submitted through authenticated SUPER with `test_order=false` and approved. The order contains **56 lines, including 10 MANUAL accessory lines**. PDF (3 pages) and XLSX returned HTTP 200 with PDF/ZIP signatures, and read-only SFTP + private SQLite verification confirmed immutable approved state, 10 MANUAL lines, exclusion of all 11 discontinued source items, no QA/test-copy wording, private Namecheap store/date paths and unwrapped Category/Bar Code columns. **No supplier dispatch and no Inventory DBA edits occurred.** The earlier MX and DT production orders were independently revalidated alongside RT.

Final combined file set on the authorized Windows computer: `C:/Users/Imran/Downloads/MERDPOS_Production_Orders_Accessories_20261028/MERDPOS_Approved_MX_DT_RT_With_Accessories.zip` (three production PDFs + three XLSX files). Protected portal download endpoint: `timesheet_portal/api/order_download.php?id=<ID>&format=pdf|xlsx`, SUPER login required. Prior approved QA orders remain immutable and distinguishable.
