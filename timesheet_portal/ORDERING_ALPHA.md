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
