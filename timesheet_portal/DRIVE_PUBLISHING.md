# MERDPOS Portal (Alpha) — Google Drive publishing

The approval workflow creates immutable PDF/XLSX locally and commits APPROVED in private SQLite. After that commit, the server attempts Google Drive publication. A Drive error does **not** reverse approval. The SUPER-protected local download remains available. Use the CLI retry to recover.

## Server setup (not repository secrets)

1. Create/choose a Google Cloud service account with Drive API enabled. Store its JSON key **outside the web root**, readable only by the PHP/CLI OS account (0600); do not put the JSON in Git, browser settings, or user-visible PHP.
2. Share a **dedicated target Google Drive folder** with that service account email as Editor. If Google account policies prevent service accounts owning or creating files in My Drive, use an appropriately configured Shared Drive or an approved delegated OAuth implementation. The folder used for unrelated CTR reports is **not** automatically the tobacco-order folder.
3. Set process environment variables `MERD_ALPHA_DRIVE_SERVICE_ACCOUNT_JSON` to that private absolute key path and `MERD_ALPHA_DRIVE_FOLDER_ID` to the exact writable folder ID. Both PHP-FPM/web and CLI must receive these settings. Confirm using a non-production test file before approving real orders.
4. Approved orders upload `ORD-...pdf` and `ORD-...xlsx` with exact existing file bytes. The private `drive_receipts/ORD-....json` files map order IDs to Drive file IDs and hashes. Names and folder are checked on retry to avoid duplicates; conflicting duplicates fail closed.
5. To publish previously approved orders, run `php timesheet_portal/cli/publish_approved_orders.php` on the server. To retry only one, pass `ORD-YYYYMMDD-XXXXXXXXXX`.
6. Verify both Drive files exist with expected size/hash and that the SUPER UI still serves its protected local originals. After authenticated acceptance and independent review, deploy the two PHP integration files plus CLI file using the established guarded Namecheap Alpha deployment procedure.

## Boundaries

- This is server-side authorization only. The existing Inventory DBA CSV feeds, sheets, ceilings, stock counts, payroll and old Apps Script remain unchanged.
- There is **no supplier dispatch** in this path. Backend `test_order=true` records remain test orders even if a PDF's large QA banner has been removed. Do not recast them as production orders.
- No Drive service-account key or writable folder has yet been verified for the Alpha production host. Source commit alone does not mean Drive uploads have occurred.
- Service-account `drive.file` permission covers files created by this integration; where policy requires another OAuth model, review that model before putting credentials on the server.
- Replacing existing approved PDFs, converting QA orders to real orders, or writing the Inventory DBA workbook is outside this feature.
