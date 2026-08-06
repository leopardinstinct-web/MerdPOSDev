# MerdPOS design brief — TapTouch-inspired original system

## Intent and evidence boundary

TapTouch is the preferred functional and visual benchmark for future MerdPOS
admin, catalogue, pricing, inventory, and POS design. OpenClaw did not receive
or inspect the original MP4. The benchmark observations are supplied by the
product owner and are recorded in `TAPTOUCH_UX_BENCHMARK.md`. Screenshots may
later refine or verify those findings; no additional observed behavior may be
claimed without evidence.

The design must be recognizably MerdPOS: original palette, typography,
components, icons, content, and interaction details. TapTouch branding and
proprietary assets must never be copied.

## Experience principles

1. Operational speed before decoration.
2. Light, readable work surfaces with persistent dark navigation.
3. Clear store, date, staff, device, and synchronization context.
4. Search, filters, sorting, pagination, status, and action placement that
   scale to multi-store retail data.
5. Large touch targets and scanner/keyboard accessibility.
6. Inline validation, explicit outcomes, and durable audit/history visibility.
7. Offline-first states that distinguish accepted server data, pending local
   work, errors, and operational exceptions.
8. Tenant/store/product identity remains visible wherever ambiguity is risky.

## MerdPOS adaptation boundaries

- Approved canonical product identity, price/tax history, and server stock
  ledger contracts govern UI behavior; benchmark patterns cannot override
  them.
- Completed offline sales remain valid and negative stock is an exception, not
  a reason to rewrite a sale.
- Role-aware actions may be designed, but M2.3 does not invent authorization.
- Backend-only milestones do not restyle implemented Flutter screens.
- Timesheet and payroll surfaces remain protected and outside this direction.
- Light content mode is first. A later dark content mode must preserve the same
  semantic tokens and contrast.

## Future visual proposal

Before Flutter redesign, prepare reviewable treatments for login, home,
navigation, products, categories, product form, pricing, promotions, tax,
inventory, adjustment, receiving, stock history, negative-stock exceptions,
transfers, reconciliation, checkout, tables, and filters. Each screen must cite
which supplied benchmark finding it adopts or adapts.
