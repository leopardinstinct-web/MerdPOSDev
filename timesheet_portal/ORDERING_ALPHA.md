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


## 2026-11-02 — Owner-confirmed naming standardization rules for next Rosebay/Marrickville orders

**Status: source-name mapping rules approved, input preparation only. No new November order has been submitted or PDF generated from these rules.** Next batch: current owner-supplied Rosebay Express counts (2026-11-02 conversation), then wait for Marrickville counts. Do not reuse historical October 28 counts. Match each input by intended variant + pack size + Inventory DBA identity and barcode; ambiguous or missing names must never be silently substituted.

Owner-confirmed aliases and default behavior:

| Incoming owner shorthand | Canonical Inventory DBA item | Interpretation / caveat |
| --- | --- | --- |
| `Peter Stuyvesant 20's Original` / `Peter Stuyvesant Original` | `PS 20's (Blue, Original)` | Verified Inventory row 274, `Code_1=9327329016750`, `TobaccoOrder=AUTO`, RT ceiling 18 at time of check. Do **not** map to PS 30's or Red Virginia. |
| `Holiday 20's Bold` / `Holiday 20's Bold R` | `Holiday 20's Red` | Owner explicitly confirms Bold means Red, preserving separate Classic and Sun variants. |
| `RAW paper small with tips` / `RAW classic 1/4 with tips` | `RAW Rolling Paper + Tips 11/4 Size` | Intended **Classic 1¼ size + tips**, Inventory row 400, not Black or Organic Hemp; do not confuse with `RAW Classic Single Wide + Tips`. If actual barcode conflicts, flag rather than substitute. |
| `RAW paper large with tips` / `RAW long classic with tips` | `RAW Classic Kingsize + Tips` | Intended **Classic long/king size + tips**, Inventory row 377; exclude Black and Organic Hemp variants. |
| `Tallyho paper 5 pack` | `Tally-HO Papers (5 Pack)` | Owner confirms requested quantity **1**. |
| Other tobacco accessories with item but no quantity supplied | Exact matched `MANUAL` catalogue entry | Default **order quantity 1 each** unless a different explicit amount is given. The default does not apply to cigarette physical stock counts or to unmatched/noneligible products. |

Owner decision for the new batch: **skip remaining discontinued or not-found products**, including products that cannot be safely matched or are not eligible (`TobaccoOrder=NO`). A blank *store-specific* ceiling on an AUTO cigarette signifies discontinued for that store: skip it, never restore or infer an old ceiling. Preserve exclusions in the reconciliation report so omissions are auditable. Use current Inventory DBA as authority; the full-export `gid=0` reader is required so MANUAL accessories aren't dropped.

User-supplied cigarette/RYO numbers are **physical stock counts**, not direct purchasing quantities. AUTO purchase = `max(0, current store ceiling - physical count)`. MANUAL accessory numbers are **requested purchase quantities**, defaulting to 1 only when omitted. No duplicate alias merging without identity checks. Store-specific drafts/orders must be separate. Old immutable approved records must not be edited or reclassified.

**Next action:** receive Marrickville Xpress count list, reconcile both store lists with exact Inventory DBA names/barcodes, show unresolved items and exclusions, then generate new approved PDF/XLSX files through authenticated MERDPOS Portal (Alpha) via RDC/Kimi. Do not submit new orders or claim PDF completion before data reconciliation and owner-provided second store list.

**2026-11-02 owner confirmation — Rosebay Holiday Red duplicate:** In the newly supplied Rosebay stock-count list, `Holiday 20's Bold R = 1` and `Holiday 20's Bold = 2` are **the same SKU**, standardized to Inventory DBA `Holiday 20's Red`. Add the two physical on-hand counts: **1 + 2 = 3 units**. Record a single Rosebay AUTO count of **3** for that product; do not treat 3 as the supplier order quantity or create duplicate rows. For future lists, apply duplicate merging only when the owner confirms both descriptions represent the same SKU. All other naming/exclusion/default-accessory rules above remain unchanged.

