<?php

if (! defined('ABSPATH')) {
    exit;
}

class WCRL_Gateway
{
    private const OPTION_PAGE_ID = 'wc_register_or_login_gateway_page_id';
    private const OPTION_ENABLED = 'wcrl_enabled';
    private const OPTION_MAGIC_LINK_TTL_MINUTES = 'wcrl_magic_link_ttl_minutes';
    private const OPTION_RATE_LIMIT_COUNT = 'wcrl_magic_link_rate_limit_count';
    private const OPTION_RATE_LIMIT_WINDOW_MINUTES = 'wcrl_magic_link_rate_limit_window_minutes';
    private const OPTION_LOGGING_ENABLED = 'wcrl_logging_enabled';
    private const PAGE_SLUG = 'wc-register-or-login-gateway';
    private const SESSION_KEY = 'wc_register_or_login_gateway_data';
    private const NONCE_ACTION = 'wc_register_or_login_gateway';
    private const MAGIC_LINK_QUERY_ARG = 'wcrl_magic_link';
    private const DEFAULT_MAGIC_LINK_TTL_MINUTES = 15;
    private const DEFAULT_MAGIC_LINK_RATE_LIMIT_COUNT = 3;
    private const DEFAULT_MAGIC_LINK_RATE_LIMIT_WINDOW_MINUTES = 15;
    private const MAGIC_LINK_TRANSIENT_PREFIX = 'wcrl_ml_';
    private const MAGIC_LINK_RATE_PREFIX = 'wcrl_ml_rate_';

    private ?int $page_id = null;

    public function bootstrap(): void
    {
        add_action('init', [$this, 'ensure_gateway_page']);

        if (! $this->is_enabled()) {
            return;
        }

        add_action('init', [$this, 'register_cart_button_override']);
        add_action('wp', [$this, 'capture_page_id']);
        add_action('template_redirect', [$this, 'maybe_handle_magic_link'], 0);
        add_action('template_redirect', [$this, 'maybe_redirect_logged_in_gateway'], 1);
        add_action('template_redirect', [$this, 'handle_form_submission']);

        add_filter('woocommerce_get_checkout_url', [$this, 'filter_checkout_url']);
        add_filter('the_content', [$this, 'maybe_render_gateway_content']);
    }

