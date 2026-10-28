# Integrated Manual Order sheet — Alpha

Source: the user-supplied **Manual Order.xlsx**. The `Order Sheet` tab has four horizontally arranged blocks for Marrickville Xpress, Enmore Xpress, Double Bay Xpress and Rosebay Xpress. Each block has a navy date band, blue store-name band, navy street-address band, an old `share.google` URL, and the four-column order table **Category | Bar Code | Product Name | Order Quantity**. Other worksheets contain supporting references and manual data.

## Implementation

The portal remains one selected store per draft on phones, not a four-store-wide spreadsheet. The active stock-count screen now displays a live workbook-style order sheet below scanner/search/on-hand controls. As users scan or change quantities, the sheet immediately recalculates using the same server-returned products, ceilings, and values. The separate **Generate order** card and redundant **Order progress** wizard are removed; the submit action lives directly inside the live workbook-style sheet.

The SUPER order-details view uses the same four-column sheet component. On approval, the existing locked FPDF renderer produces a newly styled blue/navy header and four-column table repeated on each page. Earlier approved PDFs are unchanged and remain immutable; new styling applies only to newly approved orders.

## Behaviour preserved

- The standard product catalogue comes from Inventory DBA; do not trust broken workbook-export formulas like `#NAME?`.
- Unscanned eligible AUTO cigarettes have on-hand zero and order quantity `max(0, ceiling - count)`. Missing ceilings are not orderable. Accessories use selected order quantity.
- Barcode camera, packaging multiplier, session drafts, multiple orders, USER edit pending, SUPER review/approval/reject, locked private PDF/XLSX and audit remain in place.
- Do not reuse old workbook `share.google` QR destinations. This release does **not** add a misleading QR. Add QR only with a verified destination for the new approved order.
- Date is generated in Australia/Sydney; do not reuse the workbook date.
- PDFs are generated **only upon SUPER approval**. No supplier order, Inventory DBA modification, or Google Drive write is added.

## Required end-point

Deploy the reviewed source to the **legacy production Alpha portal**, then create explicitly QA TEST-marked orders through the live authenticated SUPER workflow, approve them, verify new-style PDF contents visually, and leave user-accessible authenticated PDF links. Do not change earlier approved orders.

## Tests

- `tests/order_alpha_browser.py`: mobile 390px live sheet, four-column header, scanner/search and accessory updates, history and pending edit, zero page errors.
- `tests/order_pdf_layout.py`: font-metric wrapping, repeated branded store/date/address and column headers over long multi-page orders, correct blue fill, fail-closed pathological row.
- `tests/order_approval_smoke.py`: existing isolated USER/SUPER workflow, approvals, locks and PDF/XLSX.
- PHP lint, JS syntax, Git clean diff and independent review before deployment.

## Independent review corrections

Initial immutable KIMI_WEB review of `58edd1d` returned FIX-FIRST for a permitted near-limit row overflowing the reduced PDF body beneath the new repeating header; also noted timezone-less timestamps and potentially mangled punctuation. The PDF now rechecks available body space **after** a new branded page plus table header before rendering, failing closed if the row still will not fit. Both PHP and JS interpret timezone-less legacy timestamps as UTC; output text is ASCII-safe. A targeted QA-layout test probes short through near-limit rows and requires both safe rendering and safe refusal. Await independent delta review and current CI before production deployment.

Second independent delta review of `8984227` returned FIX-FIRST: explicit 8pt body font/colour restoration should follow every repeated table header, and JS UTC inference should also recognise SQLite space-delimited and fractional-second timestamps. Both are fixed. New regression tests inspect all 65 synthetic product spans for 8pt non-bold body fonts across 22 pages and test browser date display near Sydney midnight for the two legacy timestamp shapes. Await final delta review before production deployment.

## PR #194 deployed — qualified QA status

Current source cfb875c (merge 9ae3d5e) passed full CI, security, Beta guardrails and Kimi independent SHIP. Namecheap legacy portal deployment PR194_DEPLOY_SUCCESS: five existing files backed up, SQLite VACUUM snapshot backed up, six staged hashes, remote PHP lint, six live hashes, assets HTTP markers and protected endpoints. Production is the new workbook-style integrated order screen and PDF generator; old approved PDFs unchanged.

The Kimi-managed SUPER session expired before the requested new MX/DT/RT QA orders could be created. No live new order or approval was performed. Existing approved QA snapshots were rendered **read-only** through the new generator to produce verified styled preview PDFs in the workstation Downloads/MERDPOS_Manual_Order_QA_Previews folder (2, 3 and 4 pages); they are previews and not new production approved order files. Restoring authenticated SUPER login in the Kimi-controlled tab is the remaining condition for the user-requested full endpoint (submit/approve new QA orders and verify live PDFs). Do not bypass authorization.

## 2026-10-28 print refinement — no wrapped Category/Bar Code

- At the owner's request, newly approved PDFs **do not display** the QA TEST ORDER — NOT FOR SUPPLIER banner. The order remains explicitly `test_order=true` in the private database and SUPER status view. No supplier transmission is made.
- Category and Bar Code are widened and rendered **single-line only** in portrait A4. Widths (mm): Category **32**, Bar Code **68**, Product Name **56**, Order Quantity **28** (184 mm total). Product names may wrap. Barcode and category sizes are font-metric-checked and shrink within an 8–5 pt range if needed; impossible strings fail rather than wrap or clip.
- Regression `tests/order_pdf_layout.py` checks single-line codes/category and absent QA banner on a synthetic test order. A separate read-only production-snapshot PDF test rendered the three earlier approved MX/DT/RT payloads (34/44/76 lines; barcodes up to 41 chars), confirmed no clipping or wrapping, with 2/2/4 pages respectively.
- **Approved PDF immutability**: the code change does not replace existing approved order files; create fresh QA-marked backend orders and SUPER-approve to generate PDFs using the new template.

Independent Gate 2.5 feedback: removing all test indicators could make a QA PDF resemble a supplier-ready order. The large red QA banner is removed, but a compact `APPROVED TEST COPY - DO NOT FULFIL` is retained within the existing order metadata line, and `TEST COPY` repeats in the footer. The internal `test_order` flag and SUPER status are unchanged. This follows the owner's request to remove the large banner without making test documents misleading.