**2026-11-02 owner-confirmed Marrickville accessory variant:** Incoming `Ranch menthol filters` maps **exactly** to Inventory DBA `Ranch Menthol Burst Supa Slims Filters` (Inventory row 362, MANUAL, barcode `9311616033551`), **purchase quantity 1**. Do **not** substitute `Ranch Cool Menthol Supa Slims Filters` (row 360). This applies to the next owner-supplied Marrickville Xpress batch and to future shorthand standardization when the same name is used; explicit future corrections take precedence. It is an accessory purchase quantity, not an AUTO stock count.

## November 2, 2026 — Fresh MX and RT orders from current owner counts

The Kimi webbridge earlier returned HTTP 502 because the browser session referenced stale tabs. The authorized Remote Desktop Commander route created a new Kimi bridge session `merdpos-nov-batch`, opened the MERDPOS Portal (Alpha) via its dashboard Cigarette Orders link, and confirmed an authenticated SUPER session. The first link navigation briefly landed on the timesheet dashboard; following the actual navigation link opened the correct authenticated orders interface.

The current Inventory DBA `Inventory` sheet full CSV data and live portal catalogue matched (193 AUTO, 78 MANUAL per store). The new owner-provided physical counts superseded October 28 data. Rosebay's two Holiday Bold/Red counts were merged to a single `Holiday 20's Red` physical count of **3**; the verified Ranch filter for MX is `Ranch Menthol Burst Supa Slims Filters` quantity **1**. Other MANUAL accessory orders default to quantity **1** when unspecified. Discontinued AUTO products with blank store ceiling, `NO`-type entries, and unconfirmed aliases were excluded; no Inventory DBA ceilings were changed. AUTO order quantity is `max(0, current ceiling - on-hand count)` with uncounted eligible products at zero stock. MANUAL quantity is requested purchase count, not stock on hand.

**New non-test SUPER-approved orders, each generated exactly once on 2026-11-02:**
- MX `ORD-20261102-5A30C0D576`: **18 order lines, 5 MANUAL accessories**, PDF **1 page**. Only the owner's November MX count plan was used.
- RT `ORD-20261102-11AC020406`: **44 order lines, 6 MANUAL accessories**, PDF **2 pages**. Holiday Red is one SKU with physical count 3.

The MX counting batch completed but the first pre-submit check expected a discontinued AUTO `order_quantity==0`; the live draft correctly returned `null`. After read-only inspection confirmed exactly 33 source counts and no November order, an idempotent finalize script submitted and approved once without reapplying counts. RT counted 44 source entries in five-item batches and approved once, with a receipt file guarding against duplicates.

All four authenticated PDF/XLSX downloads returned HTTP 200 with valid magic bytes. Read-only Namecheap SFTP confirmed each actual private `/home/dridsheikh/.merdpos_alpha_orders/approved/<STORE>/2026-11-02/<ORDER_ID>.pdf|xlsx` file, correct store/order identities, no test-copy wording, layout without page overflow or wrapped Category/Bar Code data, and private permissions. The private SQLite approved-order ledger verified `test_order=false` and exact MANUAL accessory-line counts. **No supplier dispatch occurred.**

Windows verified artifact: `C:/Users/Imran/Downloads/MERDPOS_November_2026_New_Orders/MERDPOS_Nov2026_Approved_MX_RT_PDF_XLSX.zip` (4 files, 15,744 bytes). Individual documents also reside in the same directory. SUPER can download via `timesheet_portal/api/order_download.php?id=<ID>&format=pdf|xlsx`.

Old October production records and QA immutable records remain untouched. A supplier-facing use should follow owner review of the exported order quantities.

## 2026-11-09 — Owner-confirmed Port Royal 30g alias (binding for all store orders)

**Definitive owner correction:** The names `Port Royal Original 30g`, `Port Royal 30gm`, `Port Royal 30gE Original` and `Port Royal RYO 30g Rum` refer to the **same product**. The canonical Inventory DBA entry is **`Port Royal RYO 30g Rum`**, Inventory row 272, code `9310797301930`. These aliases are **CONFIRMED**, not tentative; **do not ask the owner to reconfirm this mapping** or exclude it on name grounds in future standardization. Respect the store's current stock ceiling and `TobaccoOrder` eligibility separately.

For the latest 2026-11-09 owner-supplied physical counts, apply:
- MX: Port Royal Original 30g on hand **6**, current MX ceiling **3** → calculated order **0**.
- DT (Double Bay, user sometimes calls DX): Port Royal Original 30g on hand **3**, current DT ceiling **3** → calculated order **0**.
- RT (Rosebay, user sometimes calls RX): Port Royal 30gm on hand **8**, current RT ceiling **3** → calculated order **0**.

These are cigarette/RYO **physical stock counts, not accessory purchase requests**. In the pending reconciliation record, count the three aliases as matched and update the per-store stock counts; do not accidentally treat the previously excluded Port Royal SKU as uncounted/zero-stock or auto-order three units. The 2026-11-09 three-store orders are **not** approved or submitted until the owner reviews and explicitly confirms the complete standardized reconciliations.

## 2026-11-09 owner-confirmed Rosebay Rothmans Classic and RAW six-cone aliases

Owner answered **yes** to both exact mappings below. These are definitive mapping decisions for the current November 9 three-store reconciliation and future similar shorthand, unless the owner specifies another variant.

- `Rothmans 20's Classic` -> Inventory DBA **`Rothmans 20's Signature (Blue, Classic)`** (Inventory row 288, barcode `9310797300421`). Rosebay physical stock count **2**; current RT ceiling **2** -> calculated order quantity **0**. This is AUTO physical stock, **not** a direct purchase request.
- `RAW 6-pack pre-roll paper` -> Inventory DBA **`RAW Cone Classic 6 per Cone`** (Inventory row 383, MANUAL accessory). Rosebay requested purchase quantity **2**. This is a 6-cone pack product ordered in two units, not two individual cones.

Move both from unresolved to exact confirmed matches in the pending reconciliation; never treat the Rothmans SKU as uncounted/zero-stock. **This confirmation covers identities/quantities, NOT final authorization to submit or SUPER-approve MX, DT and RT orders.** Still present the full reconciled three-store review to the owner and obtain explicit final approval before any portal mutation.

## 2026-11-09 — MX, DT, RT newly submitted to MERDPOS Alpha for owner approval

On explicit owner instruction, standardized the fresh November 9 stock counts and accessories, skipped discontinued/no-store-ceiling, ineligible `NO` and unmatched SKUs, and submitted one **genuine non-test** order for each store in the existing authenticated SUPER portal. **No assistant approval, supplier dispatch or Inventory DBA change was performed.** The owner said they will review and approve in the portal; this supersedes earlier assistant-side final approval steps.

The Kimi local browser daemon had stopped on November 5; restarted `kimi-webbridge.exe start` through authorized Remote Desktop Commander, then used the authenticated `merdpos-nov09-pending-orders` browser session. Every store was empty beforehand and there were no existing 2026-11-09 orders. Every submitted order was guarded by a local receipt to prevent duplicate submission.

The current Inventory DBA ceilings and live portal catalogue (193 AUTO + 78 MANUAL) were cross-checked. Changes since the earlier draft: MX JPS 20's Original ceiling increased to 6; DT had nine changed eligible stock ceilings; RT had six changed listed product ceilings and an uncounted Dunhill Classic ceiling changed to 8. These were revalidated live before submission. The portal's established contract includes uncounted eligible AUTO cigarettes at zero on-hand, automatically replenished to configured ceiling. Port Royal Original 30g is the owner-confirmed alias of Port Royal RYO 30g Rum in all three stores. DT Riverstone 30g duplicate 1+2 = 3 physical on-hand. RT Holiday Red duplicate 1+2 = 3 physical on-hand; RT Rothmans Classic 20 maps to Signature (Blue, Classic) count 2 and RAW 6-pack cones maps to RAW Cone Classic 6 per Cone purchase quantity 2. RT Riverstone 30g physical count 10 yields order zero per owner's "no need this week".

New approved-for-review records (statuses below are last-observed and may be changed by owner):
- **MX** `ORD-20261109-0C3BA141D0`, 17 order lines, 33 units, 0 MANUAL accessory lines; submitted as PENDING. A later read-only history check showed `APPROVED` (not by assistant).
- **DT** `ORD-20261109-6ECA9FF513`, 20 lines, 50 units, 1 MANUAL accessory, `PENDING` on last check.
- **RT** `ORD-20261109-6EC854D8F6`, 36 lines, 147 units, 3 MANUAL accessory lines, `PENDING` on last check.

Local audit input: `C:/Temp/MERDPOS_2026-11-09_UNCONFIRMED_RECONCILIATION.json` (filename is historical; all matches/ceilings reconciled by submission), store receipts `C:/Temp/MERDPOS_20261109_<STORE>_PENDING_RECEIPT.json`. Final link: `https://app.merdpos.com/timesheet_portal/orders.php`; owner will review/approve and download PDF there. Do not resubmit or change approved records; read current statuses before any future action. Previous 2026-11-02 approved production orders and QA orders remain unchanged.

