# MerdPOS Design System — "Blue Ice" (superseded)

> Historical reference only. The product owner superseded Blue Ice on
> 2026-08-06 with the original TapTouch-inspired MerdPOS direction in
> `merdpos-design-brief-taptouch-inspired.md` and `DESIGN_TOKENS.md`.
> Existing implemented screens are not automatically restyled.

Master design directive for the MerdPOS app (Flutter), admin web, and future
marketing. Based on the approved A.1 "Blue Ice" logo concept. Machine-usable
values live in DESIGN_TOKENS.md; this file explains intent. When they ever
disagree, DESIGN_TOKENS.md wins.

1. Concept
Precision, security, cool intelligence. The icon is an isometric hexagonal
prism with internal facets forming an "M", with subtle blue light-leaks that
give the "Blue Ice" glow. Descriptors: cool, high-tech, crisp, crystalline.

Tenets:

Depth — subtle glow, light leaks, faceted geometry.

Cool only — stay in the cool blue range. No golds, oranges, or warm reds.
Even errors use a cool red/magenta, not a warm red.

Future-ready — advanced, AI-driven, uncluttered. Negative space and clean
lines over boxy, busy UI.

2. Logo
Horizontal lockup on a dark background: faceted prism icon left, wordmark right.

"Merd" — bold, clean sans-serif, pure white (#FFFFFF).

"POS" — lighter weight, cool light blue, matching the prism glow.

Tagline "FUTURE-READY RETAIL" — below the wordmark, same cool blue,
smaller and lighter, all-caps.

Use only the A.1 variant. Do not mix with B, C, or D.

3. Color palette
Correction from the original brief: the old "Accent Glow Blue #A3BE8C" is a
green swatch and was mislabeled. Since the system is cool-only, the accent
has been replaced with a true cool blue. The palette below is canonical.

Role	HEX	Use
Primary Background	#080C14	Deep charcoal/navy app background
Surface / Card	#0E1626	Raised panels and cards on the background
Border / Outline	#1C2A40	Card outlines, dividers, table grid lines
Primary Text (White)	#FFFFFF	"Merd", headings, primary body text
Secondary Text	#9FB3C8	Labels, captions, muted text
Cool Blue (Brand)	#88C0D0	"POS" text, tagline, primary accents
Accent Glow Blue	#5FB6E6	Interactive elements, data highlights, glow
System / Data Slate	#434C5E	Subtle background visuals (neural net, grid)
Success	#5FD0C5	Cool teal-green confirmations (used sparingly)
Warning	#E0C56B	Reserved; use rarely — borders the cool rule
Error	#E06C9F	Cool red/magenta — never a warm red
Contrast: white and #9FB3C8 on #080C14 are fine. #88C0D0 is for accents
and large text, not small body copy. Verify any new text/background pair meets
WCAG AA (4.5:1) before shipping.

4. UI guidelines
Dark mode first. Replicate the depth and glow of the A.1 panel.

Cards. Deep #0E1626 panels on #080C14, sharp #1C2A40 outlines, with
an optional cool-blue glow on focus/hover.

Buttons. Solid white or light-blue for primary actions; glowing
#5FB6E6 hover/active state. Faceted treatment for critical actions.

Charts/graphs. Thin, crisp lines in #88C0D0 and #5FB6E6 as the primary
data colors. No heavy fills.

Neural overlays. Faint connected-node/line overlays in #434C5E on
background surfaces, low opacity, to imply AI processing. Never compete with
content.

5. Typography
Clean geometric sans-serif (Montserrat or Metropolis), weight hierarchy as in
the "MerdPOS" wordmark.

Highly readable, precise, tech-forward.

Type scale, sizes, and weights are defined in DESIGN_TOKENS.md.

6. Iconography
Geometric, clean-line, minimalist or slightly faceted, with subtle glowing blue
accents. Wireframe over filled. Avoid rounded, bubbly icons.

7. How to apply this (for the LLM)
Never invent colors, spacing, radius, or fonts — pull from DESIGN_TOKENS.md.

Style every Flutter screen through the shared theme.dart, not per-widget
hardcoded colors.

New UI must read as part of one system: dark, cool, crisp, glowing accents.

When porting from competitor screenshots, swap standard green for
#5FD0C5 and standard red for #E06C9F.
