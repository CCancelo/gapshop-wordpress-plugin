<?php
/**
 * gapShop OTP Login
 * Include via: require_once plugin_dir_path(__FILE__) . 'gapshop-otp.php';
 */

if (!defined('ABSPATH')) exit;

// ── Settings ──────────────────────────────────────────────────

add_action('admin_init', function () {
    register_setting('gapshop_settings', 'gapshop_otp_enabled', [
        'type'              => 'boolean',
        'default'           => false,
        'sanitize_callback' => 'rest_sanitize_boolean',
    ]);
});

/**
 * Call this inside your existing settings page render function
 * where you want the toggle to appear.
 */
function gapshop_otp_settings_field(): void { ?>
    <tr>
        <th scope="row">OTP Login</th>
        <td>
            <label>
                <input type="checkbox"
                       name="gapshop_otp_enabled"
                       value="1"
                       <?php checked(1, get_option('gapshop_otp_enabled')); ?>>
                <strong>Enable OTP Login for Better Security</strong>
            </label>
            <p class="description">
                Replaces the standard WordPress and WooCommerce login forms with
                a one-time passcode sent to the customer's email. No password required.
            </p>
        </td>
    </tr>
<?php }

// ── Script Enqueue ────────────────────────────────────────────

add_action('login_enqueue_scripts', 'gapshop_otp_enqueue_login');
function gapshop_otp_enqueue_login(): void {
    if (!get_option('gapshop_otp_enabled')) return;
    gapshop_otp_enqueue_script(wp_login_url());
}

add_action('wp_enqueue_scripts', 'gapshop_otp_enqueue_frontend');
function gapshop_otp_enqueue_frontend(): void {
    if (!get_option('gapshop_otp_enabled')) return;
    if (!function_exists('is_account_page') || !is_account_page()) return;
    $redirect = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('dashboard')
        : home_url('/my-account/');
    gapshop_otp_enqueue_script($redirect);
}

function gapshop_otp_enqueue_script(string $redirect): void {
    wp_enqueue_script(
        'gapshop-otp',
        plugin_dir_url(__FILE__) . 'assets/gapshop-otp.js',
        ['jquery'],
        '1.0.1',
        true
    );
    wp_localize_script('gapshop-otp', 'gapshopOtp', [
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('gapshop_otp'),
        'redirect' => $redirect,
    ]);
}

// ── AJAX: Send OTP ────────────────────────────────────────────

add_action('wp_ajax_nopriv_gapshop_otp_send', 'gapshop_handle_otp_send');
add_action('wp_ajax_gapshop_otp_send',        'gapshop_handle_otp_send');
function gapshop_handle_otp_send(): void {
    check_ajax_referer('gapshop_otp', 'nonce');

    $email = sanitize_email($_POST['email'] ?? '');
    if (!is_email($email)) wp_send_json_error('Invalid email address.');

    $response = wp_remote_post(rtrim(get_option('gapshop_api_url', ''), '/') . '/api/wp/otp/send', [
        'timeout' => 10,
        'headers' => [
            'Content-Type'  => 'application/json',
            'X-Shop-Url'    => get_option('gapshop_shop_url', ''),
            'X-Shop-Secret' => get_option('gapshop_shop_secret', ''),
        ],
        'body' => wp_json_encode(['email' => $email]),
    ]);

    if (is_wp_error($response)) wp_send_json_error('Unable to send code. Please try again.');

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($body['success'])) wp_send_json_success();

    wp_send_json_error($body['error'] ?? 'Failed to send code.');
}

// ── AJAX: Verify OTP ──────────────────────────────────────────

add_action('wp_ajax_nopriv_gapshop_otp_verify', 'gapshop_handle_otp_verify');
add_action('wp_ajax_gapshop_otp_verify',        'gapshop_handle_otp_verify');
function gapshop_handle_otp_verify(): void {
    check_ajax_referer('gapshop_otp', 'nonce');

    $email = sanitize_email($_POST['email'] ?? '');
    $code  = sanitize_text_field($_POST['code'] ?? '');

    if (!is_email($email) || empty($code)) wp_send_json_error('Invalid request.');

    $response = wp_remote_post(rtrim(get_option('gapshop_api_url', ''), '/') . '/api/wp/otp/verify', [
        'timeout' => 10,
        'headers' => [
            'Content-Type'  => 'application/json',
            'X-Shop-Url'    => get_option('gapshop_shop_url', ''),
            'X-Shop-Secret' => get_option('gapshop_shop_secret', ''),
        ],
        'body' => wp_json_encode(['email' => $email, 'code' => $code]),
    ]);

    if (is_wp_error($response)) wp_send_json_error('Verification failed. Please try again.');

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['success'])) wp_send_json_error($body['error'] ?? 'Invalid code.');

    // Find or create WordPress user
    $user = get_user_by('email', $email);
    if (!$user) {
        $username = sanitize_user(strstr($email, '@', true), true);
        if (username_exists($username)) $username .= '_' . substr(uniqid(), -4);

        $user_id = wp_create_user($username, wp_generate_password(24, true, true), $email);
        if (is_wp_error($user_id)) wp_send_json_error('Account creation failed.');

        $display = trim(($body['firstName'] ?? '') . ' ' . ($body['lastName'] ?? ''));
        if ($display) wp_update_user(['ID' => $user_id, 'display_name' => $display]);

        $user = get_user_by('id', $user_id);
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);

    $redirect = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('dashboard')
        : home_url('/my-account/');

    wp_send_json_success(['redirect' => $redirect]);
}