## 2026-11-16 — Current MX, DT, RT counts from owner-uploaded Word document

Source: owner file `Order 16 Nov 2026.docx` (three store lists), matched against Inventory DBA `Inventory` (gid 0, 193 AUTO + 78 MANUAL). New orders are submitted only as **PENDING for owner portal approval**; the assistant must never SUPER-approve or send to supplier.

The fresh full, machine-readable reconciliation lives on the user's authorized desktop:
`C:/Temp/MERDPOS_20261116_RECONCILIATION_DRAFT.json`
(sources, canonical names, physical counts, stock ceilings, excluded items and uncounted AUTO products). Script `C:/Temp/merd-20261116-submit-pending.py` provides a receipt/idempotency guarded portal workflow. For every store it verifies the current live catalog, excludes NO/blank-ceiling/unmatched SKUs, treats uncounted eligible AUTO products as zero stock under the established portal contract, uses the supplied physical count for matched AUTO, and uses requested accessory quantities (default 1 when absent).

**MX submitted** as genuine non-test `ORD-20261116-F1E8FE9CE5`, 23 lines, 43 units, **5 manual accessories**, initially PENDING; subsequent read-only portal check found **APPROVED** (not by assistant). MX exclusions included Parker & Simpson Blue+Fresh (blank MX ceiling), Choice Original Blue 30g (not in Inventory DBA, DO NOT reinterpret as 15g), Horizon Blue RYO 25g (blank), Rothmans Gold RYO 20g (ineligible) and Rothmans Red RYO 25g (blank).

