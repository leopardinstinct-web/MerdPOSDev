# MERDPOS Brand Identity Standard

**Status:** Binding brand guidance for the MERDPOS beta and future connected touchpoints.

## Brand promise

> **SMARTER • FASTER • TOGETHER**

MERDPOS is positioned as a connected retail operations platform. The intended business outcome is not merely faster checkout: operational and customer channels should work together to reduce friction and improve customer satisfaction.

- **Smarter** — unified data, better operational context and better decisions.
- **Faster** — fewer unnecessary steps, faster staff workflows and faster customer service.
- **Together** — stores, staff, inventory, POS, finance, online channels and customer touchpoints operating as one system.

## Canonical asset library

MERDPOS has one complete signature plus three individually addressable identity elements. All are exact supplied artwork; none may be reconstructed from CSS text, paths, or substitute geometry.

- **Primary Signature / Full Lockup** — `namecheap_beta_live/timesheet_portal/assets/brand/merdpos-logo-approved.png`. This is the canonical complete logo. Whenever the complete identity is needed, use this file as one image; do not rebuild it from the three elements.
- **Brand Mark / M Symbol** — `namecheap_beta_live/timesheet_portal/assets/brand/merdpos-mark.png`. This is the standalone recognition symbol for app icons, favicons, mobile/compact identity and places where the product name is already clear.
- **MERDPOS Wordmark** — `namecheap_beta_live/timesheet_portal/assets/brand/merdpos-wordmark.png`. Use where the product name needs strong horizontal readability but the full signature would be visually too dense.
- **Brand Promise / Tagline** — `namecheap_beta_live/timesheet_portal/assets/brand/merdpos-tagline.png`. This is supporting identity, not a standalone product identifier; use it on spacious brand surfaces and communications where the promise adds value.

The visual hierarchy is intentional: the M delivers rapid recognition, the wordmark supplies product-name certainty, and the tagline adds the brand promise. On dense product UI, reduce brand density by choosing the appropriate individual element rather than shrinking the complete lockup until it becomes illegible.

`assets/brand/brand-assets.js` is the runtime registry for these canonical paths so feature code never guesses asset filenames.

## Canonical colors

The MERDPOS **brand master palette is exactly five colors**:

```text
White             #FFFFFF
App Background    #F5F7FC
Brand Navy        #031B4B
Brand Cyan        #12BDF3
Violet            #8B2EFF
```

Brand-facing CSS must use these master colors through `design-tokens.css`, or derive hover, dark, border, muted and overlay treatments from them with semantic token aliases / `color-mix()`. Do not introduce additional blue, indigo, purple, slate or decorative brand literals.

The product identity gradient is **Brand Cyan ? Violet**. Gradient is identity, not state; success, warning, danger and information retain dedicated semantic status colors because they communicate operational meaning rather than brand identity.

The approved raster logo artwork remains immutable and may contain baked-in intermediate gradient colors from the supplied master artwork. That artwork exception does not make those intermediate raster colors part of the UI master palette.

## Logo forms

1. **Complete signature** — use the supplied full-lockup file unchanged when all brand elements should appear together.
2. **Mark only** — use the supplied M element for favicon, app icon, compact/mobile identity and other small recognition surfaces.
3. **Wordmark only** — use the supplied MERDPOS element when horizontal name recognition is needed without the visual weight of the M.
4. **Tagline support** — use the supplied SMARTER • FASTER • TOGETHER element only as supporting identity, never as the sole product identifier.
5. **Monochrome/context variant** — color treatment may adapt for dark/light/print contexts, but geometry, proportions and internal relationships do not change.

Do not recreate a complete logo by composing the mark, wordmark and tagline in HTML/CSS when the supplied complete signature is available. The working dashboard must not repeat the product logo inside cards or data visualizations.

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

## Approved artwork immutability

The approved Concept 7 artwork is immutable. Product surfaces must use the exact approved raster artwork or direct crops of that same file (for example, the M mark). Do not redraw, simplify, reinterpret, trace, reshape, or substitute the geometry. Context variants may change colour treatment only where required for dark/light/monochrome use.

Canonical beta assets:
- `namecheap_beta_live/timesheet_portal/assets/brand/merdpos-logo-approved.png` — exact supplied complete signature.
- `namecheap_beta_live/timesheet_portal/assets/brand/merdpos-mark.png` — exact supplied M mark.
- `namecheap_beta_live/timesheet_portal/assets/brand/merdpos-wordmark.png` — exact supplied MERDPOS wordmark.
- `namecheap_beta_live/timesheet_portal/assets/brand/merdpos-tagline.png` — exact supplied SMARTER • FASTER • TOGETHER tagline.
- `namecheap_beta_live/timesheet_portal/assets/brand/brand-assets.js` — canonical runtime path registry.
