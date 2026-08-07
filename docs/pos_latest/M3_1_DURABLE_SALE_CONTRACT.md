# M3.1 Durable Sale and Receipt Data Contract

Status: additive source foundation. Runtime exact checkout and durable sale
synchronization are M3.3 and M3.4 respectively. Production migration execution
is not authorized.

## Identity and time

- `sale_uid`, `line_uid`, and `tender_uid` are UUIDv4 values created once on
  the offline device and retained across restart, retry, and acknowledgement.
- `sale_number` is a separate human-readable receipt reference and is not the
  global idempotency identity.
- Device occurrence time is UTC and immutable. Server acceptance time is a
  distinct nullable UTC field populated only after durable ingestion.

## Atomic local aggregate

One SQLite transaction writes the sale, every line, exactly one tender, each
required local stock movement, and one durable outbox record. Failure of any
write rolls back the entire new sale aggregate. Existing completed sales are
never deleted or rewritten for recovery.

## Sale snapshot

The durable model reserves exact values for client, store, device, cashier,
currency, subtotal, automatic/manual discounts, tax, grand total, occurrence
time, acceptance time, synchronization outcome, and receipt contract version.

Each line reserves canonical product ID, exact barcode used, SKU and name
snapshots, UOM, quantity, original/resolved price, price source/version,
promotion/campaign context, manual discount/reason/actor, tax code/rate/version,
tax-inclusive flag, taxable/net/tax/gross amounts, currency, and existing cost
snapshot.

Legacy binary-floating columns remain for backward compatibility. New exact
local snapshot values are decimal text; server values use fixed-precision
decimals. M3.3 becomes the authoritative calculation writer using the approved
M2 per-line tax-inclusive half-up rules.

## Tender

- Exactly one tender is reserved per new completed sale.
- Core values are `cash` and `card_recorded`.
- Amount due, amount tendered, and change due are exact two-decimal values.
- Card-recorded means record-only and requires amount tendered equal to amount
  due with zero change.
- Split tender, partial payment, payment terminals, printer protocols, and cash
  drawers are excluded.

## Receipt software abstraction

`m3.receipt.v1` can represent business/store identity, receipt number,
occurrence time, cashier, sale UID, products, quantities, UOM, original and
final price, promotion/discount, tax, subtotal, tax total, grand total, tender,
amount tendered, and change due. M3.1 defines data capacity only; no physical
printer or hardware behavior is selected.

## Backend boundary

Migration 020 adds nullable durable identities and exact snapshot fields for
preserved history plus a tenant/store-scoped tender table. M3.1 does not cut
over `sync_retail.php`; per-sale ingestion and acknowledgement belong to M3.4.