**RT submitted** as genuine non-test `ORD-20261116-D7EC674D30`, 37 lines, 137 units, **3 manual accessory lines** (RAW Classic Kingsize + Tips x1, Clipper Round Large Graphics Jet Lighter x1, BIC Plain Small x2), **PENDING** on last verification. Holiday Bold Red 1 + Bold 2 = 3 physical count for one `Holiday 20's Red`, confirmed by owner in earlier batch; RYO Riverstone 30g physical count 9 with explicit "no need this week" order zero; previously confirmed aliases apply. NO, blank-RT-ceiling and not-in-list items excluded.

**DT remains unsubmitted and draft empty, awaiting exactly one owner decision:** `Parker and simp blue - 1` plus `parker and simp original - 3` both appear to refer to Inventory DBA `Parker & Simpson 20's Blue` (DT ceiling 3). Need owner to confirm same SKU and physical **1+3=4** before entering DT order. Do not silently merge duplicate counts. DT has zero manual accessories in this uploaded list. Other non-orderable products excluded under established rule: Rothmans 20's Signature Red/Bold (blank DT ceiling), JPS Original RYO 30g (blank), Choice Blue RYO 30g (not found), Choice Original Blue RYO 25g (not found), JPS Regular RYO 30g (not found). Older approved November 9 orders remain immutable.

**Next:** On owner confirmation that DT Parker & Simpson Blue and Original are same SKU, update the draft JSON so one canonical `Parker & Simpson 20's Blue` AUTO physical count is 4; current DT ceiling was 3 so order zero. Re-read live Inventory DBA/portal ceilings before submitting, guard against duplicate Nov 16 DT order, then create genuine non-test **PENDING** order for DT only. If the owner says they differ, clarify the exact separate variants instead. Never edit or resubmit MX/RT. After DT submission provide all new order IDs and portal review link.

## 2026-11-16 — Owner resolves DT Parker & Simpson Blue stock duplication

