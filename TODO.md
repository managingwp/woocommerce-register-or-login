# TODO

## Completed
- ✅ Logged-in users bypass gateway and go directly to checkout.
- ✅ Guest cart "Proceed to checkout" is intercepted and routed through a gateway flow.
- ✅ Gateway page asks for email and supports login/register branching.
- ✅ New-account registration path logs user in and redirects to checkout.

## Phase 1 — Convert to Standard Plugin (from MU Plugin)
- [x] Create installable plugin bootstrap (regular plugin, not mu-plugin only).
- [x] Move current logic into plugin classes/files for maintainability.
- [x] Add activation/deactivation hooks.
- [x] Preserve existing gateway content-override rendering behavior.
- [x] Update README install steps for normal plugin activation.

## Phase 2 — Privacy-First Identity Flow
- [ ] Remove explicit account-detection endpoint/UI messaging.
- [ ] Use generic responses that do not reveal whether an email exists.
- [ ] Keep secure nonce validation and form hardening.
- [ ] Ensure checkout redirection/cart continuity behavior remains intact.

## Phase 3 — Magic Link Login for Existing Accounts
- [ ] Replace existing-account password flow with email magic-link flow.
- [ ] Generate one-time, expiring login links (TTL default: 15 minutes).
- [ ] Enforce one-time token consumption and replay protection.
- [ ] Add throttling for link requests (default: 3 sends / 15 minutes).
- [ ] Redirect to checkout after successful magic-link login.

## Phase 4 — Cross-Device Cart Recovery
- [ ] Snapshot cart when issuing magic link.
- [ ] Restore/merge cart if magic link is opened in another browser/device.
- [ ] Validate restore behavior for same-device and cross-device flows.

## Phase 5 — WooCommerce Settings
- [ ] Add plugin enable/disable toggle in WooCommerce settings.
- [ ] Add configurable magic-link TTL setting.
- [ ] Add configurable rate-limit setting (count + window).
- [ ] Add gateway page selection/management setting.
- [ ] Add basic operational logging toggle (non-PII).

## Deferred
- [ ] Cloudflare Turnstile integration (intentionally postponed).
