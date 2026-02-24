
# WooCommerce Register or Login Gateway

## Overview

This proof-of-concept plugin inserts a friendly checkpoint between the WooCommerce cart and checkout for guests. When guest shoppers click **Proceed to checkout**, they are guided to a custom page that captures their email and lets them choose to sign in or create an account before forwarding them to the standard checkout. Logged-in users bypass this step and go directly to checkout.

## Requirements

- WordPress 6.0 or later
- WooCommerce 7.0 or later
- PHP 7.4+

## Quick start

1. Copy this plugin folder into your WordPress install at `wp-content/plugins/woocommerce-register-or-login`.
2. Activate **WooCommerce Register or Login Gateway** from **Plugins → Installed Plugins**.
3. Load your site once; the plugin auto-creates a published page named **Confirm your checkout details** (slug: `wc-register-or-login-gateway`).
4. Visit the WooCommerce cart. Guest users are sent to the gateway page, while logged-in users go directly to WooCommerce checkout.
5. Submit the form or use the “Skip and go straight to checkout” link to proceed.

## Current capabilities

- Standard plugin scaffolding that only boots when WooCommerce is active.
- Automatic creation of the gateway page with a tailored content override.
- A responsive HTML form that captures email and lets shoppers choose sign-in or account creation intent with nonce protection.
- Privacy-first messaging that avoids explicit account-existence disclosure during gateway interactions.
- Existing customers receive one-time email magic links (15-minute expiry) and are logged in on link open.
- New customers can create an account on the spot with their chosen password, receive the standard WooCommerce “new account” email, and continue straight to checkout.
- Logged-in users bypass the gateway entirely, including direct visits to the gateway URL, and are redirected to checkout.
- Session persistence for partially completed forms plus a guarded fallback link to the native checkout URL.

## Next steps

- Cross-device cart recovery for magic-link logins (Phase 4).
- WooCommerce settings toggles (Phase 5).
- Cloudflare Turnstile support (deferred).
