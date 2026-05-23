<?php
/**
 * Plugin Name: gapShop
 * Plugin URI:  https://wp.gapshop.net
 * Description: Connects your WordPress site to the gapShop eCommerce platform.
 * Version:     1.0.30
 * Author:      gapShop
 * License:     GPL2
 */

if (!defined('ABSPATH')) exit;

define('GAPSHOP_API',        'https://api.gapshop.net');
define('GAPSHOP_ONBOARDING', 'https://onboarding.gapshop.net');
define('GAPSHOP_PORTAL',     'https://gapshop.net');
require_once plugin_dir_path(__FILE__) . 'gapshop-otp.php';
define('GAPSHOP_VERSION',    '1.0.30');

add_filter('pre_set_site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) return $transient;

    $response = wp_remote_get(
        'https://raw.githubusercontent.com/CCancelo/gapshop-wordpress-plugin/main/version.json',
        ['timeout' => 10]
    );

    if (is_wp_error($response)) return $transient;

    $data = json_decode(wp_remote_retrieve_body($response));
    if (empty($data->version)) return $transient;

    $plugin_file = plugin_basename(__FILE__);
    $current_version = $transient->checked[$plugin_file] ?? '';

    if (version_compare($data->version, $current_version, '>')) {
        $transient->response[$plugin_file] = (object)[
            'slug'        => 'gapshop',
            'plugin'      => $plugin_file,
            'new_version' => $data->version,
            'url'         => 'https://gapshop.net',
            'package'     => $data->download_url,
        ];
    }

    return $transient;
});

add_filter('site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) return $transient;

    $response = wp_remote_get(
        'https://raw.githubusercontent.com/CCancelo/gapshop-wordpress-plugin/main/version.json',
        ['timeout' => 10]
    );

    if (is_wp_error($response)) return $transient;

    $data = json_decode(wp_remote_retrieve_body($response));
    if (empty($data->version)) return $transient;

    $plugin_file = plugin_basename(__FILE__);
    $current_version = $transient->checked[$plugin_file] ?? '';

    if (version_compare($data->version, $current_version, '>')) {
        $transient->response[$plugin_file] = (object)[
            'slug'        => 'gapshop',
            'plugin'      => $plugin_file,
            'new_version' => $data->version,
            'url'         => 'https://gapshop.net',
            'package'     => $data->download_url,
        ];
    }

    return $transient;
});

add_filter('plugins_api', function($result, $action, $args) {
    if ($action !== 'plugin_information' || $args->slug !== 'gapshop') return $result;

    $response = wp_remote_get(
        'https://raw.githubusercontent.com/CCancelo/gapshop-wordpress-plugin/main/version.json',
        ['timeout' => 10]
    );

    if (is_wp_error($response)) return $result;

    $data = json_decode(wp_remote_retrieve_body($response));
    if (empty($data)) return $result;

    return (object)[
        'name'          => 'gapShop',
        'slug'          => 'gapshop',
        'version'       => $data->version,
        'requires'      => $data->requires,
        'tested'        => $data->tested,
        'download_link' => $data->download_url,
        'sections'      => (array)$data->sections,
    ];
}, 10, 3);

// REMOVE THIS IN PRODUCTION — forces fresh check on every admin load
add_action('admin_init', function() {
    if (is_admin() && current_user_can('update_plugins')) {
        delete_site_transient('update_plugins');
    }
});

// ─── Activation ───────────────────────────────────────────────────────────────

register_activation_hook(__FILE__, 'gapshop_activate');

function gapshop_activate() {
    add_option('gapshop_do_activation_redirect', true);
    add_option('gapshop_secret', '');
    add_option('gapshop_connected', false);

    $pages = [
        'shop'     => ['title' => 'Shop',     'content' => '[gapshop_catalog]'],
        'product'  => ['title' => 'Product',  'content' => '[gapshop_product]'],
        'cart'     => ['title' => 'Cart',     'content' => '[gapshop_cart]'],
        'checkout' => ['title' => 'Checkout', 'content' => '[gapshop_checkout]'],
        'account'  => ['title' => 'Account',  'content' => '[gapshop_account]'],
    ];

    foreach ($pages as $slug => $page) {
        if (!get_page_by_path($slug)) {
            wp_insert_post([
                'post_title'   => $page['title'],
                'post_content' => $page['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $slug,
            ]);
        }
    }

    flush_rewrite_rules();
}

add_action('admin_init', function () {
    if (get_option('gapshop_do_activation_redirect', false)) {
        delete_option('gapshop_do_activation_redirect');
        wp_safe_redirect(admin_url('admin.php?page=gapshop-portal'));
        exit;
    }
});

add_action('admin_init', 'gapshop_handle_connect_callback');
function gapshop_handle_connect_callback() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'gapshop-settings') return;
    if (!isset($_GET['gs_connect']) || $_GET['gs_connect'] !== '1') return;
    if (!isset($_GET['gs_secret'])) return;
    if (!current_user_can('manage_options')) return;

    $secret = sanitize_text_field($_GET['gs_secret']);
    update_option('gapshop_secret', $secret);

    $result = gapshop_api_get('/api/status');
    if (!is_wp_error($result) && isset($result['name'])) {
        update_option('gapshop_connected', true);
        update_option('gapshop_tenant_name', $result['name']);
    }

    wp_safe_redirect(admin_url('admin.php?page=gapshop-settings&gs_connected=1'));
    exit;
}

add_action('admin_notices', 'gapshop_connect_success_notice');
function gapshop_connect_success_notice() {
    if (!isset($_GET['gs_connected']) || $_GET['gs_connected'] !== '1') return;
    if (!get_option('gapshop_connected')) return;
    $name = get_option('gapshop_tenant_name', 'your store');
    echo '<div class="notice notice-success is-dismissible"><p>'
        . '🎉 <strong>gapShop connected!</strong> Successfully linked to <strong>'
        . esc_html($name) . '</strong>.</p></div>';
}

// ─── Admin Menu ───────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_menu_page('gapShop', 'gapShop', 'manage_options',
        'gapshop-portal', 'gapshop_portal_page', 'dashicons-cart', 30);

    add_submenu_page('gapshop-portal', 'Dashboard', 'Dashboard',
        'manage_options', 'gapshop-portal', 'gapshop_portal_page');

    add_submenu_page('gapshop-portal', 'Settings', 'Settings',
        'manage_options', 'gapshop-settings', 'gapshop_settings_page');
});

// ─── Dashboard Page ───────────────────────────────────────────────────────────

