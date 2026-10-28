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
