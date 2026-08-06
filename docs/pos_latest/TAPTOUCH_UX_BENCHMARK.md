# TapTouch UX benchmark — supplied findings and MerdPOS disposition

## Evidence statement

OpenClaw did not receive or inspect the original TapTouch MP4. Every observation
below was supplied by the product owner. This document does not assert any
additional TapTouch behavior. Later screenshots may refine or verify, but not
silently expand, the evidence.

Classification: **Adopt** means use the general pattern; **Adapt** means reshape
it for MerdPOS contracts/offline operation; **Defer** means retain for a later
approved milestone; **Do not adopt** means it is unsuitable or out of scope.

## Supplied functional findings

| Supplied finding | Classification | MerdPOS direction |
|---|---|---|
| Branded login | Adapt | Original MerdPOS identity and accessible login treatment in a dedicated UI scope. |
| Dark left navigation | Adopt | Original dark navigation with light operational surfaces and clear selected state. |
| Store/date selectors | Adapt | Show tenant-safe store scope and authoritative date/time semantics; do not imply device time is authoritative. |
| Staff/language controls | Adapt | Preserve role and locale context; authorization changes require separate approval. |
| Multi-store administration | Adopt | Keep store scope visible and prevent cross-tenant/store actions. |
| Dashboard, Reports, Items, Customers, Staff, Promotion, Settings, Help | Adapt | Align labels and availability to approved MerdPOS modules and roles. |
| Advertising module | Do not adopt | Not in the approved operational roadmap; reconsider only through explicit scope approval. |
| Offline/online sales, orders, averages, refunds, payment summary, time charts | Adapt | Distinguish authoritative, pending, late, and exception states; preserve payment/accounting contracts. |
| Reports by product/category/department/add-on/payment method | Defer | Product/category/payment reporting is useful; departments/add-ons require approved domain models first. |
| Products/categories/SKU/barcode/tax/store availability | Adopt | Apply approved M2 canonical identity, alias, tax, lifecycle, and store-overlay contracts. |
| Brands/tags/add-ons | Defer | Do not silently extend the approved flat catalogue model. |
| Pricing and channel-specific prices | Adapt | Use approved store/client regular/promotion precedence; channel prices require a later product decision. |
| Product search, filters, export, pagination | Adopt | Provide scalable, accessible list controls with tenant/store context. |
| Customer/member cards, filters, activity | Defer | Useful benchmark for a later customer milestone; privacy and role review required. |
| Percentage/fixed promotions, codes, rules | Adapt | Preserve tax-inclusive effective pricing and auditable promotion contracts; implementation is later scope. |
| Tax, stores, payment methods, third-party services, time settings | Adapt | Separate by risk/role; integrations and security-sensitive settings require their own scope. |
| Uber Eats name/URL modal | Defer | Evidence of an integration form pattern only; no Uber Eats integration is approved. |
| Device serial, IP, app version, staff, last sync | Adapt | Use privacy-conscious device identity and authoritative sync state; no auth/security changes here. |

## Product import requirements derived by the product owner

All import capabilities are **Adapt** for a later catalogue-administration
milestone: CSV upload, configurable mapping, validation preview, duplicate and
barcode-conflict detection, product/category create/update, tax/price/cost/
supplier/store mapping, dry-run, import summary, downloadable error CSV,
batch tracking or rollback, audit history, and reusable templates.

MerdPOS adaptation requires immutable canonical IDs, client-scoped uniqueness,
transactional batches, explicit create/update semantics, reversible batch
tracking or compensating correction, role review, and no silent overwrite.

## M2.3 stock UI implications

These are contract implications, not claims about TapTouch and not M2.3 UI
implementation:

- Inventory: last accepted server balance, local pending projection, revision,
  store/product identity, and exception state.
- Adjustment: signed direction, reason, note, actor, source/idempotency identity,
  previewed effect, and posted confirmation.
- Receiving: purchase/source reference, duplicate-safe result, received event
  time, accepted server time, and partial/late state.
- History: immutable ordered movements, before/after balance, source, device,
  actor, reversal chain, and filters.
- Negative stock: first/latest detection, lowest balance, latest balance,
  recovery state, acknowledgement, and resolution note.
- Transfer: one identity, two stores, linked out/in legs, lifecycle, and missing-
  leg visibility.
- Reconciliation: legacy snapshot, ledger snapshot, difference, provenance,
  review decision, and generated movement link.

Actual Flutter design and implementation require a separate reviewable scope.
