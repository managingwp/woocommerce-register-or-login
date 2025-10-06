
# WooCommerce Register or Login Gateway (Phase 1)

## Overview

This proof-of-concept mu-plugin inserts a friendly checkpoint between the WooCommerce cart and checkout. When shoppers click **Proceed to checkout**, they are guided to a custom page that captures their email, auto-detects existing accounts, and either logs them in or creates a new profile before forwarding them to the standard checkout.

## Requirements

- WordPress 6.0 or later
- WooCommerce 7.0 or later
- PHP 7.4+

## Quick start

1. Copy the `mu-plugins/wc-register-or-login.php` file into your WordPress install at `wp-content/mu-plugins/` (create the folder if it does not exist).
2. Load your site once; the plugin will auto-create a published page named **Confirm your checkout details** (slug: `wc-register-or-login-gateway`).
3. Visit the WooCommerce cart. The **Proceed to checkout** button now leads to the new gateway page where the confirmation form lives.
4. Submit the form or use the “Skip and go straight to checkout” link to proceed.

## Current capabilities

- Mandatory plugin scaffolding that only boots when WooCommerce is active.
- Automatic creation of the gateway page with a tailored content override.
- A responsive HTML form that auto-detects existing accounts by email and prompts for the right password flow with nonce protection.
- Live account detection powered by a tiny REST endpoint so the form updates instantly as shoppers type.
- Existing customers can log in directly from the gateway; their session is established before the checkout loads.
- New customers can create an account on the spot with their chosen password, receive the standard WooCommerce “new account” email, and continue straight to checkout.
- Session persistence for partially completed forms plus a guarded fallback link to the native checkout URL.

## Next steps

- Email one-click login links for existing customers who opt not to enter a password (Phase 3).
- Refine redirects to keep carts intact after remote logins (Phase 3).
- Add WooCommerce settings toggles and Cloudflare Turnstile support (Phase 4).
