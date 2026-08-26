# MERDPOS Beta Authorization Standard

**Status:** Binding for `namecheap-beta-live` and every feature added to the current MERDPOS beta.

## Rule

Every protected portal capability MUST be represented by a named permission in the central permission catalogue and MUST be enforced on the server. Level of Authority (LOA) determines whether a role satisfies that permission's configured minimum authority for the active client.

UI visibility is only a reflection of authorization. A hidden menu, panel, button or widget is never a security control by itself.

## Authority model

1. `client_roles.authority_level` is the role LOA.
2. `client_permission_levels.min_authority_level` is the client-specific minimum LOA for a capability.
3. A delegable permission is granted when `role LOA >= permission minimum LOA`.
4. Permissions marked `dev_only` additionally require an actual DEV authentication identity and are always forced to LOA 1000. Numeric LOA alone can never grant a DEV-only capability.
5. Role labels and compatibility `base_role` values do not grant portal access by themselves.
6. DEV working-client context changes the data tenant only. It never changes the employee/authentication tenant.

## Mandatory enforcement layers

For every portal feature, all applicable layers MUST use the same named permission:

- sidebar/menu visibility;
- server-rendered panel visibility;
- action buttons and forms;
- client-side add/edit controls;
- dashboard widget catalogue and role templates;
- API route authorization;
- action-level authorization inside multi-action endpoints;
- field-level sensitive data visibility, including pay rates;
- row/data scope, including own-vs-all employee data and cross-store access;
- write validation, CSRF and tenant scoping;
- audit logging for security-sensitive or administrative writes.

The backend is authoritative. Requests made directly to an endpoint MUST receive the same authorization decision as requests made through the UI.

## Fail-closed requirements

- Unknown permission keys are denied.
- A protected authenticated portal API that is not registered in the route permission policy is denied.
- Deployment MUST fail if a protected portal API does not refresh authorization with `beta_require_active_user()` or is absent from the route permission policy.
- Dashboard widgets require both a widget-visibility permission and the permission for their underlying data.
- Tightening a permission must remove dashboard widgets that are no longer authorized.
- Sensitive fields must be omitted/redacted from API responses when the viewer lacks the corresponding permission; merely hiding them with CSS is prohibited.

## Client configuration

DEV can configure delegable minimum LOA values from **Operations → Roles → Permission policy**. The editor shows the current roles that would satisfy each threshold before saving.

DEV-only permissions are displayed as locked at 1000 and cannot be delegated.

Changing a role's LOA or a permission threshold takes effect against live database values on subsequent authenticated requests. The authorization layer must not depend on a stale browser/session permission cache.

## Role lifecycle

- USER, ADMIN and SUPER are system roles with configurable LOA below DEV.
- DEV is fixed at LOA 1000.
- Custom roles inherit the ADMIN compatibility base for legacy code only; actual portal access comes from their LOA and the client Permission Policy.
- A new custom role inherits the current Admin dashboard template and the template is filtered through current permissions.
- Deleting a custom role deletes its role dashboard template; deletion is blocked while employees remain assigned.

## Dashboard rule

A dashboard is role-specific. A widget is available only if its selected role satisfies both:

1. the widget's own visibility permission; and
2. every underlying data permission declared for that widget.

DEV may preview/configure dashboards for other roles, but the dashboard data endpoint must scope its response to the selected role's allowed capabilities. Previewing a lower role must never leak higher-LOA data.

## Scope exception: POS/device APIs

The browser-portal LOA model does **not** replace the security contract for native POS/device endpoints under `backend/api`.

Those endpoints continue to require their device token/key, client, store and employee bindings. If a future device action needs role/LOA authorization, it must be added deliberately without weakening the existing device contract.

## Definition of done for every beta feature

A feature is not complete until:

1. capability names and minimum LOA values are defined;
2. DEV-only/non-delegable status is explicitly decided;
3. the API route/action is registered and enforced;
4. tenant and row scope are enforced server-side;
5. sensitive fields are filtered server-side;
6. the UI mirrors the same permissions;
7. dashboard widgets declare underlying data permissions where applicable;
8. writes have CSRF, input validation and audit behavior as appropriate;
9. negative-path tests cover insufficient LOA and cross-tenant attempts;
10. `validate_portal_permission_policy.php` and PHP lint pass before deployment.

## Prohibited patterns

New beta code MUST NOT:

- authorize using `if ($role === 'ADMIN')`, `SUPER`, or similar nominal role checks;
- use `is_super`, `is_admin` or client-side role strings as the authoritative security decision;
- expose data and rely on JavaScript/CSS to hide it;
- add an authenticated portal API without registering it in the central route policy;
- make a DEV-only function delegable by lowering a database threshold;
- bypass client/store/employee tenant predicates because the UI already selected a tenant.

Legacy compatibility role flags may remain temporarily inside old helper functions only when an already-authorized named permission is translated into that compatibility flag at the boundary. They must not be used to decide whether the caller is authorized.
