# M3 Barcode POS and Durable Sales — Implementation Decisions

Status: approved roadmap; M3.1 is merged and M3.2 is implemented in source. Each later M3
sub-milestone starts only after the preceding pull request is merged.

## Reviewable decomposition

1. M3.1 — durable sale model.
2. M3.2 — HID scanner and basket behavior.
3. M3.3 — exact checkout and single tender.
4. M3.4 — durable sale synchronization and per-sale acknowledgement.
5. M3.5 — order detail, restart hardening, and completion of the
   TapTouch-inspired MerdPOS POS treatment.

M3.2 is the first material TapTouch-inspired POS work-surface implementation;
M3.5 completes order detail and operational hardening without reopening that
direction.

## Automatically adopted M3.1 decisions

- Offline sale, line, and tender identities use UUIDv4 and never derive global
  identity solely from device time or a local sequence.
- The human-readable sale/receipt number remains separate from stable identity.
- SQLite v5 is additive. New snapshot fields are nullable for preserved legacy
  rows; completed and pending history is never rewritten.
- One tender and one durable outbox row are linked one-to-one to each newly
  completed sale. Sale, lines, tender, stock movements, and outbox entry commit
  in one SQLite transaction.
- Monetary and quantity snapshot columns use exact decimal text locally and
  fixed-precision decimal types on the server. Legacy `REAL` fields remain for
  backward compatibility until later readers are migrated.
- Device occurrence time and later server acceptance time are distinct UTC
  fields. Acceptance never replaces occurrence time.
- Backend migration 020 is a source-only, preconditioned additive foundation.
  It is not executed against production and does not cut over ingestion in
  M3.1.
- Card remains a recorded tender type only. No payment-terminal, split-tender,
  partial-payment, printer, cash-drawer, void, or refund behavior is added.

## Explicit decisions withheld from M3.1

- Projected-stock checkout blocking/override policy.
- Manual discount operating limits, stacking, and permissions.
- Physical receipt/printer/cash-drawer requirements.
- Void/refund behavior.
- Payment-terminal integration.

Existing checkout behavior is preserved during M3.1; no new operating policy
is inferred.