User explicitly confirms `Parker and simp blue - 1` and `Parker and simp original - 3` in the November 16 Double Bay list are **the same Inventory DBA `Parker & Simpson 20's Blue` SKU**. Record one DT AUTO physical count **4 (1+3)**, not two records; live DT ceiling is **3**, so computed purchase quantity **0**. This confirmation was applied to the user's desktop reconciliation `C:/Temp/MERDPOS_20261116_RECONCILIATION_DRAFT.json`. On a subsequent read-only live Portal Alpha check, DT remained empty, no new November 16 DT order existed, and live Parker Blue catalogue entry type AUTO ceiling 3 was verified.

**Interruption / new required input:** Immediately afterward the user supplied an inline image described as `tobacco accessory requirements for double bay`. The image is not represented by a readable mounted or retrievable file in this environment, and the accessory names and quantities must not be guessed. **Do not submit the currently tobacco-only DT order as a completed order until those new accessory lines are reliably transcribed and matched** to Inventory DBA MANUAL products (or the user explicitly directs submitting without them). The previously computed 24 tobacco order lines / 65 units are preliminary, before accessories. Once available, append exact MANUAL items to the desktop reconciliation, verify current ceilings, and submit one genuine non-test **PENDING** DT order. MX and RT 2026-11-16 orders have already been submitted; do not touch or resubmit those.

### 2026-11-16 — Double Bay accessory photograph successfully inspected (supersedes inaccessible-image note above)

The owner re-uploaded the accessory photo as a mountable image. The photograph was read and rotated for legibility. **Four distinct products** appear, with no explicit quantities. Under the established manual accessory default, propose **1 purchase unit each**.

Confirmed exact Inventory DBA MANUAL matches, already staged in the authorized Windows reconciliation `C:/Temp/MERDPOS_20261116_RECONCILIATION_DRAFT.json` without any portal change:
- `Ventti Extra Long Micro Slim Filters`, Inventory row 411, barcode `9310537107648`, qty **1**; pictured 140 filter tips
- `OCB Slim Long + Filters`, row 355, barcode `30107014`, qty **1**; pictured OCB Premium Slim + Filters long
- `RAW Organic Hemp 1¼ Size + Tips`, row 389, barcode `716165176121`, qty **1**; pictured RAW Organic Hemp Connoisseur 1¼ + Tips with elastic band. The barcode is verified in external catalogue as the Connoisseur variant; do not confuse with row 394.
- **Unresolved fourth product** photographed: `OCB Premium 1¼ + Filters`, qty default 1. Inventory DBA has `OCB Slim Small`, row 356, barcode `30063082`, but that barcode identifies a paper-only product without filters, **not an exact match** to pictured `OCB Premium 1¼ + Filters`. Do not misrepresent substitution as exact.

Double Bay previously confirmed Parker & Simpson Blue/Original stock count **4**, current DT ceiling **3**, order **0**. The local staging plan now includes three exact manual accessory lines, leaving one exception in `DT.ambiguous`. **No 2026-11-16 DT order has been submitted.** Pre-decision draft totals: **27 lines, 68 units**, excluding unmatched OCB 1¼ + Filters. Owner must choose **exclude unmatched** or **explicitly substitute OCB Slim Small paper-only**. Once decided, clear the exception, update the plan and submit DT as genuine non-test **PENDING for owner approval only** via authenticated Alpha. MX and RT are previously submitted; do not touch them.

## 2026-11-16 — Double Bay completed with newly added exact OCB Premium 1¼ + Tips

**Supersedes the earlier unmatched OCB accessory warning** in this document. Owner supplied the exact standard row `OCB Premium 1 1/4 with Tips`, barcode `3057067394242`. Live Inventory DBA `Inventory` gid=0 now contains it at row **425**, with `TobaccoOrder=MANUAL` (the source now has **193 AUTO and 80 MANUAL** eligible entries). Another added MANUAL entry is RAW Classic 1 1/4 Rolling Papers (row 426). Earlier 193/78 catalogue snapshots are stale.

The authenticated Alpha portal initially still had its old session-pinned inventory catalog (193 AUTO / 78 MANUAL) even though the live Google Sheet had been updated. `timesheet_portal/api/order_count.php` pins `order_draft_catalogue` per store. Since the **DT draft was empty**, an authorized `order_workflow.php` `action=new` cleanly refreshed the DT catalogue **without touching existing approved/pending orders**, returning **193 AUTO + 80 MANUAL** and the exact OCB barcode. Never attempt silent substitution to OCB Slim Small, which has no attached tips.

