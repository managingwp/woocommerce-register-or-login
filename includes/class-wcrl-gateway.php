<?php

if (! defined('ABSPATH')) {
    exit;
}

class WCRL_Gateway
{
    private const OPTION_PAGE_ID = 'wc_register_or_login_gateway_page_id';
    private const PAGE_SLUG = 'wc-register-or-login-gateway';
    private const SESSION_KEY = 'wc_register_or_login_gateway_data';
    private const NONCE_ACTION = 'wc_register_or_login_gateway';

    private ?int $page_id = null;

    public function bootstrap(): void
    {
        add_action('init', [$this, 'ensure_gateway_page']);
        add_action('init', [$this, 'register_cart_button_override']);
        add_action('wp', [$this, 'capture_page_id']);
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
                .wcrl-gateway .wcrl-status { font-size: 0.9rem; color: #2f6f9d; margin-bottom: 16px; display: none; }
                .wcrl-gateway .wcrl-status.is-visible { display: block; }
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
                    <p class="wcrl-hint"><?php esc_html_e('Enter your password to sign in and continue to checkout.', 'wc-register-or-login'); ?></p>
                    <label for="wcrl_password_existing"><?php esc_html_e('Account password', 'wc-register-or-login'); ?></label>
                    <input type="password" id="wcrl_password_existing" name="wcrl_password_existing" autocomplete="current-password" <?php echo 'login' === $mode ? '' : 'disabled'; ?> />
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
            const passwordExisting = form.querySelector('#wcrl_password_existing');
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
                    passwordExisting.disabled = false;
                    passwordExisting.required = true;
                    passwordNew.disabled = true;
                    passwordConfirm.disabled = true;
                    passwordNew.required = false;
                    passwordConfirm.required = false;
                } else if ('register' === mode) {
                    registerStep.classList.add('is-active');
                    loginStep.classList.remove('is-active');
                    passwordExisting.disabled = true;
                    passwordExisting.required = false;
                    passwordNew.disabled = false;
                    passwordConfirm.disabled = false;
                    passwordNew.required = true;
                    passwordConfirm.required = true;
                } else {
                    loginStep.classList.add('is-active');
                    registerStep.classList.add('is-active');
                    passwordExisting.disabled = false;
                    passwordExisting.required = false;
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
            $password = isset($_POST['wcrl_password_existing']) ? (string) wp_unslash($_POST['wcrl_password_existing']) : '';

            if ('' === $password) {
                wc_add_notice(__('Please enter your account password so we can log you in.', 'wc-register-or-login'), 'error');
                $this->persist_session_data([
                    'email'        => $email,
                    'mode'         => 'login',
                    'submitted_at' => time(),
                ]);
                wp_safe_redirect($this->get_gateway_url());
                exit;
            }

            if (! $user) {
                wc_add_notice(__('We couldn’t sign you in with those details. Please check your email and password or create a new account.', 'wc-register-or-login'), 'error');
                $this->persist_session_data([
                    'email'        => $email,
                    'mode'         => 'login',
                    'submitted_at' => time(),
                ]);
                wp_safe_redirect($this->get_gateway_url());
                exit;
            }

            $credentials = [
                'user_login'    => $user->user_login,
                'user_password' => $password,
                'remember'      => true,
            ];

            $signed_in = wp_signon($credentials, false);

            if (is_wp_error($signed_in)) {
                wc_add_notice(__('We couldn’t sign you in with those details. Please check your email and password or create a new account.', 'wc-register-or-login'), 'error');
                $this->persist_session_data([
                    'email'        => $email,
                    'mode'         => 'login',
                    'submitted_at' => time(),
                ]);
                wp_safe_redirect($this->get_gateway_url());
                exit;
            }

            $this->login_customer((int) $signed_in->ID);
            $this->persist_session_data(null);
            wc_add_notice(__('Welcome back! You’re now logged in and heading to checkout.', 'wc-register-or-login'), 'success');
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
}
