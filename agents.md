# agents.md

## Goal
* A plugin that you can enable to use on the woocommerce cart page that upon "Proceed to checkout" click, it will ask the user a series of questions to confirm if they have an account and either send them an email to login or create an account for them and send them the login details and then redirect them to the checkout page.
* The page should be user friendly and easy to navigate, but not too much custom code.
* The plugin should be compatible with the latest version of WooCommerce and WordPress.
* The plugin should be secure and not expose any sensitive information.
* The POC should be a mu-plugin at first.

## Notes
* The plugin should be well documented and easy to install and configure.
* The plugin should be compatiable with https://github.com/ElliotSowersby/simple-cloudflare-turnstile
* The plugin should be compatible with popular themes and plugins.
* The plugin should be compatible with popular page builders like Elementor, Beaver Builder, etc.
* Document each phase in TODO.md under completed.
* Again, this is suppose to auto detect if a user has a login. So when they enter an email address, it either asks for a password or ask them to put in a new password to sign up for an account.

## Phases
### Phase 1
* Create a basic plugin that that redirects to a custom page upon "Proceed to checkout" click.
* Create a custom page that asks the user for their email address and if they have an account or not.

### Phase 2
* Check if the user has an account or not.
* If the user does not have an account, create an account for them and send them an email with their login details
* The email can be a normal WordPress account creation email.
* Forward to the checkout with the items in their cart and the user logged in.

### Phase 3
* If the user has an account, send them an email with a login link.
* Redirect the user to the checkout page after they're logged in with their cart intact.

### Phase 4
* Add an option to enable/disable the plugin from the WooCommerce settings page.

