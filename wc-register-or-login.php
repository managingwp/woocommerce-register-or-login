<?php
/**
 * Plugin Name:       WooCommerce Register or Login Gateway
 * Description:       Adds an intermediate step between the cart and checkout to collect account intent.
 * Author:            managingwp
 * Version:           0.2.0
 * License:           GPL-2.0-or-later
 * Text Domain:       wc-register-or-login
 */

if (! defined('ABSPATH')) {
    exit;
}

if (defined('WCRL_PLUGIN_LOADED')) {
    return;
}

define('WCRL_PLUGIN_LOADED', true);
define('WCRL_PLUGIN_FILE', __FILE__);
define('WCRL_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WCRL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCRL_PLUGIN_VERSION', '0.2.0');

require_once WCRL_PLUGIN_PATH . 'includes/class-wcrl-gateway.php';
require_once WCRL_PLUGIN_PATH . 'includes/class-wcrl-plugin.php';

function wcrl(): WCRL_Plugin
{
    return WCRL_Plugin::instance();
}

register_activation_hook(WCRL_PLUGIN_FILE, ['WCRL_Plugin', 'activate']);
register_deactivation_hook(WCRL_PLUGIN_FILE, ['WCRL_Plugin', 'deactivate']);

wcrl();
