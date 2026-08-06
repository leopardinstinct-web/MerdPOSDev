# MerdPOS design tokens — TapTouch-inspired original direction

Status: approved future direction. Supersedes Blue Ice for new reviewable UI
work. Existing Flutter screens are not restyled by backend-only milestones.

These tokens are an original MerdPOS system influenced by the product-owner-
supplied TapTouch admin benchmark. OpenClaw did not receive or inspect the
original MP4. No TapTouch branding, logo, proprietary icon, illustration, or
asset may be copied. Light content surfaces with dark navigation are the first
implementation target; dark content mode is deferred.

## Color tokens

```text
--primary                 #2563EB   primary actions and active controls
--primary-hover           #1D4ED8
--secondary               #0F766E   secondary operational actions
--accent                  #F59E0B   attention and selected highlights
--app-background          #F4F7FB   light content background
--surface                 #FFFFFF   cards, dialogs, forms
--surface-subtle          #F8FAFC   grouped rows and secondary panels
--navigation-background   #111827   persistent dark navigation
--navigation-selected     #1E40AF   selected navigation item
--navigation-text         #E5E7EB
--text-primary            #111827
--text-secondary          #475569
--border                  #CBD5E1
--divider                 #E2E8F0
--success                 #15803D
--warning                 #B45309
--danger                  #B91C1C
--information             #0369A1
--disabled-background     #E2E8F0
--disabled-text           #64748B
--focus-ring              #60A5FA
--table-header            #E8EEF8
--table-row-alternate     #F8FAFC
--input-background        #FFFFFF
--dialog-scrim            rgba(15,23,42,0.55)
--chart-1                 #2563EB
--chart-2                 #0F766E
--chart-3                 #F59E0B
--chart-4                 #7C3AED
--chart-5                 #DC2626
--pos-primary-action      #1D4ED8
--pos-confirm-action      #15803D
--pos-destructive-action  #B91C1C
```

All text/background combinations must meet WCAG 2.2 AA contrast. Color is
never the only status signal. Hover applies only where a pointer exists; focus
must remain clearly visible for keyboard/scanner workflows.

## Type, spacing, shape, and interaction

```text
font-family  Inter, Roboto, system sans-serif
display      28 / 700
heading      22 / 650
title        18 / 600
body         15 / 400
label        13 / 600
caption      12 / 400

spacing      4, 8, 12, 16, 24, 32, 48
radius       6, 10, 14, 999
touch-min    48px Android operational controls
desktop-min  40px administrative controls
focus-ring   3px #60A5FA with 2px offset
card-shadow  0 1px 3px rgba(15,23,42,0.12)
dialog-shadow 0 16px 40px rgba(15,23,42,0.24)
```

## Component direction

- Navigation: dark rail/drawer, light labels, icon plus text, unmistakable
  selected state, and store/date context outside destructive actions.
- Tables: sticky high-contrast headers where useful, restrained alternating or
  grouped rows, sortable labels, visible filters, pagination state, and no
  color-only statuses.
- Inputs: persistent labels, inline validation, clear required/optional state,
  48px touch targets on Android, and scanner-friendly focus behavior.
- Buttons: one primary action per region; secondary and destructive actions
  remain visually distinct. Confirmation states must not replace audit trails.
- Chips/badges: combine text and color for lifecycle, sync, inventory, and
  exception states.
- Dialogs: explicit title, consequence, cancel, and confirm actions. Never hide
  irreversible consequences behind a generic confirmation.
- Charts: use the chart series above with labels/tooltips and accessible tabular
  equivalents.
- POS action zones: large stable targets, primary sale actions separated from
  destructive/void actions, minimal decoration, and fast visual scanning.

Flutter implementation requires a later dedicated visual proposal and review.
Do not paste these colors into widgets; map approved tokens through the future
theme layer when that scope begins.
