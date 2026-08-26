# MERDPOS Omnichannel Identity Standard

**Status:** Binding experience/design guidance for the MERDPOS beta and future connected touchpoints.

## Evidence basis

The project uses the three empirically validated omnichannel identity dimensions described by Jin, Suh and Lee (2025) as a practical review framework:

1. **Reliability** — consistency, safety, accuracy, stability, professionalism and honesty.
2. **Usability** — convenience, ease, rationality, functionality and usefulness.
3. **Trendiness** — a contemporary, refreshing, sophisticated, active and lively presentation.

The study also shows that the relative importance of these dimensions differs by retailer/context. MERDPOS is an operational retail platform, so its default priority is deliberately:

> **Reliability → Usability → restrained Trendiness**

MERDPOS must not trade operational clarity, trust or speed for decorative novelty.

## 1. Reliability rules

Every connected MERDPOS touchpoint must make the user's current context and system state unambiguous.

- Client identity and current working-client context must be consistent across Dashboard, Operations, Reports, Finance and account controls.
- Store name, Store ID, Store Code and uploaded store logo must refer to the same database record everywhere they appear.
- Store/client arrays are ordered by numeric database ID unless the specific workflow requires another explicit order.
- Live/dynamic screens should expose a restrained data-freshness cue where it materially improves trust.
- Errors must be explicit and actionable; empty HTTP/API responses are not acceptable user-facing states.
- Loading, empty, success, stale and error states must be visually and linguistically distinct.
- Permission/LOA, tenant and store scope must be enforced server-side; visual hiding is never treated as reliability/security.
- Currency, timezone and business-date context must come from client/store defaults rather than browser assumptions.
- Destructive or financially consequential actions require clear consequence language and auditability.
- Cross-client DEV work must never mutate the DEV user's immutable authentication/home tenant.

## 2. Usability rules

MERDPOS should make the most common correct action obvious and reduce unnecessary choice.

- One-child navigation groups flatten to the actual destination.
- A multi-item navigation parent opens its menu; selecting a child changes the panel without closing the menu. Clicking outside closes it.
- Avoid duplicate selectors for the same global state. The global working-client selector lives in the signed-in account menu only.
- Prefer progressive disclosure: advanced/DEV controls appear only when relevant and authorised.
- One clear primary action per task region; secondary and destructive actions remain visually distinct.
- Forms use persistent labels, inline guidance and specific validation messages.
- Operational controls target at least 40 px on desktop and 48 px where touch/scanner use is expected.
- Lists/tables support scanning before decoration: stable columns, numeric ID ordering, concise status text and searchable directories where useful.
- Dashboard editing should feel direct: add, drag, snap, resize and remove without a separate configuration workflow.
- Modal opening/closing behaviour must be consistent, and background scroll is locked while a modal is open.
- Keyboard focus is always visible; reduced-motion preferences are respected.

## 3. Trendiness rules

Trendiness supports perceived quality but remains subordinate to reliability and usability.

- Use the MERDPOS dark navigation rail + light workspace identity consistently.
- Use modern system typography, restrained depth, consistent radii and spacing.
- Motion explains state change; it is not decoration.
- Prefer SVG/system icons and genuine store/client brand assets over novelty graphics.
- Avoid excessive gradients, glow, glass effects, hover movement or visual noise on operational screens.
- Login/attendance should be calmer and simpler than the management dashboard because channel-specific adaptation is allowed while core identity remains consistent.

## 4. Cross-touchpoint identity contract

The following identity elements are canonical and must come from shared backend data wherever practical:

- MERDPOS product name/mark;
- authenticated employee identity;
- effective role + LOA;
- active working client name/code;
- store name/ID/code/logo;
- currency/timezone/business date;
- live/freshness state for dynamic operational data.

Do not create screen-local aliases or duplicate editable copies of these values.

## 5. Feature definition of done

Every new or changed beta feature must be reviewed against all three dimensions:

### Reliability
- Is the tenant/store/user context explicit and correct?
- Are endpoint, database, table, column, FK/index and audit paths verified end to end?
- Are stale, loading, error and success states handled?
- Does server authorization match visible UI capability?

### Usability
- Is the main task obvious?
- Is any control or submenu redundant?
- Can the workflow be completed with fewer context switches or clicks without losing safety?
- Are keyboard, touch and responsive states usable?

### Trendiness
- Does it look current and coherent with MERDPOS without distracting from the task?
- Are spacing, typography, iconography, surfaces and motion consistent with the shared design system?

A feature is not complete if it looks modern but is ambiguous, inconsistent, insecure, or harder to use.

## 6. Context-specific weighting

The three dimensions are not a scoring game and should not be forced to equal weight.

- **POS / attendance:** reliability very high, usability very high, trendiness low-to-moderate.
- **Workforce / Stores / Finance:** reliability very high, usability high, trendiness moderate.
- **Management Dashboard:** reliability high, usability high, trendiness moderate-to-high where visualisation improves comprehension.
- **DEV/System:** reliability highest, usability high, trendiness low.

This weighting is intentionally context dependent, reflecting the study's finding that different omnichannel identity components can exert different effects in different retail contexts.
