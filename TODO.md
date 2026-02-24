# Current

## Changes
* Automatic GitHub Updates Implement https://github.com/SilverAssist/wp-github-updater
* The plugin should be compatiable with https://github.com/ElliotSowersby/simple-cloudflare-turnstile
* Update Endpoint Currently wc-register-or-login-gateway but should be generic such as register-or-login
* Email login links for existing accounts, then redirect with cart intact.
* WooCommerce settings toggle(s) and optional Cloudflare Turnstile support.


# Plugin Rewrite

## Completed
- ✅ Logged-in users bypass gateway and go directly to checkout.
- ✅ Guest cart "Proceed to checkout" is intercepted and routed through a gateway flow.
- ✅ Gateway page asks for email and supports login/register branching.
- ✅ New-account registration path logs user in and redirects to checkout.
- ✅ Privacy-first identity flow removes explicit account detection and uses generic responses.
- ✅ Removed `detect-user` account-detection REST endpoint and client-side probing behavior.
- ✅ Replaced auto-detection UI with explicit user intent selection (sign in vs create account).
- ✅ Updated sign-in and registration failure notices to avoid account-enumeration leakage.
- ✅ Kept parity between standard plugin and MU plugin implementations.
- ✅ Updated README to reflect completed Phase 2 behavior.
- ✅ Existing-account flow now uses one-time email magic links (15-minute TTL, one-time consumption, throttled sends).

## Phase 1 — Convert to Standard Plugin (from MU Plugin)
- [x] Create installable plugin bootstrap (regular plugin, not mu-plugin only).
- [x] Move current logic into plugin classes/files for maintainability.
- [x] Add activation/deactivation hooks.
- [x] Preserve existing gateway content-override rendering behavior.
- [x] Update README install steps for normal plugin activation.

## Phase 2 — Privacy-First Identity Flow
- [x] Remove explicit account-detection endpoint/UI messaging.
- [x] Use generic responses that do not reveal whether an email exists.
- [x] Keep secure nonce validation and form hardening.
- [x] Ensure checkout redirection/cart continuity behavior remains intact.

## Phase 3 — Magic Link Login for Existing Accounts
- [x] Replace existing-account password flow with email magic-link flow.
- [x] Generate one-time, expiring login links (TTL default: 15 minutes).
- [x] Enforce one-time token consumption and replay protection.
- [x] Add throttling for link requests (default: 3 sends / 15 minutes).
- [x] Redirect to checkout after successful magic-link login.

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
