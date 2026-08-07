# M3.3 Checkout and Tender Decisions

Status: implemented in source on `milestone-3-3-checkout-tender`; deployment
and M3.4 sale synchronization remain separate.

## Adopted behavior

- Checkout uses the M2 resolved catalogue price and tax snapshot. Tax is
  extracted from each tax-inclusive extended line and rounded half-up to cents;
  transaction tax is the sum of rounded line tax.
- SQLite v6 additively replaces the M3.1 one-tender uniqueness constraint with
  ordered, separately identified tender components. Existing M3.1 tenders are
  retained as sequence 1.
- Source-only backend migration 021 makes the same additive representation
  available for later M3.4 ingestion while preserving existing tenders. It is
  guarded by explicit schema preconditions and is not executed in production.
- Supported tender components are `cash` and `card_recorded`. Split tender is a
  real ordered set of those components, never a synthetic tender type.
- Card cannot exceed the remaining balance. Non-final cash cannot exceed the
  remaining balance. Final cash may exceed it and records deterministic change.
  Completion requires zero remaining effective balance.
- One SQLite transaction writes sale, exact line snapshots, every tender,
  local stock movements, and the sale outbox row. Any required-write failure
  rolls the aggregate back.
- The client retains one checkout UUID across a local retry. Repeating the same
  aggregate returns the existing result; conflicting reuse of that identity is
  rejected without adding tenders or stock effects.
- Checkout failures preserve the active basket and tender plan. The basket is
  cleared only after durable local success and outcome acknowledgement.
- A locally completed sale remains completed and pending if the network is
  unavailable. M3.4 owns server ingestion and acknowledgement hardening.
- Projected-stock warnings remain advisory. Valid offline checkout is not
  blocked solely because projected stock is insufficient.

## Withheld scope

- Manual discount operating policy and permissions.
- Projected-stock checkout blocking or manager override.
- Void/refund.
- Payment-terminal authorization or external card calls.
- Printer and cash-drawer hardware.
- Backend sale ingestion and durable acknowledgement (M3.4).
