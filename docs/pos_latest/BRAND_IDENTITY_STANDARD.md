# MERDPOS Brand Identity Standard

**Status:** Binding brand guidance for the MERDPOS beta and future connected touchpoints.

## Brand promise

> **SMARTER • FASTER • TOGETHER**

MERDPOS is positioned as a connected retail operations platform. The intended business outcome is not merely faster checkout: operational and customer channels should work together to reduce friction and improve customer satisfaction.

- **Smarter** — unified data, better operational context and better decisions.
- **Faster** — fewer unnecessary steps, faster staff workflows and faster customer service.
- **Together** — stores, staff, inventory, POS, finance, online channels and customer touchpoints operating as one system.

## Canonical mark

The canonical beta mark is `namecheap_beta_live/timesheet_portal/assets/brand/merdpos-mark.svg`.

The mark uses connected ribbon geometry to form an M. The paths represent retail channels converging into one connected customer experience. Do not add literal carts, barcodes, receipts, terminals, cloud icons or other feature-specific symbols to the mark.

## Canonical colors

```text
brand ink      #111827
brand blue     #2563EB
brand indigo   #4F46E5
brand violet   #7C3AED
brand tagline  #475569
brand on dark  #F8FAFC
```

The approved brand gradient runs blue → indigo → violet.

**Gradient is identity, not state.** Never use the brand gradient as a replacement for semantic success, warning, danger or information colors.

## Logo forms

1. **Primary lockup** — mark + MERDPOS + `Smarter • Faster • Together`; use on login and other low-density brand surfaces.
2. **Compact lockup** — mark + MERDPOS; use in authenticated product shell/header and attendance.
3. **Mark only** — use for favicon, app icon and very small identity surfaces.
4. **Monochrome** — single-color output for thermal receipts and print contexts.

The working dashboard must not repeat the product logo inside cards or data visualizations.

## Wordmark

- `MERD` uses brand navy on light surfaces and white/high-contrast treatment on dark surfaces.
- `POS` uses the approved brand gradient at brand scale.
- Brand/display headings use **Space Grotesk** where available; operational UI/body typography remains **Inter** with the canonical product sans fallbacks. Do not force display typography into dense forms, tables or controls.
- The tagline is a brand promise, not a screen heading. Hide it at compact/header scale.

## Product placement

- **Login:** primary lockup with tagline.
- **Attendance:** compact lockup without tagline.
- **Authenticated dashboard:** compact header identity without tagline.
- **Store/client logos:** remain separate canonical entity identities and are never replaced by the MERDPOS product mark.
- **Role identity:** USER, ADMIN, SUPER and DEV are represented through role/authority UI, not different MERDPOS logos.

## Accessibility

- When visible text already identifies MERDPOS, decorative mark images use `alt=""` and `aria-hidden="true"`.
- When the logo is the only identifying content, the containing lockup exposes an accessible MERDPOS label.
- Do not communicate operational status using the brand gradient alone.
- Preserve the beta design system's focus, touch-target, reduced-motion and contrast contracts.

## Clear-space and scale guidance

Define `X` as the visual width of one main M stem.

- Mark-only clear space: at least 25% of the mark height on all sides.
- Complete lockup clear space: at least 25% of the mark height around the complete identity.
- Small UI mark: 20–24px minimum where legibility is preserved.
- Header mark: 28–44px.
- Primary login mark: approximately 60–80px.
- Remove tagline before reducing the primary lockup below a comfortable reading size.

## Implementation contract

Brand styling is intentionally small and isolated in `assets/brand/brand.css`. It may style only MERDPOS product identity. `design-tokens.css` and `design-system.css` remain authoritative for operational controls, spacing, accessibility and shared component geometry.

Branding must never override input heights, button dimensions, dialog geometry, table density, focus treatment, mobile touch targets or semantic status styling.

The beta runtime may load the dedicated brand stylesheet in addition to the canonical component layers; feature modules must not copy brand CSS into injected `<style>` blocks.

## Prohibited uses

- No recolored per-role logos.
- No glow, bevel, drop-shadow or 3D extrusion added to the canonical mark.
- No continuous animated gradient.
- No stretching or non-uniform scaling.
- No placing the mark into every dashboard card.
- No replacing store/client logos with the MERDPOS mark.
- No using the gradient for save/success/error semantics.

## Omnichannel visual language

The connected-path geometry may inform future customer-journey, channel-handoff and cross-store visualizations, but should be used as a visual grammar rather than repeated copies of the logo. The intended meaning is:

> multiple channels → one connected retail system → one consistent customer experience → better customer satisfaction.

This document is subordinate to `OMNICHANNEL_IDENTITY_STANDARD.md`, `DESIGN_TOKENS.md` and `GUI_STANDARD.md` for operational UX behavior and accessibility.
