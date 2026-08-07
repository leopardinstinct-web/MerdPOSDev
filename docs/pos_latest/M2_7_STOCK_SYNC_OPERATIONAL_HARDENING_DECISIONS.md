# M2.7 Stock Sync and Operational Hardening Decisions

Status: implemented on the M2.7 milestone branch; production execution and
deployment are outside this milestone.

## Automatically adopted decisions

- The M2.3 append-only ledger remains authoritative. Each device movement uses
  the tenant/store-scoped idempotency key
  `device:<SHA-256 device UUID>:movement:<local row ID>`.
- Quantity is sent and validated as an exact decimal string with three decimal
  places. The server never uses binary floating point for authoritative stock.
- Every submitted movement receives an `accepted`, `duplicate`, or `rejected`
  outcome. Accepted and duplicate outcomes include the current authoritative
  balance and revision. A conflicting replay is rejected visibly.
- Late movements are applied in server acceptance order while retaining their
  device occurrence time. Multiple devices converge through the maintained
  server revision.
- A completed valid offline sale may take stock below zero. The existing M2.3
  negative-stock exception is opened and returned to the device; the sale and
  movement are not rewritten or rejected solely due to stock level.
- Local projected stock is the latest accepted server balance plus local
  movements still pending or requiring operator attention.
- Response application is atomic locally. Incomplete or malformed
  acknowledgements update neither submitted sales nor movements.
- Permanent per-record rejection is retained locally with its error code and
  remains included in projected stock until explicitly resolved by future
  operator workflow. It is not retried automatically.
- Network and service failures leave outbound records pending. Manual retry is
  conservative and replay-safe through server idempotency.
- Catalogue staleness and retail-sync health are visible on the Home status
  card. Staleness is advisory and never blocks offline checkout solely by age.

## Deliberately withheld

- No catalogue-age checkout block was introduced.
- No automated negative-stock correction, historical sale rewrite, rejected
  movement deletion, deployment planning, or production migration was added.

## Compatibility

The SQLite v4 upgrade is additive. It preserves completed and pending sales,
sale lines, stock movements, catalogue data, M2.6 cursor state, local IDs, and
outbound retry state. Existing authentication and Timesheet behavior are
unchanged.
