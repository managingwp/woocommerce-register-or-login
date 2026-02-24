<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WCRL_Plugin
{
    private static ?self $instance = null;

    private ?WCRL_Gateway $gateway = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        if (! class_exists('WooCommerce')) {
            return;
        }

        $gateway = new WCRL_Gateway();
        $gateway->ensure_gateway_page();
    }

    public static function deactivate(): void
    {
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'bootstrap']);
    }

    public function bootstrap(): void
    {
        if (! class_exists('WooCommerce')) {
            return;
        }

        $this->gateway = new WCRL_Gateway();
        $this->gateway->bootstrap();
    }
}