    public function register_cart_button_override(): void
    {
        if (is_admin() && ! wp_doing_ajax()) {
            return;
        }

        remove_action('woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20);
        add_action('woocommerce_proceed_to_checkout', [$this, 'render_proceed_to_checkout_button'], 20);
    }

    public function ensure_gateway_page(): void
    {
        $page_id = (int) get_option(self::OPTION_PAGE_ID);
        $page = $page_id ? get_post($page_id) : null;

        if ($page instanceof WP_Post && 'publish' === $page->post_status) {
            $this->page_id = $page_id;
            return;
        }

        $existing = get_page_by_path(self::PAGE_SLUG);
        if ($existing instanceof WP_Post && 'publish' === $existing->post_status) {
            $this->page_id = $existing->ID;
            update_option(self::OPTION_PAGE_ID, $existing->ID, false);
            return;
        }

        $new_page_id = wp_insert_post([
            'post_type'   => 'page',
            'post_title'  => __('Confirm your checkout details', 'wc-register-or-login'),
            'post_name'   => self::PAGE_SLUG,
            'post_status' => 'publish',
            'post_author' => get_current_user_id() ?: 0,
            'meta_input'  => [
                '_wp_page_template' => 'default',
            ],
        ], true);

        if (! is_wp_error($new_page_id)) {
            $this->page_id = (int) $new_page_id;
            update_option(self::OPTION_PAGE_ID, $this->page_id, false);
        }
    }

    public function capture_page_id(): void
    {
        if (null !== $this->page_id) {
            return;
        }

        $page_id = (int) get_option(self::OPTION_PAGE_ID);
        if ($page_id > 0) {
            $this->page_id = $page_id;
        }
    }

    public function filter_checkout_url(string $url): string
    {
        if (! function_exists('is_cart')) {
            return $url;
        }

        if (is_user_logged_in()) {
            return $url;
        }

        if (! is_cart() || $this->is_gateway_page()) {
            return $url;
        }

        $gateway_url = $this->get_gateway_url();
        return $gateway_url ?: $url;
    }

    public function maybe_render_gateway_content(string $content): string
    {
        if (! $this->is_gateway_page()) {
            return $content;
        }

        ob_start();
        $this->render_form();
        return ob_get_clean();
    }

    private function render_form(): void
    {
        $action = esc_url($this->get_gateway_url());
        $checkout_url = esc_url(wc_get_checkout_url());
        $session = $this->get_session_data();
        $email = isset($session['email']) ? sanitize_email($session['email']) : '';
        $mode = isset($session['mode']) ? sanitize_key($session['mode']) : 'login';

        if ($email && ! is_email($email)) {
            $email = '';
        }

        if (! in_array($mode, ['login', 'register'], true)) {
            $mode = 'login';
        }
        ?>
        <div class="wcrl-gateway">
            <style>
                .wcrl-gateway { max-width: 640px; margin: 40px auto; padding: 32px; background: #fff; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border-radius: 12px; font-family: inherit; }
                .wcrl-gateway h1 { font-size: 1.75rem; margin-bottom: 1rem; }
                .wcrl-gateway p { color: #444; line-height: 1.6; }
                .wcrl-gateway form { margin-top: 24px; }
                .wcrl-gateway label { display: block; font-weight: 600; margin-bottom: 8px; }
                .wcrl-gateway input[type="email"],
                .wcrl-gateway input[type="password"] { width: 100%; padding: 12px 14px; border-radius: 6px; border: 1px solid #d7d7d7; font-size: 1rem; margin-bottom: 20px; }
                .wcrl-gateway .wcrl-step { display: none; animation: fadeIn 180ms ease-in; }
                .wcrl-gateway .wcrl-step.is-active { display: block; }
                .wcrl-gateway .wcrl-hint { color: #666; font-size: 0.95rem; margin-bottom: 12px; }
                .wcrl-gateway button[type="submit"] { background: #2f9d55; color: #fff; padding: 14px 22px; border: none; border-radius: 6px; font-size: 1rem; cursor: pointer; transition: background 0.2s ease; }
                .wcrl-gateway button[type="submit"]:hover { background: #2a8a4c; }
                .wcrl-gateway .wcrl-secondary { display: inline-block; margin-top: 16px; color: #555; text-decoration: underline; }
                .wcrl-gateway .wcrl-password-row { display: flex; flex-direction: column; gap: 8px; }
                @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
                @media (max-width: 600px) { .wcrl-gateway { margin: 20px 16px; padding: 24px; } }
            </style>
            <h1><?php esc_html_e('Almost there—let’s get you checked out', 'wc-register-or-login'); ?></h1>
            <p><?php esc_html_e('Enter your email and choose whether to sign in or create an account to continue to checkout.', 'wc-register-or-login'); ?></p>
            <?php wc_print_notices(); ?>
            <form method="post" action="<?php echo $action; ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, '_wcrl_nonce'); ?>
                <label for="wcrl_email"><?php esc_html_e('Email address', 'wc-register-or-login'); ?></label>
                <input type="email" id="wcrl_email" name="wcrl_email" value="<?php echo esc_attr($email); ?>" required autocomplete="email" />

                <fieldset>
                    <legend class="wcrl-hint"><?php esc_html_e('How would you like to continue?', 'wc-register-or-login'); ?></legend>
                    <p>
                        <label for="wcrl_mode_login">
                            <input type="radio" id="wcrl_mode_login" name="wcrl_mode" value="login" <?php checked('login', $mode); ?> />
                            <?php esc_html_e('Sign in to my account', 'wc-register-or-login'); ?>
                        </label>
                    </p>
                    <p>
                        <label for="wcrl_mode_register">
                            <input type="radio" id="wcrl_mode_register" name="wcrl_mode" value="register" <?php checked('register', $mode); ?> />
                            <?php esc_html_e('Create a new account', 'wc-register-or-login'); ?>
                        </label>
                    </p>
                </fieldset>

                <div class="wcrl-step wcrl-step--login <?php echo 'login' === $mode ? 'is-active' : ''; ?>" data-step="login">
                    <p class="wcrl-hint"><?php esc_html_e('We’ll email you a secure one-time sign-in link to continue checkout.', 'wc-register-or-login'); ?></p>
                </div>

                <div class="wcrl-step wcrl-step--register <?php echo 'register' === $mode ? 'is-active' : ''; ?>" data-step="register">
                    <p class="wcrl-hint"><?php esc_html_e('Create and confirm a password to set up your account and continue.', 'wc-register-or-login'); ?></p>
                    <div class="wcrl-password-row">
                        <label for="wcrl_password_new"><?php esc_html_e('Create password', 'wc-register-or-login'); ?></label>
                        <input type="password" id="wcrl_password_new" name="wcrl_password_new" autocomplete="new-password" <?php echo 'register' === $mode ? '' : 'disabled'; ?> />
                        <label for="wcrl_password_confirm"><?php esc_html_e('Confirm password', 'wc-register-or-login'); ?></label>
                        <input type="password" id="wcrl_password_confirm" name="wcrl_password_confirm" autocomplete="new-password" <?php echo 'register' === $mode ? '' : 'disabled'; ?> />
                    </div>
                </div>

                <button type="submit"><?php esc_html_e('Continue to checkout', 'wc-register-or-login'); ?></button>
            </form>

            <a class="wcrl-secondary" href="<?php echo $checkout_url; ?>">
                <?php esc_html_e('Skip and go straight to checkout', 'wc-register-or-login'); ?>
            </a>
        </div>
        <script>
        (function(){
            const form = document.querySelector('.wcrl-gateway form');
            if (!form) { return; }

            const loginStep = form.querySelector('[data-step="login"]');
            const registerStep = form.querySelector('[data-step="register"]');
            const passwordNew = form.querySelector('#wcrl_password_new');
            const passwordConfirm = form.querySelector('#wcrl_password_confirm');
            const modeLogin = form.querySelector('#wcrl_mode_login');
            const modeRegister = form.querySelector('#wcrl_mode_register');

            const setMode = (mode) => {
                if (modeLogin && modeRegister) {
                    modeLogin.checked = 'login' === mode;
                    modeRegister.checked = 'register' === mode;
                }

                if ('login' === mode) {
                    loginStep.classList.add('is-active');
                    registerStep.classList.remove('is-active');
                    passwordNew.disabled = true;
                    passwordConfirm.disabled = true;
                    passwordNew.required = false;
                    passwordConfirm.required = false;
                } else if ('register' === mode) {
                    registerStep.classList.add('is-active');
                    loginStep.classList.remove('is-active');
                    passwordNew.disabled = false;
                    passwordConfirm.disabled = false;
                    passwordNew.required = true;
                    passwordConfirm.required = true;
                } else {
                    loginStep.classList.add('is-active');
                    registerStep.classList.add('is-active');
                    passwordNew.disabled = false;
                    passwordConfirm.disabled = false;
                    passwordNew.required = false;
                    passwordConfirm.required = false;
                }
            };

            if (modeLogin) {
                modeLogin.addEventListener('change', () => {
                    if (modeLogin.checked) {
                        setMode('login');
                    }
                });
            }

            if (modeRegister) {
                modeRegister.addEventListener('change', () => {
                    if (modeRegister.checked) {
                        setMode('register');
                    }
                });
            }

            const initialMode = modeRegister && modeRegister.checked ? 'register' : 'login';
            setMode(initialMode);

        })();
        </script>
        <?php
    }

    public function render_proceed_to_checkout_button(): void
    {
        if (is_user_logged_in()) {
            $target_url = wc_get_checkout_url();
        } else {
            $target_url = $this->get_gateway_url() ?: wc_get_checkout_url();
        }

        $label = apply_filters('woocommerce_proceed_to_checkout_text', __('Proceed to checkout', 'woocommerce'));

        printf(
            '<a href="%1$s" class="checkout-button button alt wc-forward">%2$s</a>',
            esc_url($target_url),
            esc_html($label)
        );
    }

    public function handle_form_submission(): void
    {
        if (! $this->is_gateway_page()) {
            return;
        }

        if (! isset($_POST['_wcrl_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['_wcrl_nonce']), self::NONCE_ACTION)) {
            return;
        }

        $email = isset($_POST['wcrl_email']) ? sanitize_email(wp_unslash($_POST['wcrl_email'])) : '';
        $mode = isset($_POST['wcrl_mode']) ? sanitize_key(wp_unslash($_POST['wcrl_mode'])) : 'login';

        if (! in_array($mode, ['login', 'register'], true)) {
            $mode = 'login';
        }

        if (empty($email) || ! is_email($email)) {
            wc_add_notice(__('Please enter a valid email address to continue.', 'wc-register-or-login'), 'error');
            wp_safe_redirect($this->get_gateway_url());
            exit;
        }

        $user = get_user_by('email', $email);

        if ('login' === $mode) {
            $result = $this->request_magic_link($email);

            $this->persist_session_data([
                'email'        => $email,
                'mode'         => 'login',
                'submitted_at' => time(),
            ]);

            if (is_wp_error($result)) {
                if ('rate_limited' === $result->get_error_code()) {
                    wc_add_notice(__('Too many login link requests. Please wait a few minutes and try again.', 'wc-register-or-login'), 'error');
                } else {
                    wc_add_notice(__('We couldn’t send your login link right now. Please try again in a moment.', 'wc-register-or-login'), 'error');
                }

                wp_safe_redirect($this->get_gateway_url());
                exit;
            }

            wc_add_notice(__('If an account exists for that email, we sent a one-time sign-in link. Check your inbox to continue checkout.', 'wc-register-or-login'), 'success');
            wp_safe_redirect($this->get_gateway_url());
            exit;
        } else {
            $password = isset($_POST['wcrl_password_new']) ? (string) wp_unslash($_POST['wcrl_password_new']) : '';
            $confirm  = isset($_POST['wcrl_password_confirm']) ? (string) wp_unslash($_POST['wcrl_password_confirm']) : '';

            if ('' === $password) {
                wc_add_notice(__('Please choose a password for your new account.', 'wc-register-or-login'), 'error');
                $this->persist_session_data([
                    'email'        => $email,
                    'mode'         => 'register',
                    'submitted_at' => time(),
                ]);
                wp_safe_redirect($this->get_gateway_url());
                exit;
            }

            if ($password !== $confirm) {
                wc_add_notice(__('Your passwords did not match. Please try again.', 'wc-register-or-login'), 'error');
                $this->persist_session_data([
                    'email'        => $email,
                    'mode'         => 'register',
                    'submitted_at' => time(),
                ]);
                wp_safe_redirect($this->get_gateway_url());
                exit;
            }

            $created = $this->create_customer_account($email, $password);

            if (is_wp_error($created)) {
                $message = __('We couldn’t create your account with those details. Please try again or sign in instead.', 'wc-register-or-login');

                wc_add_notice($message, 'error');
                $this->persist_session_data([
                    'email'        => $email,
                    'mode'         => 'register',
                    'submitted_at' => time(),
                ]);
                wp_safe_redirect($this->get_gateway_url());
                exit;
            }

            $this->login_customer((int) $created);
            $this->persist_session_data(null);
            wc_add_notice(__('Welcome aboard! We created your account and sent the login details to your email.', 'wc-register-or-login'), 'success');
        }

        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    public function maybe_redirect_logged_in_gateway(): void
    {
        if (! is_user_logged_in() || ! $this->is_gateway_page()) {
            return;
        }

        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    public function maybe_handle_magic_link(): void
    {
        if (! $this->is_gateway_page()) {
            return;
        }

        if (! isset($_GET[self::MAGIC_LINK_QUERY_ARG])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET[self::MAGIC_LINK_QUERY_ARG]));

        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->log_event('magic_link_invalid_format');
            wc_add_notice(__('This login link is invalid or has expired. Please request a new one.', 'wc-register-or-login'), 'error');
            wp_safe_redirect($this->get_gateway_url());
            exit;
        }

        $payload = $this->consume_magic_link_payload($token);
        if (is_wp_error($payload)) {
            $this->log_event('magic_link_payload_rejected', [
                'reason' => $payload->get_error_code(),
            ]);
            wc_add_notice(__('This login link is invalid or has expired. Please request a new one.', 'wc-register-or-login'), 'error');
            wp_safe_redirect($this->get_gateway_url());
            exit;
        }

        $user_id = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
        $payload_email = isset($payload['email']) ? strtolower((string) $payload['email']) : '';
        $user = $user_id ? get_user_by('id', $user_id) : false;

        if (! ($user instanceof WP_User) || ! $payload_email || strtolower($user->user_email) !== $payload_email) {
            $this->log_event('magic_link_user_mismatch');
            wc_add_notice(__('This login link is invalid or has expired. Please request a new one.', 'wc-register-or-login'), 'error');
            wp_safe_redirect($this->get_gateway_url());
            exit;
        }

        $this->login_customer($user_id);
        if (isset($payload['cart_snapshot']) && is_array($payload['cart_snapshot'])) {
            $this->restore_cart_from_snapshot($payload['cart_snapshot']);
        }
        $this->log_event('magic_link_login_success', [
            'user_id' => $user_id,
        ]);
        $this->persist_session_data(null);
        wc_add_notice(__('Welcome back! You’re now logged in and heading to checkout.', 'wc-register-or-login'), 'success');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    private function get_gateway_url(): ?string
    {
        $page_id = $this->get_gateway_page_id();
        return $page_id ? get_permalink($page_id) : null;
    }

    private function get_gateway_page_id(): ?int
    {
        if (null === $this->page_id) {
            $this->ensure_gateway_page();
        }

        return $this->page_id;
    }

    private function is_gateway_page(): bool
    {
        $page_id = $this->get_gateway_page_id();
        return (bool) ($page_id && is_page($page_id));
    }

    private function login_customer(int $user_id): void
    {
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);

        if (function_exists('wc_set_customer_auth_cookie')) {
            wc_set_customer_auth_cookie($user_id);
        }

        do_action('woocommerce_login_customer', $user_id);
    }

    /**
     * @return int|WP_Error
     */
    private function create_customer_account(string $email, string $password)
    {
        $username = $this->generate_username_from_email($email);

        if (function_exists('wc_create_new_customer')) {
            $user_id = wc_create_new_customer($email, $username, $password);
            if (is_wp_error($user_id)) {
                return $user_id;
            }

            return (int) $user_id;
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        if (function_exists('wp_new_user_notification')) {
            wp_new_user_notification($user_id, null, 'user');
        }

        return (int) $user_id;
    }

    private function generate_username_from_email(string $email): string
    {
        $parts = explode('@', $email);
        $base = sanitize_user($parts[0] ?? $email, true);

        if ('' === $base) {
            $base = 'user';
        }

        $username = $base;
        $suffix = 1;

        while (username_exists($username)) {
            $username = $base . $suffix;
            $suffix++;
        }

        return $username;
    }

    private function get_session_data(): array
    {
        $session = function_exists('WC') ? WC()->session : null;

        if ($session && method_exists($session, 'get')) {
            $data = $session->get(self::SESSION_KEY);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    private function persist_session_data(?array $data): void
    {
        $session = function_exists('WC') ? WC()->session : null;

        if ($session && method_exists($session, 'set')) {
            $session->set(self::SESSION_KEY, $data);
        }
    }

    /**
     * @return true|WP_Error
     */
    private function request_magic_link(string $email)
    {
        $ttl_minutes = $this->get_magic_link_ttl_minutes();
        $ttl_seconds = $ttl_minutes * MINUTE_IN_SECONDS;

        if (! $this->consume_magic_link_rate_limit_slot($email)) {
            $this->log_event('magic_link_rate_limited');
            return new WP_Error('rate_limited', __('Too many login link requests.', 'wc-register-or-login'));
        }

        $user = get_user_by('email', $email);
        if (! ($user instanceof WP_User)) {
            $this->log_event('magic_link_request_nonexistent_account');
            return true;
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $exception) {
            $token = wp_generate_password(64, false, false);
        }

        $key = $this->get_magic_link_storage_key($token);

        $payload = [
            'user_id'    => (int) $user->ID,
            'email'      => strtolower($email),
            'created_at' => time(),
            'expires_at' => time() + $ttl_seconds,
            'cart_snapshot' => $this->get_current_cart_snapshot(),
        ];

        set_transient($key, $payload, $ttl_seconds);

        $url = add_query_arg([
            self::MAGIC_LINK_QUERY_ARG => $token,
        ], $this->get_gateway_url() ?: wc_get_checkout_url());

        $subject = __('Your checkout login link', 'wc-register-or-login');
        $message = sprintf(
            __('Use this one-time login link to continue checkout:%1$s%1$s%2$s%1$s%1$sThis link expires in %3$d minutes.', 'wc-register-or-login'),
            PHP_EOL,
            esc_url_raw($url),
            $ttl_minutes
        );

        $sent = wp_mail($email, $subject, $message);
        if (! $sent) {
            delete_transient($key);
            $this->log_event('magic_link_send_failed', [
                'user_id' => (int) $user->ID,
            ]);
            return new WP_Error('mail_send_failed', __('Unable to send login link.', 'wc-register-or-login'));
        }

        $this->log_event('magic_link_sent', [
            'user_id' => (int) $user->ID,
        ]);

        return true;
    }

    /**
     * @return array|WP_Error
     */
    private function consume_magic_link_payload(string $token)
    {
        $key = $this->get_magic_link_storage_key($token);
        $payload = get_transient($key);

        if (! is_array($payload)) {
            return new WP_Error('invalid_token', __('Invalid token.', 'wc-register-or-login'));
        }

        delete_transient($key);

        $expires_at = isset($payload['expires_at']) ? (int) $payload['expires_at'] : 0;
        if ($expires_at > 0 && $expires_at < time()) {
            return new WP_Error('expired_token', __('Expired token.', 'wc-register-or-login'));
        }

        return $payload;
    }

    private function consume_magic_link_rate_limit_slot(string $email): bool
    {
        $key = $this->get_magic_link_rate_key($email);
        $attempts = get_transient($key);

        if (! is_array($attempts)) {
            $attempts = [];
        }

        $window_seconds = $this->get_magic_link_rate_limit_window_minutes() * MINUTE_IN_SECONDS;
        $window_start = time() - $window_seconds;
        $limit_count = $this->get_magic_link_rate_limit_count();
        $filtered_attempts = [];

        foreach ($attempts as $timestamp) {
            $timestamp = (int) $timestamp;
            if ($timestamp > $window_start) {
                $filtered_attempts[] = $timestamp;
            }
        }

        if (count($filtered_attempts) >= $limit_count) {
            set_transient($key, $filtered_attempts, $window_seconds);
            return false;
        }

        $filtered_attempts[] = time();
        set_transient($key, $filtered_attempts, $window_seconds);

        return true;
    }

    private function get_magic_link_storage_key(string $token): string
    {
        $hash = hash_hmac('sha256', strtolower($token), wp_salt('nonce'));
        return self::MAGIC_LINK_TRANSIENT_PREFIX . substr($hash, 0, 40);
    }

    private function get_magic_link_rate_key(string $email): string
    {
        $normalized = strtolower(trim($email));
        $hash = hash('sha256', $normalized);
        return self::MAGIC_LINK_RATE_PREFIX . substr($hash, 0, 40);
    }

    private function get_current_cart_snapshot(): array
    {
        if (! function_exists('WC') || ! WC()->cart) {
            return [];
        }

        $snapshot = [];

        foreach (WC()->cart->get_cart() as $item) {
            $product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
            $variation_id = isset($item['variation_id']) ? (int) $item['variation_id'] : 0;
            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;
            $variation = isset($item['variation']) && is_array($item['variation']) ? $item['variation'] : [];

            if ($product_id <= 0 || $quantity <= 0) {
                continue;
            }

            $snapshot[] = [
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $quantity,
                'variation' => $this->normalize_variation_attributes($variation),
            ];
        }

        return $snapshot;
    }

    private function restore_cart_from_snapshot(array $snapshot): void
    {
        if (! function_exists('WC') || ! WC()->cart || empty($snapshot)) {
            return;
        }

        $cart = WC()->cart;
        $existing_map = [];

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            $variation_id = isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;
            $variation = isset($cart_item['variation']) && is_array($cart_item['variation']) ? $cart_item['variation'] : [];

            if ($product_id <= 0) {
                continue;
            }

            $item_key = $this->build_cart_merge_key($product_id, $variation_id, $variation);
            $existing_map[$item_key] = $cart_item_key;
        }

        $merged_items = 0;
        $added_items = 0;

        foreach ($snapshot as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $product_id = isset($entry['product_id']) ? (int) $entry['product_id'] : 0;
            $variation_id = isset($entry['variation_id']) ? (int) $entry['variation_id'] : 0;
            $quantity = isset($entry['quantity']) ? (int) $entry['quantity'] : 0;
            $variation = isset($entry['variation']) && is_array($entry['variation']) ? $entry['variation'] : [];

            if ($product_id <= 0 || $quantity <= 0) {
                continue;
            }

            $variation = $this->normalize_variation_attributes($variation);
            $item_key = $this->build_cart_merge_key($product_id, $variation_id, $variation);

            if (isset($existing_map[$item_key])) {
                $cart_item_key = $existing_map[$item_key];
                $existing_qty = isset($cart->cart_contents[$cart_item_key]['quantity']) ? (int) $cart->cart_contents[$cart_item_key]['quantity'] : 0;
                $cart->set_quantity($cart_item_key, $existing_qty + $quantity, false);
                $merged_items++;
                continue;
            }

            $added_key = $cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
            if (! $added_key) {
                continue;
            }

            $existing_map[$item_key] = $added_key;
            $added_items++;
        }

        $cart->calculate_totals();
        $this->log_event('cart_snapshot_restored', [
            'snapshot_items' => count($snapshot),
            'merged_items' => $merged_items,
            'added_items' => $added_items,
        ]);
    }

    private function build_cart_merge_key(int $product_id, int $variation_id, array $variation): string
    {
        $normalized_variation = $this->normalize_variation_attributes($variation);

        return implode('|', [
            (string) $product_id,
            (string) $variation_id,
            md5(wp_json_encode($normalized_variation)),
        ]);
    }

    private function normalize_variation_attributes(array $variation): array
    {
        if (empty($variation)) {
            return [];
        }

        $normalized = [];

        foreach ($variation as $key => $value) {
            $normalized[(string) $key] = wc_clean((string) $value);
        }

        ksort($normalized);

        return $normalized;
    }

    private function is_enabled(): bool
    {
        return 'no' !== get_option(self::OPTION_ENABLED, 'yes');
    }

    private function get_magic_link_ttl_minutes(): int
    {
        $value = (int) get_option(self::OPTION_MAGIC_LINK_TTL_MINUTES, self::DEFAULT_MAGIC_LINK_TTL_MINUTES);
        return max(1, min(1440, $value));
    }

    private function get_magic_link_rate_limit_count(): int
    {
        $value = (int) get_option(self::OPTION_RATE_LIMIT_COUNT, self::DEFAULT_MAGIC_LINK_RATE_LIMIT_COUNT);
        return max(1, min(20, $value));
    }

    private function get_magic_link_rate_limit_window_minutes(): int
    {
        $value = (int) get_option(self::OPTION_RATE_LIMIT_WINDOW_MINUTES, self::DEFAULT_MAGIC_LINK_RATE_LIMIT_WINDOW_MINUTES);
        return max(1, min(1440, $value));
    }

    private function is_logging_enabled(): bool
    {
        return 'yes' === get_option(self::OPTION_LOGGING_ENABLED, 'no');
    }

    private function log_event(string $event, array $context = []): void
    {
        if (! $this->is_logging_enabled()) {
            return;
        }

        if (! function_exists('wc_get_logger')) {
            return;
        }

        $safe_context = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || null === $value) {
                $safe_context[(string) $key] = $value;
            }
        }

        $safe_context['source'] = 'wc-register-or-login';
        wc_get_logger()->info($event, $safe_context);
    }
}