The four manually requested accessories identified from owner photo and ordered as **one purchase unit each**:
- Ventti Extra Long Micro Slim Filters, barcode `9310537107648`
- OCB Slim Long + Filters, barcode `30107014`
- RAW Organic Hemp 1¼ Size + Tips, barcode `716165176121`
- **OCB Premium 1 1/4 with Tips**, barcode `3057067394242`

The owner explicitly confirmed DT Parker & Simpson Blue 1 + Original 3 is **one SKU** (`Parker & Simpson 20's Blue`) with **4 physical on-hand**, DT ceiling 3, hence **zero replenishment**.

**New authentic non-test DT order `ORD-20261116-0149E2A421`**, **28 order lines, 69 total units, 4 MANUAL accessory lines**, submitted through MERDPOS Alpha and independently verified **PENDING** for owner's own review/approval. The assistant did not SUPER-approve or dispatch anything. Source plan `C:/Temp/MERDPOS_20261116_RECONCILIATION_DRAFT.json`, protected receipt `C:/Temp/MERDPOS_20261116_DT_PENDING_RECEIPT.json`, and submission script `C:/Temp/merd-20261116-submit-DT-updated-catalog.py` are on the authorized Windows PC. All new November 16 orders are now submitted; DO NOT resubmit.

Last read-only status check:
- MX `ORD-20261116-F1E8FE9CE5`: APPROVED (23 lines / 43 units / 5 accessories)
- DT `ORD-20261116-0149E2A421`: **PENDING** (28 lines / 69 units / 4 accessories)
- RT `ORD-20261116-D7EC674D30`: APPROVED (37 lines / 137 units / 3 accessories)
Owner may now inspect and approve DT at `https://app.merdpos.com/timesheet_portal/orders.php`. Existing MX and RT approved records were not altered by the assistant.

## 2026-11-17 — Fresh reprocessing after product owner removed discontinued stock ceilings (PORTAL SUBMISSION BLOCKED)

**User request:** reprocess the complete 2026-11-16 MX/DT/RT owner stock counts plus all verified accessories using the **new live Inventory DBA ceilings**, submit new genuine Alpha orders as **PENDING only**, for owner's manual approval. Previous Nov 16 approved records are immutable and must never be reused, edited, or automatically marked superseded. Do not approve or send to supplier.

The original Word attachment `Order 16 Nov 2026.docx` was reopened directly and stock counts compared to the earlier fully reconciled three-store plan. The photographed DT four accessories include exact `OCB Premium 1 1/4 with Tips` barcode `3057067394242`, added to Inventory DBA and Alpha in prior session. Confirmed DT Parker & Simpson Blue/Original physical count 1+3=4, and RT Holiday Bold Red/Bold count 1+2=3, continue to apply.

**Live snapshot:** Inventory DBA sheet `Inventory` gid=0, 193 AUTO, 80 MANUAL, 456 NO. Store ceiling-controlled eligible AUTO count: MX **30**, DT **36**, RT **41**. Fresh count calculation (`max(0, ceiling - supplied physical count)` for eligible AUTO, 0 stock for unspecified eligible AUTO; exact MANUAL accessory request quantities) yielded:
- **MX: 19 lines / 35 units**, including 5 manual accessory lines. Old November 16 order `ORD-20261116-F1E8FE9CE5` was 23 lines / 43 units (8 fewer).
- **DT: 25 lines / 62 units**, including 4 manual accessory lines. Old November 16 order `ORD-20261116-0149E2A421` was 28 lines / 69 units (7 fewer).
- **RT: 28 lines / 107 units**, including 3 manual accessory lines (4 individual accessory units, incl Bic Small x2). Old November 16 order `ORD-20261116-D7EC674D30` was 37 lines / 137 units (30 fewer).