function gapshop_portal_page() {
    $secret    = get_option('gapshop_secret', '');
    $connected = get_option('gapshop_connected', false);

    if (empty($secret)) {
        $return_url = admin_url('admin.php?page=gapshop-settings&gs_connect=1');
        $onboarding_url = GAPSHOP_ONBOARDING . '/?shopUrl=' . urlencode(get_site_url())
            . '&returnUrl=' . urlencode($return_url);
        ?>
        <div class="wrap">
            <h1>gapShop</h1>
            <div class="card" style="padding:20px;max-width:500px">
                <h2>Get Started with gapShop</h2>
                <p>Create your free gapShop account to add eCommerce to this site.</p>
                <a href="<?php echo esc_url($onboarding_url); ?>"
                   class="button button-primary button-large"
                   target="_blank" rel="noopener noreferrer">
                    Create gapShop Account →
                </a>
                <hr>
                <p class="description">
                    Already have an account?
                    <a href="<?php echo admin_url('admin.php?page=gapshop-settings'); ?>">
                        Enter your secret key in Settings
                    </a>.
                </p>
            </div>
        </div>
        <?php
        return;
    }

    $data     = gapshop_api_get('/api/dashboard');
    $has_data = !is_wp_error($data) && isset($data['stats']);
    $stats    = $has_data ? $data['stats']        : null;
    $orders   = $has_data ? $data['recentOrders'] : [];

    $portal_url = GAPSHOP_PORTAL . '/portal?shopUrl='
        . urlencode(get_site_url())
        . '&secret=' . urlencode($secret);
    ?>
    <div class="wrap">
        <h1>
            <span style="color:#3f51b5">gap</span>Shop
            <?php if ($connected): ?>
                <span class="dashicons dashicons-yes-alt" style="color:green;font-size:20px;vertical-align:middle"></span>
            <?php else: ?>
                <span class="dashicons dashicons-warning" style="color:orange;font-size:20px;vertical-align:middle"></span>
            <?php endif; ?>
        </h1>

        <?php if (!$connected): ?>
        <div class="notice notice-warning inline">
            <p>Connection issue. <a href="<?php echo admin_url('admin.php?page=gapshop-settings'); ?>">Check your secret key</a>.</p>
        </div>
        <?php endif; ?>

        <?php if ($has_data): ?>

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:16px 0">
            <?php
            $stat_items = [
                ['label' => 'Total Orders',  'value' => $stats['totalOrders'],                          'icon' => 'dashicons-cart',       'color' => '#3f51b5'],
                ['label' => 'Revenue',        'value' => '$' . number_format($stats['totalRevenue'], 2), 'icon' => 'dashicons-money-alt',  'color' => '#4caf50'],
                ['label' => 'Products',       'value' => $stats['totalProducts'],                        'icon' => 'dashicons-products',   'color' => '#ff9800'],
                ['label' => 'Pending Orders', 'value' => $stats['pendingOrders'],                        'icon' => 'dashicons-clock',      'color' => '#f44336'],
            ];
            foreach ($stat_items as $item): ?>
            <div class="card" style="padding:16px 20px;text-align:center">
                <span class="dashicons <?php echo $item['icon']; ?>"
                      style="font-size:28px;color:<?php echo $item['color']; ?>;display:block;margin-bottom:6px"></span>
                <div style="font-size:1.6rem;font-weight:700;line-height:1;color:<?php echo $item['color']; ?>">
                    <?php echo esc_html($item['value']); ?>
                </div>
                <div style="font-size:0.8rem;color:#666;margin-top:4px"><?php echo $item['label']; ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 320px;gap:16px;margin-top:8px">

            <div class="card" style="padding:0">
                <div style="padding:12px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center">
                    <strong>Recent Orders</strong>
                    <a href="<?php echo esc_url($portal_url); ?>" target="_blank" rel="noopener noreferrer" style="font-size:0.82rem">View all →</a>
                </div>
                <?php if (empty($orders)): ?>
                    <p style="padding:24px;text-align:center;color:#aaa">No orders yet</p>
                <?php else: ?>
                <table class="widefat striped" style="border:none">
                    <thead>
                        <tr>
                            <th>Order</th><th>Customer</th><th>Status</th><th>Total</th><th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order):
                            $status_colors = [
                                'completed'  => '#4caf50',
                                'processing' => '#3f51b5',
                                'pending'    => '#ff9800',
                                'cancelled'  => '#f44336',
                            ];
                            $color = $status_colors[$order['status']] ?? '#aaa';
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($order['gapOrderNumber']); ?></strong></td>
                            <td><?php echo esc_html($order['customerEmail'] ?? 'Guest'); ?></td>
                            <td>
                                <span style="background:<?php echo $color; ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.75rem">
                                    <?php echo esc_html($order['status']); ?>
                                </span>
                            </td>
                            <td>$<?php echo number_format($order['total'], 2); ?></td>
                            <td><?php echo date('M j', strtotime($order['createdAt'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div style="display:flex;flex-direction:column;gap:16px">
                <div class="card" style="padding:16px">
                    <strong style="display:block;margin-bottom:12px">Quick Links</strong>
                    <a href="<?php echo esc_url($portal_url); ?>"
                       class="button button-primary button-large"
                       style="width:100%;text-align:center;margin-bottom:8px;display:block"
                       target="_blank" rel="noopener noreferrer">
                        <span class="dashicons dashicons-dashboard" style="vertical-align:middle"></span>
                        Open gapShop Portal
                    </a>
                </div>

                <div class="card" style="padding:16px">
                    <strong style="display:block;margin-bottom:12px">Storefront Shortcodes</strong>
                    <table style="width:100%;border-collapse:collapse;font-size:0.82rem">
                        <?php foreach ([
                            ['[gapshop_catalog]',            'Product catalog with filters'],
                            ['[gapshop_product slug="x"]',   'Single product detail'],
                            ['[gapshop_category slug="x"]',  'Category listing'],
                            ['[gapshop_cart]',               'Cart page'],
                            ['[gapshop_checkout]',           'Checkout form'],
                            ['[gapshop_account]',            'Customer login & orders'],
                            ['[gapshop_minicart]',           'Cart icon for nav'],
                        ] as [$code, $desc]): ?>
                        <tr style="border-bottom:1px solid #f0f0f0">
                            <td style="padding:6px 8px"><code><?php echo esc_html($code); ?></code></td>
                            <td style="padding:6px 8px;color:#666"><?php echo esc_html($desc); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="notice notice-info inline" style="margin-top:16px">
            <p>Could not load dashboard data. <a href="<?php echo admin_url('admin.php?page=gapshop-settings'); ?>">Check your connection</a>.</p>
        </div>
        <div class="card" style="padding:20px;max-width:500px;margin-top:16px">
            <p>Manage your store, orders, and settings from the gapShop Portal.</p>
            <a href="<?php echo esc_url($portal_url); ?>" class="button button-primary button-large" target="_blank" rel="noopener noreferrer">
                Open gapShop Portal →
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// ─── Settings Page ────────────────────────────────────────────────────────────

function gapshop_settings_page() {
    if (isset($_POST['gapshop_save_settings'])) {
        check_admin_referer('gapshop_settings');
        $secret = sanitize_text_field($_POST['gapshop_secret']);
        update_option('gapshop_secret', $secret);
        update_option('gapshop_shop_secret', $secret);
        update_option('gapshop_api_url', GAPSHOP_API);
        update_option('gapshop_shop_url', site_url());
        update_option('gapshop_otp_enabled', isset($_POST['gapshop_enable_otp']) ? true : false);

        $result = gapshop_api_get('/api/status');
        if (!is_wp_error($result) && isset($result['name'])) {
            update_option('gapshop_connected', true);
            update_option('gapshop_tenant_name', $result['name']);
            echo '<div class="notice notice-success"><p>&#10003; Connected to <strong>'
                . esc_html($result['name']) . '</strong>.</p></div>';
        } else {
            update_option('gapshop_connected', false);
            $msg = is_wp_error($result) ? $result->get_error_message() : 'Invalid secret key.';
            echo '<div class="notice notice-error"><p>Connection failed: ' . esc_html($msg) . '</p></div>';
        }
    }

    $secret    = get_option('gapshop_secret', '');
    $connected = get_option('gapshop_connected', false);
    ?>
    
    <div class="wrap">
        <h1>gapShop Settings</h1>
        <form method="post">
            <?php wp_nonce_field('gapshop_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>Connection Status</th>
                    <td>
                        <?php if ($connected): ?>
                            <span style="color:green">&#10003; Connected to <?php echo esc_html(get_option('gapshop_tenant_name')); ?></span>
                        <?php else: ?>
                            <span style="color:red">&#10007; Not connected</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="gapshop_secret">Secret Key</label></th>
                    <td>
                        <input type="password" id="gapshop_secret" name="gapshop_secret"
                               value="<?php echo esc_attr($secret); ?>" class="regular-text" />
                        <p class="description">
                            Found on your gapShop onboarding success page.
                            <a href="<?php echo esc_url(GAPSHOP_ONBOARDING . '?shopUrl=' . urlencode(get_site_url())); ?>" target="_blank">Create an account</a> if you don't have one.
                        </p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="gapshop_save_settings" class="button button-primary" value="Save & Test Connection" />
            </p>
        </form>
    </div>
    <?php
}

// ─── API Helpers ──────────────────────────────────────────────────────────────

function gapshop_api_post($endpoint, $payload) {
    $response = wp_remote_post(GAPSHOP_API . $endpoint, [
        'timeout' => 30,
        'headers' => [
            'Content-Type'  => 'application/json',
            'X-Shop-Url'    => get_site_url(),
            'X-Shop-Secret' => get_option('gapshop_secret', ''),
        ],
        'body' => json_encode($payload),
    ]);

    if (is_wp_error($response)) return $response;
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code >= 400)
        return new WP_Error('api_error', "API returned $code: " . wp_remote_retrieve_body($response));
    return $body;
}

function gapshop_api_get($endpoint) {
    $response = wp_remote_get(GAPSHOP_API . $endpoint, [
        'timeout' => 30,
        'headers' => [
            'X-Shop-Url'    => get_site_url(),
            'X-Shop-Secret' => get_option('gapshop_secret', ''),
        ],
    ]);

    if (is_wp_error($response)) return $response;
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code >= 400)
        return new WP_Error('api_error', "API returned $code: " . wp_remote_retrieve_body($response));
    return $body;
}

// ═══════════════════════════════════════════════════════════════════════════════
// Storefront Shortcodes
// ═══════════════════════════════════════════════════════════════════════════════

function gapshop_store_get($endpoint, $args = []) {
    $url = GAPSHOP_API . '/api/store/' . ltrim($endpoint, '/');
    if (!empty($args)) $url .= '?' . http_build_query($args);

    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'X-Tenant-Domain' => parse_url(get_site_url(), PHP_URL_HOST),
            'Accept'          => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) return null;
    if (wp_remote_retrieve_response_code($response) >= 400) return null;
    return json_decode(wp_remote_retrieve_body($response), true);
}

// ─── Styles ───────────────────────────────────────────────────────────────────

add_action('wp_head', 'gapshop_storefront_styles');
function gapshop_storefront_styles() {
    if (!get_option('gapshop_connected')) return;
    ?>
    <style>
    .gapshop-wrap { max-width:1200px; margin:0 auto; padding:0 16px; }
    .gapshop-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:20px; }
    .gapshop-card { background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.08); text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:box-shadow .2s,transform .2s; }
    .gapshop-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.12); transform:translateY(-2px); }
    .gapshop-card img { width:100%; aspect-ratio:1; object-fit:cover; background:#f0f0f0; }
    .gapshop-card-placeholder { width:100%; aspect-ratio:1; background:#f0f0f0; display:flex; align-items:center; justify-content:center; font-size:3rem; color:#ccc; }
    .gapshop-card-body { padding:12px 14px 16px; flex:1; display:flex; flex-direction:column; }
    .gapshop-card-cat { font-size:.72rem; color:#aaa; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
    .gapshop-card-name { font-size:.9rem; font-weight:600; margin:0 0 6px; }
    .gapshop-card-price { font-size:1rem; font-weight:700; margin-top:auto; }
    .gapshop-sale { color:#e53935; }
    .gapshop-original { text-decoration:line-through; color:#aaa; font-size:.82rem; margin-left:4px; }
    .gapshop-filters { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; align-items:center; }
    .gapshop-filters input, .gapshop-filters select { padding:8px 12px; border:1px solid #ddd; border-radius:6px; font-size:.88rem; }
    .gapshop-btn { display:inline-block; padding:10px 24px; border-radius:6px; font-size:.9rem; font-weight:600; cursor:pointer; border:none; text-decoration:none; transition:opacity .15s; }
    .gapshop-btn-primary { background:#1565c0; color:#fff; }
    .gapshop-btn-primary:hover { opacity:.88; color:#fff; }
    .gapshop-btn-outline { background:transparent; color:#1565c0; border:2px solid #1565c0; }
    .gapshop-btn-outline:hover { background:#1565c0; color:#fff; }
    .gapshop-minicart { display:inline-flex; align-items:center; gap:6px; text-decoration:none; color:inherit; }
    .gapshop-cart-count { background:#e53935; color:#fff; border-radius:50%; min-width:18px; height:18px; font-size:.7rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; padding:0 3px; }
    .gapshop-product-wrap { display:grid; grid-template-columns:1fr 1fr; gap:40px; align-items:start; }
    .gapshop-product-wrap img { width:100%; border-radius:8px; }
    .gapshop-qty-wrap { display:flex; align-items:center; border:1px solid #ddd; border-radius:6px; overflow:hidden; }
    .gapshop-qty-btn { padding:8px 14px; background:none; border:none; font-size:1.1rem; cursor:pointer; }
    .gapshop-qty-input { width:48px; text-align:center; border:none; font-size:.95rem; padding:8px 0; }
    .gapshop-cart-table { width:100%; border-collapse:collapse; }
    .gapshop-cart-table td { padding:12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
    .gapshop-summary { background:#f8f9fa; border-radius:8px; padding:20px; }
    .gapshop-loading { text-align:center; padding:40px; color:#aaa; }
    .gapshop-msg-error { background:#ffebee; border-left:4px solid #e53935; padding:12px 16px; border-radius:4px; color:#c62828; margin-bottom:12px; }
    .gapshop-msg-success { background:#e8f5e9; border-left:4px solid #43a047; padding:12px 16px; border-radius:4px; color:#2e7d32; margin-bottom:12px; }
    .gapshop-field { padding:10px 12px; border:1px solid #ddd; border-radius:6px; font-size:.9rem; width:100%; box-sizing:border-box; margin-bottom:12px; }
    @media(max-width:768px) {
        .gapshop-product-wrap { grid-template-columns:1fr; }
        .gapshop-grid { grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); }
    }
    </style>
    <?php
}

// ─── Cart JS ──────────────────────────────────────────────────────────────────

add_action('wp_footer', 'gapshop_cart_js');
function gapshop_cart_js() {
    if (!get_option('gapshop_connected')) return;
    ?>
    <script>
    window.gapShopCart = {
        get: function() {
            try { return JSON.parse(localStorage.getItem('gapshop_cart') || '{"items":[]}'); }
            catch(e) { return {items:[]}; }
        },
        save: function(c) {
            localStorage.setItem('gapshop_cart', JSON.stringify(c));
            document.dispatchEvent(new Event('gapshop:cart:updated'));
        },
        count: function() { return this.get().items.reduce(function(s,i){ return s+i.quantity; }, 0); },
        subtotal: function() { return this.get().items.reduce(function(s,i){ return s+(i.unitPrice*i.quantity); }, 0); },
        add: function(product, qty, variantId, variantLabel, price) {
            var c = this.get();
            var key = product.id + (variantId ? '-'+variantId : '');
            var ex = c.items.find(function(i){ return i.key===key; });
            if (ex) { ex.quantity += qty; }
            else {
                c.items.push({
                    key: key, productId: product.id, variantId: variantId||null,
                    name: product.name + (variantLabel ? ' – '+variantLabel : ''),
                    imageUrl: product.imageUrl, slug: product.slug,
                    unitPrice: price, quantity: qty
                });
            }
            this.save(c);
        },
        remove: function(key) {
            var c = this.get();
            c.items = c.items.filter(function(i){ return i.key !== key; });
            this.save(c);
        },
        update: function(key, qty) {
            var c = this.get();
            var item = c.items.find(function(i){ return i.key === key; });
            if (item) { if (qty <= 0) { this.remove(key); } else { item.quantity = qty; this.save(c); } }
        },
        clear: function() { this.save({items:[]}); }
    };

    function gs_sync_counts() {
        var n = window.gapShopCart.count();
        document.querySelectorAll('.gapshop-cart-count').forEach(function(el) {
            el.textContent = n;
            el.style.display = n > 0 ? 'inline-flex' : 'none';
        });
    }
    document.addEventListener('DOMContentLoaded', gs_sync_counts);
    document.addEventListener('gapshop:cart:updated', gs_sync_counts);
    </script>
    <?php
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function gapshop_price_html($p) {
    if ($p['onSale'] && !empty($p['salePrice'])) {
        return '<span class="gapshop-sale">$'.number_format($p['salePrice'],2).'</span>'
             . '<span class="gapshop-original">$'.number_format($p['basePrice'],2).'</span>';
    }
    return '$'.number_format($p['basePrice'],2);
}

function gapshop_product_url($slug, $base = '') {
    if (empty($base)) {
        $page = get_page_by_path('product');
        $base = $page ? trailingslashit(get_permalink($page)) : home_url('/product/');
    }
    return $base . $slug;
}

function gapshop_product_card_html($p, $product_page_base) {
    $url = gapshop_product_url($p['slug'], $product_page_base);
    $img = !empty($p['imageUrl'])
        ? '<img src="'.esc_url($p['imageUrl']).'" alt="'.esc_attr($p['name']).'" loading="lazy" />'
        : '<div class="gapshop-card-placeholder">📦</div>';
    $cat = !empty($p['categories'])
        ? '<div class="gapshop-card-cat">'.esc_html($p['categories'][0]['name']).'</div>'
        : '';
    return '<a href="'.esc_url($url).'" class="gapshop-card">'.$img
        .'<div class="gapshop-card-body">'.$cat
        .'<div class="gapshop-card-name">'.esc_html($p['name']).'</div>'
        .'<div class="gapshop-card-price">'.gapshop_price_html($p).'</div>'
        .'</div></a>';
}

// ─── Rewrite rules ────────────────────────────────────────────────────────────

add_action('init', 'gapshop_rewrite_rules');
function gapshop_rewrite_rules() {
    add_rewrite_rule('^product/([^/]+)/?$',
        'index.php?pagename=product&gapshop_slug=$matches[1]', 'top');
}
add_filter('query_vars', function($v) { $v[] = 'gapshop_slug'; return $v; });

// ─── [gapshop_minicart] ───────────────────────────────────────────────────────

add_shortcode('gapshop_minicart', 'gapshop_sc_minicart');
function gapshop_sc_minicart($atts) {
    $atts = shortcode_atts(['cart_page' => 'cart'], $atts);
    $page = get_page_by_path($atts['cart_page']);
    $url  = $page ? get_permalink($page) : home_url('/'.$atts['cart_page']);
    return '<a href="'.esc_url($url).'" class="gapshop-minicart">'
         . '<span style="font-size:1.4rem">🛒</span>'
         . '<span class="gapshop-cart-count" style="display:none">0</span>'
         . '</a>';
}

// ─── [gapshop_catalog] ────────────────────────────────────────────────────────

add_shortcode('gapshop_catalog', 'gapshop_sc_catalog');
function gapshop_sc_catalog($atts) {
    $atts = shortcode_atts([
        'category'     => '',
        'per_page'     => 24,
        'show_filters' => 'yes',
        'product_page' => 'product',
    ], $atts);

    $category    = sanitize_text_field($_GET['gs_cat']    ?? $atts['category']);
    $search      = sanitize_text_field($_GET['gs_search'] ?? '');
    $sort        = sanitize_text_field($_GET['gs_sort']   ?? '');
    $page        = max(1, intval($_GET['gs_page']         ?? 1));
    $current_url = get_permalink();
    $prod_page   = get_page_by_path($atts['product_page']);
    $prod_base   = $prod_page
        ? trailingslashit(get_permalink($prod_page))
        : home_url('/'.$atts['product_page'].'/');

    $data = gapshop_store_get('products', [
        'category' => $category, 'search' => $search,
        'sort'     => $sort,     'page'   => $page,
        'pageSize' => intval($atts['per_page']),
    ]);
    $cats = gapshop_store_get('categories') ?: [];

    ob_start();
    if (!$data) {
        echo '<p class="gapshop-msg-error">Unable to load products. Please try again later.</p>';
        return ob_get_clean();
    }
    ?>
    <div class="gapshop-wrap">
        <?php if ($atts['show_filters'] === 'yes'): ?>
        <form class="gapshop-filters" method="get" action="<?php echo esc_url($current_url); ?>">
            <input type="text" name="gs_search" value="<?php echo esc_attr($search); ?>"
                   placeholder="Search products..." style="flex:1;min-width:160px" />
            <select name="gs_cat">
                <option value="">All Categories</option>
                <?php foreach ($cats as $c): ?>
                <option value="<?php echo esc_attr($c['slug']); ?>" <?php selected($category, $c['slug']); ?>>
                    <?php echo esc_html($c['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="gs_sort">
                <option value="">Sort: Default</option>
                <option value="newest"     <?php selected($sort,'newest'); ?>>Newest</option>
                <option value="price_asc"  <?php selected($sort,'price_asc'); ?>>Price: Low–High</option>
                <option value="price_desc" <?php selected($sort,'price_desc'); ?>>Price: High–Low</option>
                <option value="name_asc"   <?php selected($sort,'name_asc'); ?>>Name A–Z</option>
            </select>
            <button type="submit" class="gapshop-btn gapshop-btn-primary">Filter</button>
            <?php if ($search || $category || $sort): ?>
            <a href="<?php echo esc_url($current_url); ?>" class="gapshop-btn gapshop-btn-outline">Clear</a>
            <?php endif; ?>
        </form>
        <?php endif; ?>

        <p style="font-size:.85rem;color:#888;margin-bottom:16px">
            <?php echo intval($data['total'] ?? 0); ?> products
        </p>

        <?php if (empty($data['products'])): ?>
            <p style="text-align:center;padding:40px;color:#aaa">No products found.</p>
        <?php else: ?>
        <div class="gapshop-grid">
            <?php foreach ($data['products'] as $p) echo gapshop_product_card_html($p, $prod_base); ?>
        </div>

        <?php $total_pages = intval($data['totalPages'] ?? 1);
        if ($total_pages > 1): ?>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:32px;flex-wrap:wrap">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="<?php echo esc_url(add_query_arg('gs_page', $i, $current_url)); ?>"
               style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:6px;text-decoration:none;font-weight:600;font-size:.88rem;<?php echo $i===$page ? 'background:#1565c0;color:#fff;' : 'border:1px solid #ddd;color:#555;'; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ─── [gapshop_category] ──────────────────────────────────────────────────────

add_shortcode('gapshop_category', 'gapshop_sc_category');
function gapshop_sc_category($atts) {
    $atts = shortcode_atts(['slug' => '', 'per_page' => 24, 'product_page' => 'product'], $atts);
    if (empty($atts['slug'])) return '<p class="gapshop-msg-error">Please specify a category slug.</p>';
    $_GET['gs_cat'] = $atts['slug'];
    return gapshop_sc_catalog([
        'category'     => $atts['slug'],
        'per_page'     => $atts['per_page'],
        'show_filters' => 'yes',
        'product_page' => $atts['product_page'],
    ]);
}

// ─── [gapshop_product] ───────────────────────────────────────────────────────

add_shortcode('gapshop_product', 'gapshop_sc_product');
function gapshop_sc_product($atts) {
    $atts = shortcode_atts(['slug' => ''], $atts);
    $slug = $atts['slug'] ?: sanitize_text_field(get_query_var('gapshop_slug', ''));
    if (empty($slug)) return '<p class="gapshop-msg-error">Product not found.</p>';

    $p = gapshop_store_get('products/' . $slug);
    if (!$p || isset($p['error'])) return '<p class="gapshop-msg-error">Product not found.</p>';

    $price = ($p['onSale'] && !empty($p['salePrice'])) ? $p['salePrice'] : $p['basePrice'];
    ob_start(); ?>
    <div class="gapshop-wrap">
        <div class="gapshop-product-wrap">
            <div>
                <?php if (!empty($p['imageUrl'])): ?>
                <img id="gs-main-img" src="<?php echo esc_url($p['imageUrl']); ?>" alt="<?php echo esc_attr($p['name']); ?>" />
                <?php else: ?>
                <div style="width:100%;aspect-ratio:1;background:#f0f0f0;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:4rem">📦</div>
                <?php endif; ?>
            </div>
            <div>
                <?php if (!empty($p['categories'])): ?>
                <div style="font-size:.75rem;color:#aaa;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">
                    <?php echo esc_html(implode(', ', array_column($p['categories'], 'name'))); ?>
                </div>
                <?php endif; ?>
                <h1 style="font-size:1.6rem;font-weight:800;margin:0 0 12px;line-height:1.2"><?php echo esc_html($p['name']); ?></h1>
                <div style="margin-bottom:16px;font-size:1.8rem;font-weight:700"><?php echo gapshop_price_html($p); ?></div>
                <?php if (!empty($p['shortDescription'])): ?>
                <p style="color:#555;line-height:1.6;margin:0 0 16px"><?php echo esc_html($p['shortDescription']); ?></p>
                <?php endif; ?>
                <div style="margin-bottom:16px;font-size:.88rem;font-weight:600">
                    <?php echo $p['inStock'] ? '<span style="color:#43a047">✓ In Stock</span>' : '<span style="color:#e53935">✗ Out of Stock</span>'; ?>
                </div>
                <?php if (!empty($p['variants'])): ?>
                <div style="margin-bottom:16px">
                    <label style="font-size:.85rem;font-weight:600;display:block;margin-bottom:6px">Options</label>
                    <select id="gs-variant" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:.88rem;max-width:240px">
                        <option value="">Select an option</option>
                        <?php foreach ($p['variants'] as $v): ?>
                        <option value="<?php echo esc_attr($v['id']); ?>" data-price="<?php echo esc_attr($v['salePrice'] ?? $v['price']); ?>" data-label="<?php echo esc_attr($v['attributes'] ?? ''); ?>">
                            <?php echo esc_html($v['attributes'] ?? 'Variant '.$v['id']); ?> — $<?php echo number_format($v['salePrice'] ?? $v['price'], 2); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div style="display:flex;gap:12px;align-items:center;margin-bottom:20px">
                    <div class="gapshop-qty-wrap">
                        <button type="button" class="gapshop-qty-btn" onclick="gsQty(-1)">−</button>
                        <input type="number" id="gs-qty" class="gapshop-qty-input" value="1" min="1" max="<?php echo intval($p['stockQuantity']); ?>" />
                        <button type="button" class="gapshop-qty-btn" onclick="gsQty(1)">+</button>
                    </div>
                    <?php if ($p['inStock']): ?>
                    <button onclick="gsAddCart(<?php echo esc_js(json_encode($p)); ?>, <?php echo esc_js($price); ?>)" class="gapshop-btn gapshop-btn-primary" style="flex:1">🛒 Add to Cart</button>
                    <?php else: ?>
                    <button class="gapshop-btn gapshop-btn-primary" disabled style="flex:1;opacity:.5">Out of Stock</button>
                    <?php endif; ?>
                </div>
                <?php if (!empty($p['sku'])): ?>
                <p style="font-size:.78rem;color:#aaa;margin:0 0 16px">SKU: <?php echo esc_html($p['sku']); ?></p>
                <?php endif; ?>
                <?php if (!empty($p['features'])): ?>
                <div>
                    <h4 style="font-size:.88rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px">Features</h4>
                    <?php foreach ($p['features'] as $f): ?>
                    <div style="display:flex;gap:8px;font-size:.88rem;margin-bottom:4px">
                        <span style="color:#1565c0">✓</span>
                        <span><strong><?php echo esc_html($f['name']); ?></strong><?php if (!empty($f['details'])): ?> — <?php echo esc_html($f['details']); ?><?php endif; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($p['description'])): ?>
        <div style="margin-top:40px;padding-top:28px;border-top:1px solid #f0f0f0">
            <h3 style="font-size:1.1rem;font-weight:700;margin:0 0 16px">Product Description</h3>
            <div style="color:#555;line-height:1.8"><?php echo wp_kses_post($p['description']); ?></div>
        </div>
        <?php endif; ?>
    </div>
    <div id="gs-atc-toast" style="display:none;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:12px 24px;border-radius:8px;z-index:9999;font-size:.9rem"></div>
    <script>
    function gsQty(d) {
        var i = document.getElementById('gs-qty');
        i.value = Math.max(1, Math.min(parseInt(i.max)||999, parseInt(i.value)+d));
    }
    function gsAddCart(p, base) {
        var qty = parseInt(document.getElementById('gs-qty').value);
        var sel = document.getElementById('gs-variant');
        var vid = null, vlabel = '', price = base;
        if (sel && sel.value) {
            var o = sel.options[sel.selectedIndex];
            vid = sel.value; vlabel = o.dataset.label||''; price = parseFloat(o.dataset.price)||base;
        }
        window.gapShopCart.add(p, qty, vid, vlabel, price);
        var t = document.getElementById('gs-atc-toast');
        t.textContent = p.name + ' added to cart!';
        t.style.display = 'block';
        setTimeout(function(){ t.style.display='none'; }, 2500);
    }
    </script>
    <?php
    return ob_get_clean();
}

// ─── [gapshop_cart] ──────────────────────────────────────────────────────────

add_shortcode('gapshop_cart', 'gapshop_sc_cart');
function gapshop_sc_cart($atts) {
    $atts = shortcode_atts(['checkout_page' => 'checkout'], $atts);
    $page = get_page_by_path($atts['checkout_page']);
    $checkout_url = $page ? get_permalink($page) : home_url('/'.$atts['checkout_page']);
    ob_start(); ?>
    <div class="gapshop-wrap" id="gs-cart-wrap">
        <div class="gapshop-loading">Loading cart...</div>
    </div>
    <script>
    (function(){
        var chkUrl = <?php echo json_encode($checkout_url); ?>;
        function render() {
            var cart = window.gapShopCart.get();
            var wrap = document.getElementById('gs-cart-wrap');
            if (!cart.items.length) {
                wrap.innerHTML = '<div style="text-align:center;padding:60px;color:#aaa"><div style="font-size:4rem">🛒</div><p style="margin-top:12px">Your cart is empty.</p><a href="/" class="gapshop-btn gapshop-btn-primary" style="margin-top:12px">Continue Shopping</a></div>';
                return;
            }
            var sub  = window.gapShopCart.subtotal();
            var rows = cart.items.map(function(i) {
                return '<tr>'
                    + '<td style="display:flex;align-items:center;gap:12px">'
                    + (i.imageUrl ? '<img src="'+i.imageUrl+'" style="width:60px;height:60px;object-fit:cover;border-radius:4px"/>' : '<div style="width:60px;height:60px;background:#f0f0f0;border-radius:4px"></div>')
                    + '<div><div style="font-weight:600;font-size:.9rem">'+i.name+'</div><div style="font-size:.78rem;color:#888">$'+i.unitPrice.toFixed(2)+' each</div></div></td>'
                    + '<td style="text-align:center"><div class="gapshop-qty-wrap" style="display:inline-flex">'
                    + '<button class="gapshop-qty-btn" onclick="gsCartUpd(\''+i.key+'\','+(i.quantity-1)+')">−</button>'
                    + '<span style="padding:0 12px;font-weight:600">'+i.quantity+'</span>'
                    + '<button class="gapshop-qty-btn" onclick="gsCartUpd(\''+i.key+'\','+(i.quantity+1)+')">+</button>'
                    + '</div></td>'
                    + '<td style="text-align:right;font-weight:700">$'+(i.unitPrice*i.quantity).toFixed(2)+'</td>'
                    + '<td><button onclick="gsCartRm(\''+i.key+'\')" style="background:none;border:none;color:#aaa;cursor:pointer;font-size:1.2rem">×</button></td>'
                    + '</tr>';
            }).join('');
            wrap.innerHTML =
                '<div style="display:grid;grid-template-columns:1fr 300px;gap:32px;align-items:start">'
                + '<div><table class="gapshop-cart-table"><thead><tr>'
                + '<th style="text-align:left;padding:8px 12px;border-bottom:2px solid #f0f0f0">Product</th>'
                + '<th style="text-align:center;padding:8px 12px;border-bottom:2px solid #f0f0f0">Qty</th>'
                + '<th style="text-align:right;padding:8px 12px;border-bottom:2px solid #f0f0f0">Total</th>'
                + '<th style="border-bottom:2px solid #f0f0f0"></th>'
                + '</tr></thead><tbody>'+rows+'</tbody></table>'
                + '<a href="/" style="font-size:.88rem;color:#1565c0;text-decoration:none;display:inline-block;margin-top:16px">← Continue Shopping</a></div>'
                + '<div class="gapshop-summary"><h3 style="margin:0 0 16px;font-size:1rem;font-weight:700">Order Summary</h3>'
                + '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eee;font-size:.88rem"><span>Subtotal</span><span style="font-weight:600">$'+sub.toFixed(2)+'</span></div>'
                + '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eee;font-size:.88rem"><span>Shipping</span><span style="color:#aaa">Calculated at checkout</span></div>'
                + '<div style="display:flex;justify-content:space-between;padding:12px 0;font-size:1rem;font-weight:700"><span>Total</span><span style="color:#1565c0">$'+sub.toFixed(2)+'</span></div>'
                + '<a href="'+chkUrl+'" class="gapshop-btn gapshop-btn-primary" style="display:block;text-align:center;margin-top:12px;padding:14px">Proceed to Checkout</a>'
                + '</div></div>';
        }
        window.gsCartUpd = function(k, q) { window.gapShopCart.update(k, q); render(); };
        window.gsCartRm  = function(k)    { window.gapShopCart.remove(k);    render(); };
        document.addEventListener('DOMContentLoaded', render);
        document.addEventListener('gapshop:cart:updated', render);
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ─── [gapshop_checkout] ──────────────────────────────────────────────────────

add_shortcode('gapshop_checkout', 'gapshop_sc_checkout');
function gapshop_sc_checkout($atts) {
    $atts       = shortcode_atts(['cart_page' => 'cart'], $atts);
    $api_checkout = GAPSHOP_API . '/api/store/checkout';
    $cart_page  = get_page_by_path($atts['cart_page']);
    $cancel_url = $cart_page ? get_permalink($cart_page) : home_url('/cart');

    ob_start(); ?>
    <div class="gapshop-wrap" id="gs-checkout-wrap">
        <div class="gapshop-loading">Loading...</div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var cart = window.gapShopCart.get();
        var wrap = document.getElementById('gs-checkout-wrap');

        if (!cart.items.length) {
            wrap.innerHTML = '<div class="gapshop-msg-error">Your cart is empty. <a href="/">Continue shopping</a>.</div>';
            return;
        }

        var sub   = window.gapShopCart.subtotal();
        var token = localStorage.getItem('gapshop_token');

        wrap.innerHTML =
            '<div style="display:grid;grid-template-columns:1fr 320px;gap:32px;align-items:start">'
            + '<div>'
            + (!token ? '<div style="background:#fff3e0;border-left:4px solid #f57c00;padding:12px 16px;border-radius:4px;margin-bottom:20px;font-size:.88rem">💡 <a href="/account/?redirect='+encodeURIComponent(window.location.href)+'" style="color:#e65100;font-weight:600">Sign in</a> to save your order history.</div>' : '')
            + '<h3 style="margin:0 0 16px;font-size:1rem;font-weight:700">Contact Information</h3>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
            + '<input id="gs-fn" class="gapshop-field" type="text" placeholder="First Name" />'
            + '<input id="gs-ln" class="gapshop-field" type="text" placeholder="Last Name" />'
            + '</div>'
            + '<input id="gs-em" class="gapshop-field" type="email" placeholder="Email Address (optional — for order confirmation)" />'
            + '<input id="gs-ph" class="gapshop-field" type="tel" placeholder="Phone" />'
            + '<h3 style="margin:16px 0;font-size:1rem;font-weight:700">Shipping Address</h3>'
            + '<input id="gs-a1" class="gapshop-field" type="text" placeholder="Address Line 1" />'
            + '<input id="gs-a2" class="gapshop-field" type="text" placeholder="Address Line 2" />'
            + '<div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-bottom:12px">'
            + '<input id="gs-ci" style="padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:.9rem;box-sizing:border-box" type="text" placeholder="City" />'
            + '<input id="gs-st" style="padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:.9rem;box-sizing:border-box" type="text" placeholder="State" maxlength="2" />'
            + '<input id="gs-zp" style="padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:.9rem;box-sizing:border-box" type="text" placeholder="ZIP" />'
            + '</div>'
            + '<button id="gs-place" class="gapshop-btn gapshop-btn-primary" style="width:100%;padding:14px;font-size:1rem" onclick="gsPlaceOrder()">Place Order — $'+sub.toFixed(2)+'</button>'
            + '<div id="gs-co-err" class="gapshop-msg-error" style="display:none;margin-top:12px"></div>'
            + '</div>'
            + '<div class="gapshop-summary">'
            + '<h3 style="margin:0 0 16px;font-size:1rem;font-weight:700">Order Summary</h3>'
            + cart.items.map(function(i){
                return '<div style="display:flex;justify-content:space-between;padding:6px 0;font-size:.85rem;border-bottom:1px solid #f5f5f5"><span>'+i.name+' × '+i.quantity+'</span><span style="font-weight:600">$'+(i.unitPrice*i.quantity).toFixed(2)+'</span></div>';
              }).join('')
            + '<div style="display:flex;justify-content:space-between;padding:12px 0 0;font-size:1rem;font-weight:700;border-top:2px solid #eee;margin-top:8px"><span>Total</span><span style="color:#1565c0">$'+sub.toFixed(2)+'</span></div>'
            + '</div></div>';
    });

    async function gsPlaceOrder() {
        var btn = document.getElementById('gs-place');
        var err = document.getElementById('gs-co-err');
        btn.disabled = true;
        btn.textContent = 'Placing Order...';
        err.style.display = 'none';

        var cart  = window.gapShopCart.get();
        var token = localStorage.getItem('gapshop_token');

        var payload = {
            firstName: document.getElementById('gs-fn').value.trim(),
            lastName:  document.getElementById('gs-ln').value.trim(),
            email:     document.getElementById('gs-em').value.trim(),
            phone:     document.getElementById('gs-ph').value.trim(),
            shippingAddress: {
                line1: document.getElementById('gs-a1').value.trim(),
                line2: document.getElementById('gs-a2').value.trim(),
                city:  document.getElementById('gs-ci').value.trim(),
                state: document.getElementById('gs-st').value.trim(),
                zip:   document.getElementById('gs-zp').value.trim()
            },
            items: cart.items.map(function(i) {
                return {
                    productId: i.productId,
                    variantId: i.variantId || null,
                    name:      i.name,
                    quantity:  i.quantity,
                    unitPrice: i.unitPrice
                };
            })
        };

        var hdrs = {
            'Content-Type':   'application/json',
            'X-Tenant-Domain': window.location.hostname
        };
        if (token) hdrs['Authorization'] = 'Bearer ' + token;

        try {
            var res  = await fetch(<?php echo json_encode($api_checkout); ?>, {
                method:  'POST',
                headers: hdrs,
                body:    JSON.stringify(payload)
            });
            var data = await res.json();

            if (res.ok && data.success) {
                window.gapShopCart.clear();
                // Show inline confirmation
                document.getElementById('gs-checkout-wrap').innerHTML =
                    '<div style="max-width:560px;margin:40px auto;text-align:center;background:#fff;border-radius:12px;padding:40px;box-shadow:0 2px 12px rgba(0,0,0,0.08)">'
                    + '<div style="width:64px;height:64px;background:#e8f5e9;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:2rem;line-height:64px">✓</div>'
                    + '<h2 style="color:#2e7d32;margin:0 0 8px">Order Confirmed!</h2>'
                    + '<p style="color:#888;margin:0 0 8px">Your order <strong>' + data.orderNumber + '</strong> has been placed.</p>'
                    + (payload.email ? '<p style="color:#888;font-size:.88rem;margin:0 0 24px">A confirmation email has been sent to ' + payload.email + '.</p>' : '<p style="color:#888;font-size:.88rem;margin:0 0 24px">Keep your order number for reference.</p>')
                    + '<a href="/" class="gapshop-btn gapshop-btn-primary">Continue Shopping</a>'
                    + '</div>';
            } else {
                showErr(data.error || 'Order failed. Please try again.');
            }
        } catch (e) {
            showErr('Network error. Please try again.');
        }
    }

    function showErr(msg) {
        var btn = document.getElementById('gs-place');
        var err = document.getElementById('gs-co-err');
        btn.disabled    = false;
        btn.textContent = 'Place Order';
        err.textContent = msg;
        err.style.display = 'block';
    }
    </script>
    <?php
    return ob_get_clean();
}

// ─── [gapshop_account] ────────────────────────────────────────────────────────

add_shortcode('gapshop_account', 'gapshop_sc_account');
function gapshop_sc_account($atts) {
    $otp_request_url = GAPSHOP_API . '/api/auth/otp/request';
    $otp_verify_url  = GAPSHOP_API . '/api/auth/otp/verify';
    ob_start(); ?>
    <div class="gapshop-wrap" id="gs-acct-wrap">
        <div class="gapshop-loading">Loading...</div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var token = localStorage.getItem('gapshop_token');
        if (token) gsRenderAccount(); else gsRenderLogin();
    });
    function gsRenderLogin() {
        document.getElementById('gs-acct-wrap').innerHTML =
            '<div style="max-width:400px;margin:0 auto;background:#fff;border-radius:10px;padding:32px;box-shadow:0 1px 8px rgba(0,0,0,.08)">'
            + '<h2 style="margin:0 0 8px;font-size:1.3rem;font-weight:700">Sign In</h2>'
            + '<p style="color:#888;font-size:.88rem;margin:0 0 20px">Enter your email to receive a login code.</p>'
            + '<div id="gs-step1">'
            + '<input id="gs-email" class="gapshop-field" type="email" placeholder="Email address" />'
            + '<button onclick="gsReqOtp()" class="gapshop-btn gapshop-btn-primary" style="width:100%;padding:13px">Send Login Code</button>'
            + '</div>'
            + '<div id="gs-step2" style="display:none">'
            + '<p style="font-size:.88rem;color:#555;margin:0 0 16px">Check your email for a 6-digit code.</p>'
            + '<input id="gs-otp" class="gapshop-field" type="text" maxlength="6" placeholder="000000" style="font-size:1.3rem;letter-spacing:10px;text-align:center" />'
            + '<button onclick="gsVerOtp()" class="gapshop-btn gapshop-btn-primary" style="width:100%;padding:13px">Verify Code</button>'
            + '<button onclick="document.getElementById(\'gs-step1\').style.display=\'block\';document.getElementById(\'gs-step2\').style.display=\'none\';" style="background:none;border:none;color:#aaa;font-size:.82rem;cursor:pointer;margin-top:8px;width:100%">← Use a different email</button>'
            + '</div>'
            + '<div id="gs-acct-err" class="gapshop-msg-error" style="display:none;margin-top:12px"></div>'
            + '</div>';
    }
    async function gsReqOtp() {
        var email = document.getElementById('gs-email').value;
        var err   = document.getElementById('gs-acct-err');
        if (!email) { err.textContent='Email is required.'; err.style.display='block'; return; }
        var res = await fetch(<?php echo json_encode($otp_request_url); ?>, {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'X-Tenant-Domain':window.location.hostname },
            body: JSON.stringify({ email: email })
        });
        if (res.ok) {
            document.getElementById('gs-step1').style.display = 'none';
            document.getElementById('gs-step2').style.display = 'block';
            err.style.display = 'none';
        } else {
            err.textContent = 'Could not send code. Please try again.';
            err.style.display = 'block';
        }
    }
    async function gsVerOtp() {
        var email = document.getElementById('gs-email').value;
        var otp   = document.getElementById('gs-otp').value;
        var err   = document.getElementById('gs-acct-err');
        var res = await fetch(<?php echo json_encode($otp_verify_url); ?>, {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'X-Tenant-Domain':window.location.hostname },
            body: JSON.stringify({ email: email, otp: otp })
        });
        if (res.ok) {
            var d = await res.json();
            localStorage.setItem('gapshop_token', d.token);
            localStorage.setItem('gapshop_customer', JSON.stringify(d.customer));
            gsRenderAccount();
        } else {
            err.textContent = 'Invalid or expired code.';
            err.style.display = 'block';
        }
    }
    function gsRenderAccount() {
        var c = JSON.parse(localStorage.getItem('gapshop_customer') || '{}');
        document.getElementById('gs-acct-wrap').innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">'
            + '<div><h2 style="margin:0 0 4px;font-size:1.3rem;font-weight:700">My Account</h2>'
            + '<p style="margin:0;color:#888;font-size:.88rem">'+(c.email||'')+'</p></div>'
            + '<button onclick="gsSignOut()" class="gapshop-btn gapshop-btn-outline" style="font-size:.82rem;padding:8px 18px">Sign Out</button>'
            + '</div>'
            + '<div style="background:#fff;border-radius:10px;padding:24px;box-shadow:0 1px 8px rgba(0,0,0,.08)">'
            + '<h3 style="margin:0 0 16px;font-size:1rem;font-weight:700">Order History</h3>'
            + '<p style="color:#aaa;text-align:center;padding:32px 0">No orders yet. <a href="/" style="color:#1565c0">Start shopping</a>.</p>'
            + '</div>';
    }
    function gsSignOut() {
        localStorage.removeItem('gapshop_token');
        localStorage.removeItem('gapshop_customer');
        gsRenderLogin();
    }
    </script>
    <?php
    return ob_get_clean();
}

// ─── Blog Sync Hook ───────────────────────────────────────────────────────────
add_action('save_post', 'gapshop_sync_post_on_save', 10, 2);
function gapshop_sync_post_on_save($post_id, $post) {
    // Skip revisions and autosaves
    if (wp_is_post_revision($post_id)) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if ($post->post_status !== 'publish') return;

    $sync_secret = get_option('gapshop_sync_secret', '');
    if (empty($sync_secret)) return;

    // Non-blocking — doesn't slow down WP save
    wp_remote_post(GAPSHOP_API . '/api/wp/sync', [
        'headers'  => [
            'Content-Type'  => 'application/json',
            'X-Sync-Secret' => $sync_secret,
        ],
        'body'     => json_encode(['post_id' => $post_id]),
        'timeout'  => 5,
        'blocking' => false,
    ]);
}