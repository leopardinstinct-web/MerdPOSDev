# M2.4 full catalogue snapshot schema

Status: implemented contract `m2.catalogue.full.v1`.

## Request

`POST /api/sync_catalogue.php` with `Content-Type: application/json` and the
existing approved bearer device token transport. Legacy token transport remains
only through the existing centralized compatibility helper.

```json
{
  "contract_version": "m2.catalogue.full.v1",
  "snapshot_type": "full",
  "client_id": 1,
  "store_id": 11,
  "device_uuid": "device-uuid"
}
```

Client/store/device fields are lookup inputs required by the existing device
authorization contract, not caller-selected authority. The endpoint generates
the snapshot from the client/store values on the matched active server-side
device row. A wrong combination or invalid, inactive, expired, or revoked device
receives the existing generic `device_unauthorized` response.

## Success envelope

```text
success: true
api: sync_catalogue.php
contract_version: m2.catalogue.full.v1
snapshot_type: full
snapshot_revision: sha256:<64 lowercase hexadecimal characters>
cursor_seed: m2f1_<opaque base64url content hash>
server_time_utc: RFC 3339 UTC with six fractional digits
snapshot_generated_at_utc: RFC 3339 UTC with six fractional digits
snapshot:
  context: client, store, authorized device UUID
  currency: ISO code, money scale 4, tax_inclusive true
  categories: stable ID order
  tax_codes: stable ID order, including lifecycle status
  effective_tax_rates: current published UTC-effective records
  product_tax_assignments: current published UTC-effective records
  products: stable ID order
  warnings: machine-readable product configuration findings
```

Each product contains stable category/product IDs, trimmed display SKU and
stored normalized SKU, name/description, approved unit, derived lifecycle,
archive/tombstone times, exact-text barcode aliases, store availability and
reorder level, all currently effective store/client regular/promotion price
candidates, resolved price, resolved tax, authoritative M2.3 stock balance and
revision, optional negative-stock exception, and sellability/reason codes.

## Resolution rules

- One server UTC instant is captured for the whole response.
- Effective intervals are half-open: start inclusive, end exclusive.
- Only `published` price/rate/assignment records effective at that instant are
  returned. Draft, cancelled, expired, and future records are excluded.
- Price order is store promotion, client promotion, store regular, client
  regular. The first record is `resolved_price`.
- Tax requires an effective published assignment, an active tax code, and an
  effective published rate. `NO_TAX` is valid only when explicitly assigned.
- Missing price or tax never becomes implicit zero/`NO_TAX`. The product remains
  present with `sellable: false` and reason codes.
- Archived, disabled, and tombstoned products remain present for last-good and
  historical compatibility and are explicitly not sellable.
- Store availability is currently represented by an existing tenant-safe
  `retail_store_inventory` row. No row is `store_unavailable`.
- Missing authoritative balance means M2.3 ledger balance zero/revision zero;
  legacy inventory quantity is never substituted.
- Negative stock and exception metadata remain visible but do not by themselves
  make an otherwise configured active product unsellable.

## Serialization and compatibility

Money is a four-place decimal string; quantities are three-place decimal
strings; barcodes remain exact text; IDs and basis points are JSON integers;
timestamps are UTC strings or null. Arrays have deterministic database-ID/
contract-precedence ordering.

The snapshot revision is a hash of store-scoped catalogue content excluding
generation timestamps and device UUID, so identical store content produces the
same revision across retries/devices. `cursor_seed` is opaque compatibility
metadata only. M2.4 does not implement incremental paging or cursor semantics.
Devices must validate and commit a complete snapshot transactionally in a later
milestone before replacing last-good data; this endpoint does not update device
`last_sync` because a download is not proof of device commit.

The endpoint is read-only. It does not change existing APIs, outbound retail
sync, checkout, Flutter, SQLite, catalogue persistence, or runtime stock writes.