**Safety critical:** new source ceilings blank for discontinued items are excluded even if old orders included them. 30g RYO SKUs **still replenished solely because active ceiling and no submitted physical count**:
- MX `Winfield RYO 30g Signature Gold` x2
- DT `Winfield RYO 30g (Blue, Classic Blue)` x2
- RT `Champion RYO 30g (Ruby, Distinct)` x5
Do NOT assume these are discontinued; the live sheet retains nonblank ceilings. The owner specifically reported harmful 30g RYO overstocking and should inspect these lines before approving corrected orders. Other explicitly counted 30g RYO products with positive replenishment: MX Champion 30g x3; DT Champion 30g x2; RT Manitou Organic 30g x2. User's RT Riverstone 30g stock 9, "no need this week", remains order 0.

**Execution blocker:** Authorized Windows Remote Desktop Commander device `d01d43f4-7121-42ed-805d-e33a039d8f1b` went **offline while preparing the fresh three-store reconciliation**, so no new corrected portal orders have been submitted. Do **not** claim any new Nov 17 IDs or PENDING status. The latest prior approved orders remain visible and do NOT automatically get canceled or replaced. Remote device must be reconnected. Once available, recreate the current live Sheet snapshot and confirm it still matches, check for any existing new November 17 orders and nonempty store drafts, reset empty per-session pinned catalogues only when safe, enter all original Nov16 counts including accessories with the corrected ceilings, independently verify order lines/units, and submit exactly once per store as PENDING. Preserve receipts to avoid duplicate sends and report the resulting IDs. Never automatically SUPER approve.

The user's last reply has already received a recalculated three-store total comparison, but completing actual portal submission awaits restored authorized desktop connectivity.

## 2026-11-17 — Corrected three-store orders SUCCESSFULLY SUBMITTED PENDING (supersedes interruption note)

The earlier "portal submission blocked" note immediately above is superseded. Remote Desktop Commander returned online, and a safe Kimi browser session `merdpos-nov17-reprocess` was established.

The owner requested reprocessing all three 16 Nov orders using updated live Inventory DBA ceilings to eliminate discontinued product replenishment and unintended 30g RYO overstock. The original 16 Nov DOCX and verified DT photo/OCB barcode were retained as the source; no new counts were invented. A new master snapshot `C:/Temp/MERDPOS_20261117_CURRENT_MASTER.json` captured live Inventory DBA `Inventory` gid0 (193 AUTO, 80 MANUAL), and the recomputed line-level manifest `C:/Temp/MERDPOS_20261117_FRESH_RECALC.json` was validated against the **complete 273-product catalogue and all current per-store ceilings** on the authenticated Alpha portal before any count entry.

Per-store safe preflight found MX an empty draft with an older pinned 193/78 catalogue, DT an unsubmitted *editing copy* of old `ORD-20261116-0149E2A421`, and RT an empty older-pinned draft. DT's editing copy was durably saved to `C:/Temp/MERDPOS_20261117_PRIOR_DRAFT_SNAPSHOT_DT.json` and compared EXACTLY to original owner counts; its associated order was already **REJECTED**. A blank `action=new` cleared only that local editing draft without changing the stored rejected order. Empty MX/DT/RT draft snapshots were refreshed to 193 AUTO + 80 MANUAL each; old immutable orders were not altered. No new concurrent PENDING or conflicting November 17/18 orders existed at preflight.

Each new order was submitted exactly once as genuine non-test **PENDING** only, through authenticated Kimi webbridge `orders.php`; **no SUPER approval, supplier send, Inventory DBA edit, or source alteration** was performed. Guarded scripts and receipts on user PC:
- `C:/Temp/merd-20261117-submit-one-pending.py`
- `C:/Temp/MERDPOS_20261117_MX_PENDING_RECEIPT.json`
- `C:/Temp/MERDPOS_20261117_DT_PENDING_RECEIPT.json`
- `C:/Temp/MERDPOS_20261117_RT_PENDING_RECEIPT.json`

**Independently verified corrected records:**
- **MX:** `ORD-20261116-95067E161E` — **PENDING**, 19 lines, **35 units**, 5 accessory lines. Prior approved `ORD-20261116-F1E8FE9CE5`: 23 lines, 43 units.
- **DT:** `ORD-20261116-127E71AF7C` — **PENDING**, 25 lines, **62 units**, 4 accessory lines. Prior `ORD-20261116-0149E2A421`: **REJECTED**, 28 lines, 69 units.
- **RT:** `ORD-20261116-FDFA5D55F2` — **PENDING**, 28 lines, **107 units**, 3 accessory lines (four accessory units). Prior approved `ORD-20261116-D7EC674D30`: 37 lines, 137 units.

