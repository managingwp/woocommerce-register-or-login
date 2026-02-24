<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WCRL_Plugin
{
    private const SETTINGS_SECTION_ID = 'wcrl_gateway';
    private const OPTION_ENABLED = 'wcrl_enabled';
    private const OPTION_MAGIC_LINK_TTL_MINUTES = 'wcrl_magic_link_ttl_minutes';
    private const OPTION_RATE_LIMIT_COUNT = 'wcrl_magic_link_rate_limit_count';
    private const OPTION_RATE_LIMIT_WINDOW_MINUTES = 'wcrl_magic_link_rate_limit_window_minutes';
    private const OPTION_GATEWAY_PAGE_ID = 'wc_register_or_login_gateway_page_id';
    private const OPTION_LOGGING_ENABLED = 'wcrl_logging_enabled';

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

        self::seed_default_options();

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

        self::seed_default_options();

        add_filter('woocommerce_get_sections_account', [$this, 'add_settings_section']);
        add_filter('woocommerce_get_settings_account', [$this, 'add_settings_fields'], 10, 2);
        add_action('woocommerce_update_options_account', [$this, 'save_settings_fields']);

        $this->gateway = new WCRL_Gateway();
        $this->gateway->bootstrap();
    }

    public function add_settings_section(array $sections): array
    {
        $sections[self::SETTINGS_SECTION_ID] = __('Register or Login Gateway', 'wc-register-or-login');
        return $sections;
    }

    public function add_settings_fields(array $settings, string $current_section): array
    {
        if (self::SETTINGS_SECTION_ID !== $current_section) {
            return $settings;
        }

        return $this->get_settings_fields();
    }

    public function save_settings_fields(): void
    {
        $current_section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';

        if (self::SETTINGS_SECTION_ID !== $current_section) {
            return;
        }

        WC_Admin_Settings::save_fields($this->get_settings_fields());
        $this->normalize_settings_values();
    }

    private static function seed_default_options(): void
    {
        add_option(self::OPTION_ENABLED, 'yes');
        add_option(self::OPTION_MAGIC_LINK_TTL_MINUTES, 15);
        add_option(self::OPTION_RATE_LIMIT_COUNT, 3);
        add_option(self::OPTION_RATE_LIMIT_WINDOW_MINUTES, 15);
        add_option(self::OPTION_LOGGING_ENABLED, 'no');
    }

    private function get_settings_fields(): array
    {
        return [
            [
                'name' => __('Register or Login Gateway', 'wc-register-or-login'),
                'type' => 'title',
                'desc' => __('Control checkout gateway behavior, magic-link limits, and operational logging.', 'wc-register-or-login'),
                'id'   => 'wcrl_gateway_settings',
            ],
            [
                'title'   => __('Enable gateway flow', 'wc-register-or-login'),
                'id'      => self::OPTION_ENABLED,
                'type'    => 'checkbox',
                'default' => 'yes',
                'desc'    => __('When enabled, guest checkout is routed through the register/login gateway.', 'wc-register-or-login'),
            ],
            [
                'title'    => __('Gateway page', 'wc-register-or-login'),
                'id'       => self::OPTION_GATEWAY_PAGE_ID,
                'type'     => 'single_select_page',
                'default'  => '0',
                'desc'     => __('Select the page used as the register/login gateway.', 'wc-register-or-login'),
                'desc_tip' => true,
            ],
            [
                'title'             => __('Magic-link TTL (minutes)', 'wc-register-or-login'),
                'id'                => self::OPTION_MAGIC_LINK_TTL_MINUTES,
                'type'              => 'number',
                'default'           => '15',
                'desc'              => __('How long a login link remains valid.', 'wc-register-or-login'),
                'desc_tip'          => true,
                'custom_attributes' => [
                    'min'  => 1,
                    'max'  => 1440,
                    'step' => 1,
                ],
            ],
            [
                'title'             => __('Rate limit count', 'wc-register-or-login'),
                'id'                => self::OPTION_RATE_LIMIT_COUNT,
                'type'              => 'number',
                'default'           => '3',
                'desc'              => __('Maximum magic-link sends allowed per email in the configured window.', 'wc-register-or-login'),
                'desc_tip'          => true,
                'custom_attributes' => [
                    'min'  => 1,
                    'max'  => 20,
                    'step' => 1,
                ],
            ],
            [
                'title'             => __('Rate limit window (minutes)', 'wc-register-or-login'),
                'id'                => self::OPTION_RATE_LIMIT_WINDOW_MINUTES,
                'type'              => 'number',
                'default'           => '15',
                'desc'              => __('Time window used for rate limiting magic-link sends.', 'wc-register-or-login'),
                'desc_tip'          => true,
                'custom_attributes' => [
                    'min'  => 1,
                    'max'  => 1440,
                    'step' => 1,
                ],
            ],
            [
                'title'   => __('Enable operational logging', 'wc-register-or-login'),
                'id'      => self::OPTION_LOGGING_ENABLED,
                'type'    => 'checkbox',
                'default' => 'no',
                'desc'    => __('Log non-PII events to WooCommerce logs for troubleshooting.', 'wc-register-or-login'),
            ],
            [
                'type' => 'sectionend',
                'id'   => 'wcrl_gateway_settings',
            ],
        ];
    }

    private function normalize_settings_values(): void
    {
        $ttl = max(1, min(1440, (int) get_option(self::OPTION_MAGIC_LINK_TTL_MINUTES, 15)));
        $count = max(1, min(20, (int) get_option(self::OPTION_RATE_LIMIT_COUNT, 3)));
        $window = max(1, min(1440, (int) get_option(self::OPTION_RATE_LIMIT_WINDOW_MINUTES, 15)));
        $page_id = max(0, (int) get_option(self::OPTION_GATEWAY_PAGE_ID, 0));

        update_option(self::OPTION_MAGIC_LINK_TTL_MINUTES, $ttl, false);
        update_option(self::OPTION_RATE_LIMIT_COUNT, $count, false);
        update_option(self::OPTION_RATE_LIMIT_WINDOW_MINUTES, $window, false);
        update_option(self::OPTION_GATEWAY_PAGE_ID, $page_id, false);
    }
}