Total new **204 units**, down **45** from previous 249. Order IDs bear `20261116` prefix generated by the production portal even though this reprocessing occurred November 17 user-local; preserve actual IDs.

**CRITICAL owner-review flag:** The normal portal contract still replenishes *uncounted* eligible AUTO products as zero stock if their ceiling remains active. Three 30g RYO lines remain on this basis: MX Winfield Signature Gold 30g **2**, RT Champion 30g **5**, while DT Winfield Classic Blue 30g **2** is based on a supplied physical stock count of **1**, not uncounted. Other explicitly counted RYO 30g replenishments: MX Champion 30g **3**, DT Champion 30g **2**, RT Manitou 30g **2**. Ask owner to review these before approval if they do not want uncounted products replenished; do not silently alter ceilings or counts.

**WARNING:** Old MX and RT approved documents remain in order history, not automatically canceled or superseded. They must NOT be forwarded again or used as the corrected orders; if already sent to suppliers, new pending orders could duplicate procurement. The owner will approve ONLY the corrected IDs after review at `https://app.merdpos.com/timesheet_portal/orders.php`.

This completion record supersedes earlier "offline" status notes. Do not resubmit any of these three orders.

## 2026-11-23 — Marrickville current owner counts, new genuine PENDING order

User supplied a fresh **MX-only** list (19 cigarette descriptions, 15 RYO descriptions, **no accessories**) and instructed to generate it in MERDPOS Portal Alpha for owner approval only. All 34 lines were standardized against the live Inventory DBA sheet (`Inventory` gid0; 193 AUTO / 80 MANUAL). The full eligible catalogue and **every current MX ceiling** were independently compared with the authenticated portal's pinned catalogue before any stock entry. No prior approved records were edited.

Applied previously approved aliases (including Port Royal Original 30g = `Port Royal RYO 30g Rum`, Winfield Sun 30g = `Winfield RYO 30g Signature Gold`) and treated supplied values as physical on-hand counts. `Marlboro Red+ firm filter 20` was **not** treated as `Marlboro 20's (Red, Blended R)` because it is a distinct variant not in the eligible catalogue. Unmatched and discontinued/blank-ceiling names were excluded, not guessed.

**Excluded 7 user inputs:** `Marlboro Red + Firm Filter 20` (unmatched), `Parker & Simpson 20's Blue+Fresh` (blank MX ceiling), `Choice Original Blue RYO 30g` and `25g` (not in catalogue; do not substitute 15g), `Horizon RYO 25g Blue` (blank MX ceiling), `Rothmans RYO 20g Gold` (not eligible), `Rothmans RYO 25g Red` (blank MX ceiling).

The standard Alpha ordering contract replenishes uncounted eligible AUTO products from 0 physical stock: `JPS 20's (Gold, Regular)` **3**, `Marlboro 20's (Gold, Regular G)` **4**, and `PS 20's (Blue, Original)` **4**, for 11 uncounted units. All specified 30g RYO counts were applied (e.g., `Winfield RYO 30g Signature Gold` stock 2, MX ceiling 2, order 0; `Champion RYO 30g` stock 2, ceiling 4, order 2).

**Submitted authenticated non-test order `ORD-20261123-811CAB3D45`, 14 order lines / 31 units, zero MANUAL accessories.** Independent follow-up portal read verified status **PENDING**, test flag false and new MX draft blank. No SUPER approval or supplier dispatch performed. Old approved MX `ORD-20261116-95067E161E` and `ORD-20261116-F1E8FE9CE5` remain APPROVED, unchanged.

Authorized Windows audit files: `C:/Temp/MERDPOS_20261123_MX_CURRENT_ORDER_PLAN.json`, `C:/Temp/MERDPOS_20261123_MX_PENDING_RECEIPT.json`, script `C:/Temp/merd-20261123-mx-submit-pending-once.py`; session `merdpos-nov23-mx-current`. **Do not resubmit.** Owner may review and approve at `https://app.merdpos.com/timesheet_portal/orders.php`.
