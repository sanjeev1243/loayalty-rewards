<?php
// class-lrp-admin.php - updated
if (!defined('ABSPATH')) {
    exit;
}

// Table identifiers are built from the trusted WordPress prefix and plugin-owned suffixes.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
class LRP_Admin {
    private $events_page = null;

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        add_action('admin_init', [$this, 'handle_authentication']);
        add_action('admin_init', [$this, 'save_config']);
        add_action('admin_init', [$this, 'save_features_config']);
        add_action('admin_init', [$this, 'handle_gift_card_export']);
        add_action('admin_init', [$this, 'manually_create_tables']);
        add_action( 'admin_init', [ $this, 'lrp_register_netsuite_endpoint_url_points_config' ] );

        add_action( 'wp_ajax_lrp_get_customer_events', [ $this, 'ajax_get_customer_events' ] );
        add_action( 'wp_ajax_lrp_export_customer_events', [ $this, 'ajax_export_customer_events' ] );
        add_action('admin_init', [$this, 'handle_loyalty_customer_export']);

        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_customer_events_assets' ] );
        add_action('admin_notices', [$this, 'admin_notices']);
        add_filter('woocommerce_product_data_tabs', [$this, 'add_loyalty_product_tab'], 99);
        add_action('woocommerce_product_data_panels', [$this, 'add_loyalty_product_tab_content']);

        // Keep WooCommerce > Loyalty Rewards highlighted in sidebar when viewing any loyalty subpage
        add_filter('parent_file', [$this, 'fix_parent_menu_highlight']);
        add_filter('submenu_file', [$this, 'fix_submenu_highlight']);
    }

    public function fix_parent_menu_highlight($parent_file) {
        global $current_screen;
        if ($current_screen && in_array($current_screen->id, [
            'admin_page_lrp-loyalty-customers',
            'admin_page_lrp-items',
            'admin_page_lrp-events',
            'admin_page_lrp-features',
            'admin_page_lrp-gift-card-users',
            'woocommerce_page_lrp-settings'
        ], true)) {
            return 'woocommerce';
        }
        return $parent_file;
    }

    public function fix_submenu_highlight($submenu_file) {
        global $current_screen;
        if ($current_screen && in_array($current_screen->id, [
            'admin_page_lrp-loyalty-customers',
            'admin_page_lrp-items',
            'admin_page_lrp-events',
            'admin_page_lrp-features',
            'admin_page_lrp-gift-card-users',
            'woocommerce_page_lrp-settings'
        ], true)) {
            return 'lrp-settings';
        }
        return $submenu_file;
    }

    public static function render_loyalty_nav_tabs($active_page = 'lrp-settings') {
        $tabs = [
            'lrp-settings'          => [ 'label' => __( 'Setup', 'netscore-loyalty-rewards' ), 'icon' => 'fas fa-cog' ],
            'lrp-loyalty-customers' => [ 'label' => __( 'Loyalty Customers', 'netscore-loyalty-rewards' ), 'icon' => 'fas fa-users' ],
            'lrp-items'             => [ 'label' => __( 'Items', 'netscore-loyalty-rewards' ), 'icon' => 'fas fa-box-open' ],
            'lrp-events'            => [ 'label' => __( 'Events', 'netscore-loyalty-rewards' ), 'icon' => 'fas fa-calendar-alt' ],
            'lrp-features'          => [ 'label' => __( 'Features', 'netscore-loyalty-rewards' ), 'icon' => 'fas fa-toggle-on' ],
            'lrp-gift-card-users'   => [ 'label' => __( 'Gift Cards Generated', 'netscore-loyalty-rewards' ), 'icon' => 'fas fa-gift' ],
        ];
        ?>
        <nav class="nav-tab-wrapper woo-nav-tab-wrapper lrp-main-nav-tabs" style="margin: 15px 0 20px 0;">
            <?php foreach ( $tabs as $page_slug => $tab_data ) : 
                $url = admin_url( 'admin.php?page=' . $page_slug );
                $is_active = ( $active_page === $page_slug );
            ?>
                <a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo $is_active ? 'nav-tab-active' : ''; ?>">
                    <i class="<?php echo esc_attr( $tab_data['icon'] ); ?>" style="margin-right: 6px;"></i>
                    <?php echo esc_html( $tab_data['label'] ); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    private function is_authenticated() {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    private function get_customer_type() {
        $user_id = get_current_user_id();
        return get_user_meta($user_id, 'lrp_customer_type', true);
    }

    private function is_license_expired() {
        return false;
    }

    private function restrict_access() {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    private function is_valid_ymd($d) {
        if (!$d) return false;
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    }

    private function normalize_date($val) {
        if (!isset($val)) return '';
        $val = trim((string)$val);
        if ($val === '' || $val === 'null' || $val === '0') return '';
        if (strpos($val, '0001-01-01') === 0) return '';
        // Preserve calendar dates coming from external systems even when they
        // include a time/timezone, otherwise UTC conversion can shift the day.
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})(?:[T\s].*)?$#', $val, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $d = (int) $m[3];
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+.*)?$#', $val, $m)) {
            $first = (int) $m[1];
            $second = (int) $m[2];
            $y = (int) $m[3];
            if ($first > 12 && checkdate($second, $first, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $second, $first);
            }
            if (checkdate($first, $second, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $first, $second);
            }
            if (checkdate($second, $first, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $second, $first);
            }
            return '';
        }
        if (preg_match('#/Date\((\d+)\)/#', $val, $m)) {
            $ms = (int)$m[1];
            $ts = (int) round($ms / 1000);
            return $ts > 0 ? gmdate('Y-m-d', $ts) : '';
        }
        if (ctype_digit($val)) {
            $n = (int)$val;
            if ($n <= 0) return '';
            if ($n > 20000000000) $n = (int) round($n / 1000);
            return gmdate('Y-m-d', $n);
        }
        $ts = strtotime($val);
        return ($ts !== false && $ts > 0) ? gmdate('Y-m-d', $ts) : '';
    }

    private function extract_ns_expiry(array $payload) {
        $preferred = ['expirydate','expiredate','expirationdate','enddate','planenddate','validtill','validuntil','validto','licenseexpiry','licenseend','licenseexpirydate'];
        foreach ($payload as $k => $v) {
            $key = strtolower((string)$k);
            foreach ($preferred as $needle) {
                if (strpos($key, $needle) !== false) {
                    $d = $this->normalize_date($v);
                    if ($this->is_valid_ymd($d)) return $d;
                }
            }
            if (is_array($v)) {
                $d = $this->extract_ns_expiry($v);
                if ($this->is_valid_ymd($d)) return $d;
            }
        }
        $best = ''; $bestTs = 0;
        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($payload));
        foreach ($it as $val) {
            $d = $this->normalize_date($val);
            if ($this->is_valid_ymd($d)) {
                $ts = strtotime($d . ' 00:00:00 UTC');
                if ($ts > $bestTs) { $bestTs = $ts; $best = $d; }
            }
        }
        return $best;
    }

    private function extract_ns_expiry_raw(array $payload) {
        $preferred = ['expirydate','expiredate','expirationdate','enddate','planenddate','validtill','validuntil','validto','licenseexpiry','licenseend','licenseexpirydate'];
        foreach ($payload as $k => $v) {
            $key = strtolower((string)$k);
            foreach ($preferred as $needle) {
                if (strpos($key, $needle) !== false) {
                    if (!is_array($v) && !is_object($v)) {
                        return trim((string) $v);
                    }
                }
            }
            if (is_array($v)) {
                $raw = $this->extract_ns_expiry_raw($v);
                if ($raw !== '') {
                    return $raw;
                }
            }
        }

        $bestRaw = '';
        $bestTs = 0;
        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($payload));
        foreach ($it as $val) {
            if (is_array($val) || is_object($val)) {
                continue;
            }
            $raw = trim((string) $val);
            $d = $this->normalize_date($raw);
            if ($this->is_valid_ymd($d)) {
                $ts = strtotime($d . ' 00:00:00 UTC');
                if ($ts > $bestTs) {
                    $bestTs = $ts;
                    $bestRaw = $raw;
                }
            }
        }
        return $bestRaw;
    }

    private function normalize_netsuite_plan_end_date($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        // NetSuite API is returning UTC datetimes, while the NetSuite UI shows
        // the business date in Pacific Time. Convert to that timezone first so
        // the stored/displayed date matches the NetSuite screen.
        try {
            $dt = new DateTimeImmutable($raw);
            return $dt->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
        } catch (Exception $e) {
            return $this->normalize_date($raw);
        }
    }

    private function call_netsuite_license($url, $license_code, $product_code, $account_id, $override_token = '') {
        $basic_token = !empty($override_token) ? $override_token : get_option('lrp_netsuite_auth_token');
        if (empty($basic_token) && defined('LRP_NETSUITE_BASIC')) {
            $basic_token = LRP_NETSUITE_BASIC;
        }
        if (empty($basic_token)) {
            return [
                'ok' => false, 'code' => 0,
                'error' => 'Missing NetSuite Authorization token. Provide Auth Code on form or set lrp_netsuite_auth_token option/constant.',
                'raw' => '', 'json' => null,
            ];
        }
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . $basic_token,
        ];
        $body = [
            'licenseCode' => $license_code,
            'productCode' => $product_code,
        ];
        if ($account_id !== '') {
            $body['accountId'] = $account_id;
        }
        $args = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 20,
            'redirection' => 3,
        ];
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'code' => 0,
                'error' => $response->get_error_message(),
                'raw' => '',
                'json' => null,
            ];
        }
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);
        return [
            'ok' => ($code >= 200 && $code < 300),
            'code' => $code,
            'error' => ($code >= 200 && $code < 300) ? '' : 'HTTP ' . $code,
            'raw' => $raw,
            'json' => $json,
        ];
    }

    public function handle_authentication() {
        global $wpdb;
        $user_id = get_current_user_id();
        if (!$user_id) {
            set_transient('lrp_admin_error', 'Please log in to WordPress to access the Loyalty Rewards dashboard.', 30);
            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }

        // Allow clearing stored NetSuite token
        if (isset($_GET['clear_lrp_ns_token']) && $_GET['clear_lrp_ns_token'] === '1' && (current_user_can('manage_woocommerce') || current_user_can('manage_options'))) {
            $clear_nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
            if (!wp_verify_nonce($clear_nonce, 'lrp_clear_ns_token')) {
                set_transient('lrp_admin_error', 'Invalid request to clear token.', 10);
            } else {
                delete_option('lrp_netsuite_auth_token');
                set_transient('lrp_admin_notice', 'Saved NetSuite Auth Code cleared.', 10);
            }
            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }

        $auth_nonce = isset( $_POST['lrp_auth_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lrp_auth_nonce'] ) ) : '';
        if ($auth_nonce && wp_verify_nonce($auth_nonce, 'lrp_auth_nonce')) {
            $errors = [];
            $has_netsuite_account = !empty($_POST['has_netsuite_account']);
            $customer_type = $has_netsuite_account ? 'netsuite' : 'loyalty';
            $auth_code = isset($_POST['auth_code']) ? sanitize_text_field(wp_unslash($_POST['auth_code'])) : '';
            $license_key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
            $product_code = isset($_POST['product_code']) ? sanitize_text_field(wp_unslash($_POST['product_code'])) : '';
            $account_id = isset($_POST['account_id']) ? sanitize_text_field(wp_unslash($_POST['account_id'])) : '';
            $license_url = isset($_POST['license_url']) ? esc_url_raw(wp_unslash($_POST['license_url'])) : '';

            if (empty($auth_code) || empty($license_key) || empty($product_code) || empty($license_url)) {
                $errors[] = 'Auth Code, License Key, Product Code, and License URL are required.';
            } elseif ($has_netsuite_account && empty($account_id)) {
                $errors[] = 'NetSuite Account ID is required when the NetSuite account option is checked.';
            } elseif (!$has_netsuite_account) {
                $table_name = $wpdb->prefix . 'netscore_lmp_users';

                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name) {
                    $errors[] = 'Loyalty customers table does not exist. Please reactivate the plugin or create tables manually.';
                } else {
                    $loyalty_user = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$table_name}
                         WHERE auth_code = %s
                           AND license_key = %s
                           AND product_code = %s
                           AND license_url = %s
                         LIMIT 1",
                        $auth_code,
                        $license_key,
                        $product_code,
                        $license_url
                    ));

                    if (!$loyalty_user) {
                        $errors[] = 'Loyalty customer credentials do not match.';
                    } else {
                        $plan_end_date_raw = $loyalty_user->plan_end_date;
                        $plan_end_date = $this->normalize_date($plan_end_date_raw);

                        update_user_meta($user_id, 'lrp_authenticated', '1');
                        update_user_meta($user_id, 'lrp_customer_type', 'loyalty');
                        update_user_meta($user_id, 'lrp_license_key', $license_key);
                        update_user_meta($user_id, 'lrp_product_code', $product_code);
                        update_user_meta($user_id, 'lrp_license_url', $license_url);
                        update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
                        update_user_meta($user_id, 'lrp_plan_end_date_raw', $plan_end_date_raw);
                        delete_user_meta($user_id, 'lrp_account_id');

                        if (!empty($loyalty_user->username)) {
                            update_user_meta($user_id, 'lrp_username', $loyalty_user->username);
                        } else {
                            delete_user_meta($user_id, 'lrp_username');
                        }

                        $msg = 'Authentication successful. Welcome to the Loyalty Rewards dashboard.';
                        if ($plan_end_date) {
                            $msg .= ' Plan End: ' . $plan_end_date . '.';
                        }
                        set_transient('lrp_admin_notice', $msg, 30);
                        wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
                        exit;
                    }
                }
            } else {
                $api = $this->call_netsuite_license(
                    $license_url,
                    $license_key,
                    $product_code,
                    $account_id,
                    $auth_code
                );

                if (!$api['ok']) {
                    $details = $api['error'];
                    if (!empty($api['raw'])) {
                        $details .= ' - ' . substr($api['raw'], 0, 300);
                    }
                    $errors[] = 'License validation failed: ' . $details;
                } else {
                    $payload = is_array($api['json']) ? $api['json'] : [];
                    $plan_end_date_raw = $this->extract_ns_expiry_raw($payload);
                    $plan_end_date = $this->normalize_netsuite_plan_end_date($plan_end_date_raw);
                    if (!$this->is_valid_ymd($plan_end_date)) {
                        $plan_end_date = $this->extract_ns_expiry($payload);
                    }

                    $table_name = $wpdb->prefix . 'netscore_lmp_netsuite_users';
                    $saved = false;

                    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
                        $errors[] = 'NetSuite customers table does not exist. Please reactivate the plugin or create tables manually.';
                    } else {
                        $existing_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $table_name WHERE license_key = %s AND product_code = %s AND account_id = %s AND license_url = %s",
                            $license_key,
                            $product_code,
                            $account_id,
                            $license_url
                        ));
                        $row = [
                            'license_key' => $license_key,
                            'product_code' => $product_code,
                            'account_id' => $account_id,
                            'license_url' => $license_url,
                            'plan_end_date' => $plan_end_date,
                            'updated_at' => current_time('mysql'),
                        ];
                        if ($existing_id) {
                            $saved = $wpdb->update($table_name, $row, ['id' => (int) $existing_id]);
                        } else {
                            $row['plan_start_date'] = current_time('mysql');
                            $row['plan_active'] = 1;
                            $row['created_at'] = current_time('mysql');
                            $saved = $wpdb->insert($table_name, $row);
                        }
                    }

                    if ($saved === false) {
                        $errors[] = 'The license was valid, but its local record could not be saved.';
                    } else {
                        update_option('lrp_netsuite_auth_token', $auth_code);
                        update_user_meta($user_id, 'lrp_authenticated', '1');
                        update_user_meta($user_id, 'lrp_customer_type', $customer_type);
                        update_user_meta($user_id, 'lrp_license_key', $license_key);
                        update_user_meta($user_id, 'lrp_product_code', $product_code);
                        update_user_meta($user_id, 'lrp_license_url', $license_url);
                        update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
                        update_user_meta($user_id, 'lrp_plan_end_date_raw', $plan_end_date_raw);
                        delete_user_meta($user_id, 'lrp_username');
                        update_user_meta($user_id, 'lrp_account_id', $account_id);

                        $msg = 'Authentication successful with NetSuite.';
                        if ($plan_end_date) {
                            $msg .= ' Plan End: ' . $plan_end_date . '.';
                        }
                        set_transient('lrp_admin_notice', $msg, 30);
                        wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
                        exit;
                    }
                }
            }
            if (!empty($errors)) {
                set_transient('lrp_admin_error', implode(' ', $errors), 30);
                wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
                exit;
            }
        }

        if (isset($_GET['logout']) && $_GET['logout'] == '1') {
            delete_user_meta($user_id, 'lrp_authenticated');
            delete_user_meta($user_id, 'lrp_customer_type');
            delete_user_meta($user_id, 'lrp_license_key');
            delete_user_meta($user_id, 'lrp_username');
            delete_user_meta($user_id, 'lrp_product_code');
            delete_user_meta($user_id, 'lrp_account_id');
            delete_user_meta($user_id, 'lrp_license_url');
            delete_user_meta($user_id, 'lrp_plan_end_date');
            delete_user_meta($user_id, 'lrp_plan_end_date_raw');
            set_transient('lrp_admin_notice', 'You have been logged out.', 30);
            wp_safe_redirect(admin_url('admin.php?page=lrp-settings'));
            exit;
        }
    }



function lrp_register_netsuite_endpoint_url_points_config() {

    // Register the option (stored in wp_options)
    register_setting(
        'lrp_points_settings_group', // Settings group used in settings_fields()
        'lrp_netsuite_url',          // Option name in DB
        [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]
    );

    // Add the field into Points Config section
    add_settings_field(
        'lrp_netsuite_url',
        'NetSuite Endpoint URL',     // ✔ Updated label
        [ $this, 'lrp_netsuite_endpoint_url_field_callback' ],
        'lrp_points_config_page',    // Page slug used in do_settings_sections()
        'lrp_points_config_section'  // Section ID for Points Config
    );
}

/**
 * Render the NetSuite Endpoint URL input field.
 */
function lrp_netsuite_endpoint_url_field_callback() {
    $value = esc_url( get_option( 'lrp_netsuite_url', '' ) );
    ?>
    <input type="url"
           name="lrp_netsuite_url"
           id="lrp_netsuite_url"
           value="<?php echo esc_attr( $value ); ?>"
           style="width: 420px;"
           placeholder="https://xxxxx.restlets.api.netsuite.com/...">
    <p class="description">
        Enter the single NetSuite Endpoint URL used to send Order & Gift Card data.
    </p>
    <?php
}


    public function add_admin_menu() {
        // Main WooCommerce submenu item (visible in left sidebar)
        add_submenu_page(
            'woocommerce',
            __( 'Loyalty Rewards', 'netscore-loyalty-rewards' ),
            __( 'Loyalty Rewards', 'netscore-loyalty-rewards' ),
            'manage_woocommerce',
            'lrp-settings',
            [ $this, 'settings_page' ]
        );

        // Subpages (accessible via top tabs, hidden from sidebar menu)
        add_submenu_page(
            null,
            __( 'Loyalty Customers', 'netscore-loyalty-rewards' ),
            __( 'Loyalty Customers', 'netscore-loyalty-rewards' ),
            'manage_woocommerce',
            'lrp-loyalty-customers',
            [ $this, 'display_loyalty_customers_table' ]
        );

        add_submenu_page(
            null,
            __( 'Loyalty Items', 'netscore-loyalty-rewards' ),
            __( 'Loyalty Items', 'netscore-loyalty-rewards' ),
            'manage_woocommerce',
            'lrp-items',
            [ 'LRP_Items_Page', 'render_items_page_static' ]
        );

        add_submenu_page(
            null,
            __( 'Loyalty Events', 'netscore-loyalty-rewards' ),
            __( 'Loyalty Events', 'netscore-loyalty-rewards' ),
            'manage_woocommerce',
            'lrp-events',
            [ $this, 'display_events_table' ]
        );

        add_submenu_page(
            null,
            __( 'Loyalty Features', 'netscore-loyalty-rewards' ),
            __( 'Loyalty Features', 'netscore-loyalty-rewards' ),
            'manage_woocommerce',
            'lrp-features',
            [ $this, 'display_features_page' ]
        );

        add_submenu_page(
            null,
            __( 'Gift Cards Generated', 'netscore-loyalty-rewards' ),
            __( 'Gift Cards Generated', 'netscore-loyalty-rewards' ),
            'manage_woocommerce',
            'lrp-gift-card-users',
            [ $this, 'display_gift_card_users_table' ]
        );
    }

    public function add_loyalty_customers_submenu() {}
    public function add_items_submenu() {}
    public function add_events_submenu() {}
    public function add_features_submenu() {}
    public function add_gift_card_users_submenu() {}

    public function display_loyalty_customers_table() {
        if ( class_exists( 'LRP_Customers_Page' ) ) {
            LRP_Customers_Page::render();
        }
    }

    public function display_events_table() {
        if ( class_exists( 'LRP_Events_Page' ) ) {
            LRP_Events_Page::render_events_page_static();
        }
    }

    public function display_features_page() {
        if ( class_exists( 'LRP_Features_Page' ) ) {
            LRP_Features_Page::render();
        }
    }

    public function display_gift_card_users_table() {
        if ( class_exists( 'LRP_Gift_Cards_Page' ) ) {
            LRP_Gift_Cards_Page::render();
        }
    }

    public function handle_loyalty_customer_export() {
        if ( class_exists( 'LRP_Customers_Page' ) ) {
            LRP_Customers_Page::handle_export();
        }
    }

    public function handle_gift_card_export() {
        if ( class_exists( 'LRP_Gift_Cards_Page' ) ) {
            LRP_Gift_Cards_Page::handle_export();
        }
    }

    public function save_features_config() {
        if ( class_exists( 'LRP_Features_Page' ) ) {
            LRP_Features_Page::handle_save();
        }
    }

    public function enqueue_admin_scripts($hook) {
        $current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        $valid_hooks = [
            'woocommerce_page_lrp-settings',
            'woocommerce_page_lrp-loyalty-customers',
            'woocommerce_page_lrp-items',
            'woocommerce_page_lrp-events',
            'woocommerce_page_lrp-features',
            'woocommerce_page_lrp-gift-card-users',
            'toplevel_page_lrp-settings',
            'product_page_product_attributes',
            'product',
            'post.php',
            'post-new.php'
        ];
        $valid_pages = [
            'lrp-settings',
            'lrp-loyalty-customers',
            'lrp-items',
            'lrp-events',
            'lrp-features',
            'lrp-gift-card-users'
        ];
        if (in_array($hook, $valid_hooks, true) || in_array( $current_page, $valid_pages, true )) {
            $admin_css_path = LRP_PLUGIN_DIR . 'assets/css/lrp-admin.css';
            $admin_css_version = file_exists($admin_css_path) ? filemtime($admin_css_path) : '1.2.0';
            wp_enqueue_style('lrp-admin-styles', LRP_PLUGIN_URL . 'assets/css/lrp-admin.css', [], $admin_css_version);
            wp_enqueue_style('lrp-font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4');
            $admin_js_path = LRP_PLUGIN_DIR . 'assets/js/lrp-admin.js';
            $admin_js_version = file_exists($admin_js_path) ? filemtime($admin_js_path) : '1.2.0';
            wp_enqueue_script('lrp-admin-script', LRP_PLUGIN_URL . 'assets/js/lrp-admin.js', ['jquery'], $admin_js_version, true);
            wp_localize_script('lrp-admin-script', 'lrp_admin_params', [
                'nonce' => wp_create_nonce('lrp_nonce'),
                'ajax_url' => admin_url('admin-ajax.php')
            ]);
        }
    }  

	/**
     * Enqueue modal assets for customer events popup
     */
public function enqueue_customer_events_assets( $hook ) {

    // Enqueue popup CSS
    wp_enqueue_style(
        'lrp-customer-events-css',
        LRP_PLUGIN_URL . 'assets/css/lrp-customer-events.css',
        [],
        '1.0.0'
    );

    // Enqueue popup JS
    wp_enqueue_script(
        'lrp-customer-events-js',
        LRP_PLUGIN_URL . 'assets/js/lrp-customer-events.js',
        [ 'jquery' ],
        '1.0.0',
        true
    );

    // Localize AJAX URL + nonce
    wp_localize_script( 'lrp-customer-events-js', 'lrp_admin_params', [
        'nonce' => wp_create_nonce( 'lrp_nonce' ),
        'ajax_url' => admin_url( 'admin-ajax.php' ),
    ] );
}

    public function admin_notices() {
        $screen = get_current_screen();
        if (!$screen) return;
        $valid_screens = [
            'woocommerce_page_lrp-settings',
            'woocommerce_page_lrp-loyalty-customers',
            'woocommerce_page_lrp-items',
            'woocommerce_page_lrp-events',
            'woocommerce_page_lrp-features',
            'woocommerce_page_lrp-gift-card-users',
            'toplevel_page_lrp-settings',
            'product'
        ];
        if (in_array($screen->id, $valid_screens, true)) {
            if ($message = get_transient('lrp_admin_notice')) {
                delete_transient('lrp_admin_notice');
                if ( 'woocommerce_page_lrp-settings' !== $screen->id && 'toplevel_page_lrp-settings' !== $screen->id ) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
                }
            } elseif ($error = get_transient('lrp_admin_error')) {
                echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post($error) . '</p></div>';
                delete_transient('lrp_admin_error');
            }
        }
    }
    

public function disable_fields_for_netsuite() {

    echo '
    <style>
    /* Readable readonly style for disabled inputs/buttons in our areas only */
    #lrp_loyalty_product_data input[disabled],
    #lrp_loyalty_product_data select[disabled],
    #lrp_loyalty_product_data textarea[disabled],
    #lrp_loyalty_product_data button[disabled],
    .lrp-admin-container .lrp-tab-pane input[disabled],
    .lrp-admin-container .lrp-tab-pane select[disabled],
    .lrp-admin-container .lrp-tab-pane textarea[disabled],
    .lrp-admin-container .lrp-tab-pane button[disabled] {
        background: #ffffff !important;
        color: #111 !important;
        opacity: 1 !important;
        border-color: #d1d5db !important;
        box-shadow: none !important;
        cursor: not-allowed !important;
    }
    </style>
    <script>
    jQuery(document).ready(function($) {

        // Limit to our plugin UI only: product tab + settings page panes
        var $scopes = $("#lrp_loyalty_product_data, .lrp-admin-container .lrp-tab-pane");

        $scopes.find("input, select, textarea, button").each(function() {
            var el = $(this);

            // allow special buttons if you want (export, view, etc.)
            if (el.hasClass("lrp-btn") || el.hasClass("lrp-open-events") || el.hasClass("toggle-visibility")) {
                return;
            }

            // do not mess with hidden inputs
            if (el.attr("type") === "hidden") {
                return;
            }

            el.prop("disabled", true)
              .css({ opacity: 0.6, cursor: "not-allowed" });
        });
    });
    </script>
    ';
}

public function is_netsuite_customer() {
    $user_id = get_current_user_id();
    return get_user_meta($user_id, 'lrp_customer_type', true) === 'netsuite';
}


    public function settings_page() {
        global $wpdb;
        $user_id = get_current_user_id();
        $tables = [
            'app_config' => $wpdb->prefix . 'netscore_lty_lty_config_table',
            'points_config' => $wpdb->prefix . 'netscore_lty_lty_config_table',
            'threshold_config' => $wpdb->prefix . 'netscore_lty_lty_config_table',
            'loyalty_tiers' => $wpdb->prefix . 'netscore_lty_lty_tiers_table',
            'social_share_config' => $wpdb->prefix . 'netscore_lty_lty_config_table',
            'netsuite_customers' => $wpdb->prefix . 'netscore_lmp_netsuite_users',
            'loyalty_customers' => $wpdb->prefix . 'netscore_lmp_users'
        ];
        foreach ($tables as $key => $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                add_action('admin_notices', function() use ($table) {
                    echo '<div class="notice notice-error is-dismissible"><p>Database table ' . esc_html($table) . ' does not exist. <a href="' . esc_url(add_query_arg('lrp_create_tables', '1')) . '">Create tables now</a>.</p></div>';
                });
            }
        }
        if (!$this->is_authenticated()) {
            ?>
            <div class="wrap lrp-login-page">
                <h1>Loyalty Rewards Login</h1>
                <div class="lrp-admin-container lrp-login-card" style="display: block !important;">
                    <div class="lrp-login-shell">
                        <aside class="lrp-login-brand" aria-label="NetScore Loyalty Rewards">
                            <div class="lrp-brand-mark">
                                <img class="lrp-login-logo-img" src="<?php echo esc_url( LRP_PLUGIN_URL . 'assets/images/netscore_icon_white-logo.svg?v=6' ); ?>" alt="NetScore">
                            </div>
                            <h2>NetScore<br><span>Loyalty Rewards</span></h2>
                            <span class="lrp-brand-rule" aria-hidden="true"></span>
                            <p>Reward customers.Build loyalty.<br>Grow your business.</p>
                            <div class="lrp-login-gifts" aria-hidden="true">
                                <span><i class="fas fa-gift"></i></span>
                                <span><i class="fas fa-certificate"></i></span>
                                <span><i class="fas fa-gift"></i></span>
                            </div>
                            <div class="lrp-brand-dashboard" aria-hidden="true">
                                <span class="lrp-dashboard-bar"></span>
                                <span class="lrp-dashboard-line"></span>
                                <span class="lrp-dashboard-pie"></span>
                                <span class="lrp-dashboard-list"></span>
                                <span class="lrp-dashboard-gift-badge"><i class="fas fa-gift"></i></span>
                                <span class="lrp-dashboard-star-badge"><i class="fas fa-star"></i></span>
                                <span class="lrp-dashboard-spark-badge"><i class="fas fa-certificate"></i></span>
                            </div>
                            <span class="lrp-brand-lock" aria-hidden="true"><i class="fas fa-lock" aria-hidden="true"></i></span>
                        </aside>
                        <section class="lrp-login-form-panel">
                            <div class="lrp-login-header">
                                <h2>Activate Your Licence</h2>
                                <p>Fill the licence details</p>
                            </div>
                            <form method="post" action="" id="netsuite_form">
                                <?php wp_nonce_field('lrp_auth_nonce', 'lrp_auth_nonce'); ?>
                                <h2>Licence Login</h2>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="auth_code">Auth Code</label></th>
                                        <td>
                                            <input type="text" id="auth_code" name="auth_code" value="" placeholder="Enter auth code" required />
                                            <?php if (get_option('lrp_netsuite_auth_token')): ?>
                                                <?php
                                                    $clear_url = wp_nonce_url( add_query_arg('clear_lrp_ns_token', '1', admin_url('admin.php?page=lrp-settings')), 'lrp_clear_ns_token' );
                                                ?>
                                                <p><strong>Saved token present.</strong> <a href="<?php echo esc_url($clear_url); ?>">Clear saved token</a></p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="license_key">License Key</label></th>
                                        <td><input type="text" id="license_key" name="license_key" placeholder="Enter license key" required></td>
                                    </tr>
                                    <tr>
                                        <th><label for="product_code">Product Code</label></th>
                                        <td><input type="text" id="product_code" name="product_code" placeholder="Enter product code" required></td>
                                    </tr>
                                    <tr>
                                        <th><label for="license_url">License URL</label></th>
                                        <td><input type="url" id="license_url" name="license_url" value="https://license.netscoretech.com/api/Account/GetLicenseDeatails" placeholder="Enter license URL" required></td>
                                    </tr>
                                    <tr class="lrp-netsuite-account-option">
                                        <th><label for="has_netsuite_account">NetSuite Account</label></th>
                                        <td>
                                            <label class="lrp-netsuite-checkbox" for="has_netsuite_account">
                                                <input type="checkbox" id="has_netsuite_account" name="has_netsuite_account" value="1">
                                                <span>Do you have a NetSuite account?</span>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr id="netsuite_account_id_row">
                                        <th><label for="account_id">NetSuite Account ID</label></th>
                                        <td><input type="text" id="account_id" name="account_id" placeholder="Enter NetSuite account ID"></td>
                                    </tr>
                                </table>
                                <div class="lrp-submit-section">
                                    <?php submit_button('Activate'); ?>
                                </div>
                            </form>
                            <div class="lrp-login-help">
                                <span>Your data is safe and secure</span>
                                <small><?php esc_html_e( 'Need help?', 'netscore-loyalty-rewards' ); ?> <a href="<?php echo esc_url( apply_filters( 'lrp_docs_url', 'https://woocommerce.com/document/netscore-loyalty-rewards/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'documentation', 'netscore-loyalty-rewards' ); ?></a> <?php esc_html_e( 'or', 'netscore-loyalty-rewards' ); ?> <a href="<?php echo esc_url( apply_filters( 'lrp_support_url', 'https://woocommerce.com/my-account/create-a-ticket/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'contact support', 'netscore-loyalty-rewards' ); ?></a>.</small>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
            <script>
            jQuery(document).ready(function($) {
                function toggleNetSuiteAccountId() {
                    var $checkbox = $('#has_netsuite_account');
                    var hasAccount = $checkbox.is(':checked');
                    $('#netsuite_account_id_row').toggleClass('is-visible', hasAccount);
                    $('#account_id').prop('required', hasAccount);
                }

                $('#has_netsuite_account').on('change', function() {
                    var hasAccount = $(this).is(':checked');
                    toggleNetSuiteAccountId();
                });

                toggleNetSuiteAccountId();
            });
            </script>
            <?php
            return;
        }

        $app_config_table = $wpdb->prefix . 'netscore_lty_lty_config_table';
        $points_config_table = $wpdb->prefix . 'netscore_lty_lty_config_table';
        $threshold_config_table = $wpdb->prefix . 'netscore_lty_lty_config_table';
        $loyalty_tiers_table       = $wpdb->prefix . 'netscore_lty_lty_tiers_table';
        $loyalty_tier_levels_table = $wpdb->prefix . 'netscore_lty_tier_lvl_pts_table';
        $social_share_config_table = $wpdb->prefix . 'netscore_lty_lty_config_table';
        $app_config = $wpdb->get_row("SELECT * FROM $app_config_table LIMIT 1");
        
        if ( $this->is_netsuite_customer() ) {
            $this->disable_fields_for_netsuite();
        }
        if (!$app_config) {
            $wpdb->insert($app_config_table, [
                'customer_signup_points' => 50,
                'product_review_points' => 10,
                'referral_points' => 50,
                'birthday_points' => 25,
                'anniversary_points' => 25
            ]);
            $app_config = $wpdb->get_row("SELECT * FROM $app_config_table LIMIT 1");
        }
        $points_config = $wpdb->get_row("SELECT * FROM $points_config_table LIMIT 1");
        if (!$points_config) {
            $wpdb->insert($points_config_table, [
                'each_point_value' => 10,
                'loyalty_point_value'=> 1.00
            ]);
            $points_config = $wpdb->get_row("SELECT * FROM $points_config_table LIMIT 1");
        }
        $threshold_config = $wpdb->get_row("SELECT * FROM $threshold_config_table LIMIT 1");
        if (!$threshold_config) {
            $wpdb->insert($threshold_config_table, [
                'minimum_redemption_points' => 100
            ]);
            $threshold_config = $wpdb->get_row("SELECT * FROM $threshold_config_table LIMIT 1");
        }
       // table names
$loyalty_tiers_table       = $wpdb->prefix . 'netscore_lty_lty_tiers_table';
$loyalty_tier_levels_table = $wpdb->prefix . 'netscore_lty_tier_lvl_pts_table';

// Fetch tiers by joining parent + child level row (one level row per tier assumed)
$loyalty_tiers = $wpdb->get_results( $wpdb->prepare(
    "SELECT p.id AS tier_id,
            p.tier_name AS name,
            COALESCE(l.threshold, 0) AS threshold,
            COALESCE(l.points_for_currency, 0) AS points,
            COALESCE(l.level, 1) AS level,
            CASE WHEN p.status = 'active' THEN 1 ELSE 0 END AS active,
            p.description
     FROM {$loyalty_tiers_table} p
     LEFT JOIN {$loyalty_tier_levels_table} l ON l.tier_id = p.id
     ORDER BY COALESCE(l.level, 1) ASC"
) );

        $social_share_config = $wpdb->get_row("SELECT * FROM $social_share_config_table LIMIT 1");
        if (!$social_share_config) {
            $wpdb->insert($social_share_config_table, [
                'email_share_points' => 20,
                'facebook_share_points' => 20
            ]);
            $social_share_config = $wpdb->get_row("SELECT * FROM $social_share_config_table LIMIT 1");
        }

        // ---------------- License expiry display ----------------
        $user_id = get_current_user_id();
        $customer_type = $this->get_customer_type();
        $plan_end_date = '';
        $user_data = [];
        if ($customer_type === 'netsuite') {
    $user_data = [
        'license_key' => get_user_meta($user_id, 'lrp_license_key', true),
        'product_code' => get_user_meta($user_id, 'lrp_product_code', true),
        'account_id' => get_user_meta($user_id, 'lrp_account_id', true),
        'license_url' => get_user_meta($user_id, 'lrp_license_url', true),
    ];
    // Try user meta first
    $plan_end_date = get_user_meta($user_id, 'lrp_plan_end_date', true);
    if (!$this->is_valid_ymd($plan_end_date)) {
        // Fallback to database query
        $netsuite_table = $wpdb->prefix . 'netscore_lmp_netsuite_users';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT plan_end_date FROM $netsuite_table WHERE license_key = %s AND product_code = %s AND account_id = %s AND license_url = %s LIMIT 1",
            $user_data['license_key'], $user_data['product_code'], $user_data['account_id'], $user_data['license_url']
        ));
        if ($row && !empty($row->plan_end_date) && $this->is_valid_ymd($row->plan_end_date)) {
            $plan_end_date = $row->plan_end_date;
            update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
        }
    }
        } elseif ($customer_type === 'loyalty') {
            $user_data = [
                'license_key' => get_user_meta($user_id, 'lrp_license_key', true),
                'product_code' => get_user_meta($user_id, 'lrp_product_code', true),
                'license_url' => get_user_meta($user_id, 'lrp_license_url', true),
                'username' => get_user_meta($user_id, 'lrp_username', true),
            ];
            $username = !empty($user_data['username']) ? sanitize_text_field($user_data['username']) : '';
            $license_key = !empty($user_data['license_key']) ? sanitize_text_field($user_data['license_key']) : '';
            if ($username || $license_key) {
                $loyalty_table = $wpdb->prefix . 'netscore_lmp_users';
                if ($license_key) {
                    $row = $wpdb->get_row($wpdb->prepare(
                        "SELECT plan_end_date FROM $loyalty_table WHERE license_key = %s LIMIT 1",
                        $license_key
                    ));
                } else {
                    $row = $wpdb->get_row($wpdb->prepare(
                        "SELECT plan_end_date FROM $loyalty_table WHERE username = %s LIMIT 1",
                        $username
                    ));
                }
                if ($row && !empty($row->plan_end_date)) {
                    $normalized_date = $this->normalize_date($row->plan_end_date);
                    if ($this->is_valid_ymd($normalized_date)) {
                        $plan_end_date = $normalized_date;
                        update_user_meta($user_id, 'lrp_plan_end_date', $plan_end_date);
                    }
                }
            }
        }
        $user_data['plan_end_date'] = $plan_end_date;
        $days_remaining = 'Unknown';
        $formatted_end_date = 'Not Set';
        if ($this->is_valid_ymd($plan_end_date)) {
            try {
                $today_dt = new DateTimeImmutable('today', wp_timezone());
                $end_dt = new DateTimeImmutable($plan_end_date, new DateTimeZone('UTC'));
                $end_dt_local = $end_dt->setTimezone(wp_timezone());
                $day_diff = (int) $today_dt->diff($end_dt_local)->format('%r%a');
                $days_remaining = $day_diff >= 0 ? $day_diff : 'Expired';
            } catch (Exception $e) {
                $days_remaining = 'Unknown';
            }
            $formatted_end_date = date_i18n(get_option('date_format'), strtotime($plan_end_date));
        }
        // --------------------------------------------------------
        ?>
            <?php
            $docs_url    = apply_filters( 'lrp_docs_url', 'https://woocommerce.com/document/netscore-loyalty-rewards/' );
            $support_url = apply_filters( 'lrp_support_url', 'https://woocommerce.com/my-account/create-a-ticket/' );
            ?>
            <div class="wrap lrp-settings-page">
                <?php self::render_loyalty_nav_tabs( 'lrp-settings' ); ?>
                <div class="lrp-settings-bg-dots" aria-hidden="true"></div>
                <div class="lrp-settings-header">
                    <div class="lrp-settings-header-copy">
                        <h1>Loyalty Rewards SetUp</h1>
                        <p>Centralized configuration for rewards, thresholds, point value strategy, and tier progression.</p>
                        <div class="lrp-settings-pills" aria-label="Plugin status summary">
                            <?php if ( ! empty( $user_data['account_id'] ) ) : ?>
                                <span>NetSuite Account ID: <?php echo esc_html( $user_data['account_id'] ); ?></span>
                            <?php endif; ?>
                            <span>Status: Active</span>
                        </div>
                    </div>
            </div>
            <div class="lrp-admin-container">
                <div class="lrp-nav-tabs">
                    <a href="#user-details" class="lrp-tab active"><span class="lrp-tab-icon"><i class="fas fa-user" aria-hidden="true"></i></span><span>Account & Integration</span><i class="fas fa-chevron-right lrp-tab-arrow" aria-hidden="true"></i></a>
                    <a href="#app-config" class="lrp-tab"><span class="lrp-tab-icon"><i class="fas fa-th-large" aria-hidden="true"></i></span><span>App Configurations</span><i class="fas fa-chevron-right lrp-tab-arrow" aria-hidden="true"></i></a>
                    <a href="#points-config" class="lrp-tab"><span class="lrp-tab-icon"><i class="far fa-star" aria-hidden="true"></i></span><span>Points Configurations</span><i class="fas fa-chevron-right lrp-tab-arrow" aria-hidden="true"></i></a>
                    <a href="#threshold-config" class="lrp-tab"><span class="lrp-tab-icon"><i class="fas fa-shield-alt" aria-hidden="true"></i></span><span>Threshold Configurations</span><i class="fas fa-chevron-right lrp-tab-arrow" aria-hidden="true"></i></a>
                    <a href="#social-share-config" class="lrp-tab"><span class="lrp-tab-icon"><i class="fas fa-share-alt" aria-hidden="true"></i></span><span>Social Share Configurations</span><i class="fas fa-chevron-right lrp-tab-arrow" aria-hidden="true"></i></a>
                    <a href="#loyalty-tiers" class="lrp-tab"><span class="lrp-tab-icon"><i class="fas fa-trophy" aria-hidden="true"></i></span><span>Loyalty Tiers</span><i class="fas fa-chevron-right lrp-tab-arrow" aria-hidden="true"></i></a>
                </div>
                <div class="lrp-tab-content" style="position:relative;">
                    <div id="user-details" class="lrp-tab-pane active">
                        <?php
                            $license_key_value = $user_data['license_key'] ?? '';
                            $license_key_masked = $license_key_value ? substr($license_key_value, 0, 7) . '********' . substr($license_key_value, -6) : '';
                            $product_code_value = $user_data['product_code'] ?? '';
                            $account_id_value = $user_data['account_id'] ?? '';
                            $license_url_value = $user_data['license_url'] ?? '';
                            $license_status_label = $days_remaining === 'Expired' ? 'Expired' : 'Active';
                            $license_status_heading = $days_remaining === 'Expired' ? 'Expired License' : 'Active License';
                            $license_status_copy = $days_remaining === 'Expired' ? 'Your license is expired.' : 'Your license is active and valid.';
                            $license_good_standing = $days_remaining === 'Expired' ? 'Your license is expired.' : 'Your license is active and in good standing.';
                            $license_good_standing_note = $days_remaining === 'Expired' ? 'Please renew your license to continue using Loyalty Rewards.' : 'Thank you for choosing NetScore Loyalty Rewards.';
                            $license_date_display = $this->is_valid_ymd($plan_end_date) ? date_i18n('j M Y', strtotime($plan_end_date)) : $formatted_end_date;
                        ?>
                        <div class="lrp-card lrp-license-details-card">
                            <div class="lrp-license-hero <?php echo esc_attr($days_remaining === 'Expired' ? 'expired' : 'active'); ?>">
                                <div class="lrp-license-hero-left">
                                    <span class="lrp-license-hero-mark"><i class="fas fa-shield-alt" aria-hidden="true"></i></span>
                                    <div class="lrp-license-hero-copy">
                                        <h2><?php echo esc_html($license_status_heading); ?></h2>
                                        <p><?php echo esc_html($license_status_copy); ?></p>
                                        <?php if ( ! empty( $account_id_value ) ) : ?>
                                            <span class="lrp-license-account-pill">Account ID: <strong><?php echo esc_html($account_id_value); ?></strong></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="lrp-license-hero-status">
                                    <strong><i class="fas <?php echo esc_attr($days_remaining === 'Expired' ? 'fa-times' : 'fa-check'); ?>" aria-hidden="true"></i><?php echo esc_html($days_remaining === 'Expired' ? 'License Expired' : 'License Active'); ?></strong>
                                    <span>Valid until</span>
                                    <b><?php echo esc_html($license_date_display); ?></b>
                                </div>
                            </div>

                            <div class="lrp-license-summary-grid">
                                <div class="lrp-license-summary-card lrp-license-summary-status">
                                    <span class="lrp-license-summary-icon"><i class="fas fa-shield-alt" aria-hidden="true"></i></span>
                                    <span>Status</span>
                                    <strong><?php echo esc_html($license_status_label); ?></strong>
                                </div>
                                <?php if ( ! empty( $product_code_value ) ) : ?>
                                    <div class="lrp-license-summary-card">
                                        <span class="lrp-license-summary-icon blue"><i class="fas fa-cube" aria-hidden="true"></i></span>
                                        <span>Product Code</span>
                                        <strong><?php echo esc_html($product_code_value); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if ( ! empty( $account_id_value ) ) : ?>
                                    <div class="lrp-license-summary-card">
                                        <span class="lrp-license-summary-icon purple"><i class="fas fa-user" aria-hidden="true"></i></span>
                                        <span>Account ID</span>
                                        <strong><?php echo esc_html($account_id_value); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div class="lrp-license-summary-card">
                                    <span class="lrp-license-summary-icon orange"><i class="far fa-calendar-alt" aria-hidden="true"></i></span>
                                    <span>Expires On</span>
                                    <strong><?php echo esc_html($license_date_display); ?></strong>
                                </div>
                            </div>

                            <div class="lrp-license-info-panel">
                                <h3>License Information</h3>
                                <table class="form-table lrp-user-details-table">
                                <?php if ($customer_type === 'loyalty') { ?>
                                    <tr>
                                        <th><span class="lrp-detail-icon"><i class="fas fa-key" aria-hidden="true"></i></span><span>License Key</span></th>
                                        <td>
                                            <span id="license_key" data-value="<?php echo esc_attr($license_key_value); ?>" data-hidden="true"><?php echo esc_html($license_key_masked); ?></span>
                                            <span class="toggle-visibility" data-field="license_key"><i class="fas fa-eye-slash"></i></span>
                                        </td>
                                    </tr>
                                    <?php if ( ! empty($product_code_value) ) : ?>
                                        <tr>
                                            <th><span class="lrp-detail-icon"><i class="fas fa-cube" aria-hidden="true"></i></span><span>Product Code</span></th>
                                            <td><?php echo esc_html($product_code_value); ?></td>
                                        </tr>
                                    <?php elseif ( ! empty($user_data['username']) ) : ?>
                                        <tr>
                                            <th><span class="lrp-detail-icon"><i class="far fa-user" aria-hidden="true"></i></span><span>Username</span></th>
                                            <td><?php echo esc_html($user_data['username']); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th><span class="lrp-detail-icon"><i class="far fa-calendar-alt" aria-hidden="true"></i></span><span>Plan End Date</span></th>
                                        <td><?php echo esc_html($license_date_display); ?></td>
                                    </tr>
                                <?php } elseif ($customer_type === 'netsuite') { ?>
                                    <tr>
                                        <th><span class="lrp-detail-icon"><i class="fas fa-key" aria-hidden="true"></i></span><span>License Key</span></th>
                                        <td>
                                            <span id="license_key" data-value="<?php echo esc_attr($license_key_value); ?>" data-hidden="true"><?php echo esc_html($license_key_masked); ?></span>
                                            <span class="toggle-visibility" data-field="license_key"><i class="fas fa-eye-slash"></i></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><span class="lrp-detail-icon"><i class="fas fa-cube" aria-hidden="true"></i></span><span>Product Code</span></th>
                                        <td>
                                            <span id="product_code" data-value="<?php echo esc_attr($product_code_value); ?>" data-hidden="false"><?php echo esc_html($product_code_value); ?></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><span class="lrp-detail-icon"><i class="far fa-id-card" aria-hidden="true"></i></span><span>Account ID</span></th>
                                        <td>
                                            <span id="account_id" data-value="<?php echo esc_attr($account_id_value); ?>" data-hidden="false"><?php echo esc_html($account_id_value); ?></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><span class="lrp-detail-icon"><i class="fas fa-link" aria-hidden="true"></i></span><span>License URL</span></th>
                                        <td>
                                            <span id="license_url_value" class="lrp-detail-url" data-value="<?php echo esc_attr($license_url_value); ?>"><?php echo esc_url($license_url_value); ?></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><span class="lrp-detail-icon"><i class="far fa-calendar-alt" aria-hidden="true"></i></span><span>Plan End Date</span></th>
                                        <td><?php echo esc_html($license_date_display); ?></td>
                                    </tr>
                                <?php } else { ?>
                                    <tr><td colspan="2">No user details � please log in above.</td></tr>
                                <?php } ?>
                                </table>
                            </div>
                            <div class="lrp-license-standing <?php echo esc_attr($days_remaining === 'Expired' ? 'expired' : 'active'); ?>">
                                <span><i class="fas <?php echo esc_attr($days_remaining === 'Expired' ? 'fa-times' : 'fa-check'); ?>" aria-hidden="true"></i></span>
                                <div>
                                    <strong><?php echo esc_html($license_good_standing); ?></strong>
                                    <p><?php echo esc_html($license_good_standing_note); ?></p>
                                </div>
                                <i class="fas fa-shield-alt lrp-license-standing-shield" aria-hidden="true"></i>
                            </div>
                        </div>
                    </div>
                    <div id="app-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>App Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="customer_signup_points">Customer Signup Points</label></th>
                                        <td><input type="number" id="customer_signup_points" name="customer_signup_points" value="<?php echo esc_attr($app_config ? $app_config->customer_signup_points : 50); ?>" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points awarded on user signup.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="product_review_points">Product Review Points</label></th>
                                        <td><input type="number" id="product_review_points" name="product_review_points" value="<?php echo esc_attr($app_config ? $app_config->product_review_points : 10); ?>" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points per product review.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="referral_points">Referral & Earn Points</label></th>
                                        <td><input type="number" id="referral_points" name="referral_points" value="<?php echo esc_attr($app_config ? $app_config->referral_points : 50); ?>" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points for referrals.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="birthday_points">Birthday Points</label></th>
                                        <td><input type="number" id="birthday_points" name="birthday_points" value="<?php echo esc_attr($app_config ? $app_config->birthday_points : 25); ?>" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points for birthdays.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="anniversary_points">Anniversary Points</label></th>
                                        <td><input type="number" id="anniversary_points" name="anniversary_points" value="<?php echo esc_attr($app_config ? $app_config->anniversary_points : 25); ?>" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points for anniversaries.</p></td>
                                    </tr>
                                </table>
                                <?php if(!$is_expired) submit_button('Save App Config'); ?>
                            </form>
                        </div>
                    </div>
                    <div id="points-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Points Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="each_point_value"> Point Value</label></th>
                                        <td><input type="number" id="each_point_value" name="each_point_value" value="<?php echo esc_attr($points_config ? $points_config->each_point_value : 10); ?>" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Value of each point in cents.</p></td>
                                    </tr>
                                    <tr>
                                                <th><label for="loyalty_point_value">Loyalty Point Equivalent</label></th>
                                                <td><input type="number" id="loyalty_point_value" name="loyalty_point_value" value="<?php echo esc_attr($points_config ? $points_config->loyalty_point_value : 1); ?>" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Value of loyalty points in dollars.</p></td>
                                            </tr>
                                            <tr>
    <th><label for="points_expiration_days">Points Expiration Days</label></th>
    <td>
        <?php
        $days_value = '';
        if ( ! empty( $points_config ) && isset( $points_config->points_expiration_days ) ) {
            $raw = trim( (string) $points_config->points_expiration_days );
            // If DB contains a numeric value, use it; otherwise leave blank.
            if ( $raw !== '' && ctype_digit( $raw ) ) {
                $days_value = intval( $raw );
            }
        }
        ?>
        <input type="number"
               id="points_expiration_days"
               name="points_expiration_days"
               value="<?php echo esc_attr( $days_value ); ?>"
               min="0"
               step="1"
               <?php if ( ! empty( $is_expired ) ) echo 'disabled'; ?>>
        <p class="description">Number of days after which points expire (enter an integer, e.g. 30). Leave empty for no automatic expiration.</p>
    </td>
</tr>
<!-- NEW: Giftcard expiry days, shown directly after the points expiry field -->
<tr>
    <th><label for="giftcard_expiry_days">Giftcard Expiry Days</label></th>
    <td>
        <?php
        $giftcard_days_value = '';
        if ( ! empty( $points_config ) && isset( $points_config->giftcard_expiry_days ) ) {
            // DB stored as INT — ensure we display integer value
            $raw_gc = $points_config->giftcard_expiry_days;
            if ( $raw_gc !== null && $raw_gc !== '' ) {
                $giftcard_days_value = intval( $raw_gc );
            }
        }
        ?>
        <input type="number"
               id="giftcard_expiry_days"
               name="giftcard_expiry_days"
               value="<?php echo esc_attr( $giftcard_days_value ); ?>"
               min="0"
               step="1"
               <?php if ( ! empty( $is_expired ) ) echo 'disabled'; ?>>
        <p class="description">Number of days after which gift cards expire (enter an integer). Leave empty for no expiry.</p>
    </td>
</tr>
<?php if ( $this->is_netsuite_customer() ) : ?>
 <tr>
                    <th><label for="netsuite_endpoint_url">NetSuite Endpoint URL</label></th>
                    <td>
                        <input type="url"
                               id="netsuite_endpoint_url"
                               name="netsuite_endpoint_url"
                               value="<?php echo esc_attr( $points_config ? $points_config->netsuite_endpoint_url : '' ); ?>"
                               style="width: 420px;"
                               placeholder="https://xxxxx.restlets.api.netsuite.com/..."
                               <?php if ( ! empty( $is_expired ) ) echo 'disabled'; ?>>
                        <p class="description">
                            Single NetSuite endpoint URL used to send Gift Card & Order data.
                        </p>
                    </td>
                </tr>
                <?php endif; ?>

                                </table>
                                <?php if(!$is_expired) submit_button('Save Points Config'); ?>
                            </form>
                        </div>
                    </div>
                    <div id="threshold-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Threshold Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="minimum_redemption_points">Customer Minimum Points</label></th>
                                        <td><input type="number" id="minimum_redemption_points" name="minimum_redemption_points" value="<?php echo esc_attr($threshold_config ? $threshold_config->minimum_redemption_points : 100); ?>" min="0" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Minimum points required to apply and use rewards.</p></td>
                                    </tr>
                                </table>
                                <?php if(!$is_expired) submit_button('Save Threshold Config'); ?>
                            </form>
                        </div>
                    </div>
                    <div id="loyalty-tiers" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Loyalty Tiers</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table id="loyalty-tiers-table" class="form-table wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th>Tier Name</th>
                                            <th>Threshold</th>
                                            <th>Points (per $)</th>
                                            <th>Level</th>
                                            <th>Active</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $index = 0; foreach ($loyalty_tiers as $tier) : ?>
                                        <tr data-index="<?php echo esc_attr($index); ?>">
                                            <td><input type="text" name="tier_data[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($tier->name); ?>" class="lrp-input" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td><input type="number" name="tier_data[<?php echo esc_attr($index); ?>][threshold]" value="<?php echo esc_attr($tier->threshold); ?>" class="lrp-input" min="0" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td><input type="number" name="tier_data[<?php echo esc_attr($index); ?>][points]" value="<?php echo esc_attr($tier->points); ?>" class="lrp-input" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td><input type="number" name="tier_data[<?php echo esc_attr($index); ?>][level]" value="<?php echo esc_attr($tier->level); ?>" class="lrp-input" min="1" required <?php if($is_expired) echo 'disabled'; ?>></td>
                                            <td>
                                                <input type="hidden" name="tier_data[<?php echo esc_attr($index); ?>][active]" value="0">
                                                <input type="checkbox" name="tier_data[<?php echo esc_attr($index); ?>][active]" value="1" <?php checked(1, $tier->active); ?> class="lrp-checkbox" <?php if($is_expired) echo 'disabled'; ?>>
                                            </td>
                                        </tr>
                                        <?php $index++; endforeach; ?>
                                    </tbody>
                                </table>
                                <p><?php if(!$is_expired) { ?><button type="button" class="button add-tier"><i class="fas fa-plus"></i> Add Tier</button><?php } ?></p>
                                <?php if(!$is_expired) submit_button('Save Loyalty Tiers'); ?>
                            </form>
                            <script>
                            jQuery(document).ready(function($) {
                                var isExpired = <?php echo json_encode($is_expired); ?>;
                                if (!isExpired) {
                                    $('.add-tier').on('click', function() {
                                        var nextIndex = $('#loyalty-tiers-table tbody tr').length;
                                        var newRow = '<tr data-index="' + nextIndex + '">' +
                                            '<td><input type="text" name="tier_data[' + nextIndex + '][name]" value="" class="lrp-input" required></td>' +
                                            '<td><input type="number" name="tier_data[' + nextIndex + '][threshold]" value="0" class="lrp-input" min="0" required></td>' +
                                            '<td><input type="number" name="tier_data[' + nextIndex + '][points]" value="2.00" class="lrp-input" min="0" step="0.01" required></td>' +
                                            '<td><input type="number" name="tier_data[' + nextIndex + '][level]" value="' + (nextIndex + 1) + '" class="lrp-input" min="1" required></td>' +
                                            '<td>' +
                                                '<input type="hidden" name="tier_data[' + nextIndex + '][active]" value="0">' +
                                                '<input type="checkbox" name="tier_data[' + nextIndex + '][active]" value="1" class="lrp-checkbox">' +
                                            '</td>' +
                                        '</tr>';
                                        $('#loyalty-tiers-table tbody').append(newRow);
                                    });
                                    $(document).on('click', '.remove-row', function() {
                                        $(this).closest('tr').remove();
                                    });
                                }
                            });
                            </script>
                        </div>
                    </div>
                    <div id="social-share-config" class="lrp-tab-pane">
                        <div class="lrp-card">
                            <h2>Social Share Configurations</h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('lrp_nonce', 'lrp_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th><label for="email_share_points">Email Share Points</label></th>
                                        <td><input type="number" id="email_share_points" name="email_share_points" value="<?php echo esc_attr($social_share_config ? $social_share_config->email_share_points : 20); ?>" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points awarded for sharing via email.</p></td>
                                    </tr>
                                    <tr>
                                        <th><label for="facebook_share_points">Facebook Share Points</label></th>
                                        <td><input type="number" id="facebook_share_points" name="facebook_share_points" value="<?php echo esc_attr($social_share_config ? $social_share_config->facebook_share_points : 20); ?>" min="0" step="0.01" required <?php if($is_expired) echo 'disabled'; ?>><p class="description">Points awarded for sharing on Facebook.</p></td>
                                    </tr>
                                </table>
                                <?php if(!$is_expired) submit_button('Save Social Share Config'); ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="lrp-settings-footer">
                <span><i class="fas fa-shield-alt" aria-hidden="true"></i> NetScore Loyalty Rewards</span>
                <span class="lrp-footer-divider"></span>
                <span><a href="<?php echo esc_url($docs_url); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation', 'netscore-loyalty-rewards' ); ?></a></span>
                <span class="lrp-footer-divider"></span>
                <span><a href="<?php echo esc_url($support_url); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Support', 'netscore-loyalty-rewards' ); ?></a></span>
                <span class="lrp-footer-divider"></span>
                <span>&copy; <?php echo esc_html(gmdate('Y')); ?> NetScore Technologies. All rights reserved.</span>
            </div>
        </div>
        <?php
    }

   private function ensure_config_decimal_columns() {
    global $wpdb;

    $table = $wpdb->prefix . 'netscore_lty_lty_config_table';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return;
    }

    $decimal_columns = [
        'customer_signup_points' => '50.00',
        'product_review_points'  => '10.00',
        'referral_points'        => '50.00',
        'birthday_points'        => '25.00',
        'anniversary_points'     => '25.00',
        'email_share_points'     => '20.00',
        'facebook_share_points'  => '20.00',
    ];

    foreach ( $decimal_columns as $column => $default ) {
        $wpdb->query( "ALTER TABLE {$table} MODIFY {$column} DECIMAL(10,2) NOT NULL DEFAULT {$default}" );
    }
}

   public function save_config() {
    // preserve original access check logic
    if ( ! $this->restrict_access() && ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'lrp-settings' ) ) {
        return;
    }

    global $wpdb;

    $this->ensure_config_decimal_columns();

    $app_config_table             = $wpdb->prefix . 'netscore_lty_lty_config_table';
    $points_config_table          = $wpdb->prefix . 'netscore_lty_lty_config_table';
    $threshold_config_table       = $wpdb->prefix . 'netscore_lty_lty_config_table';
    $loyalty_tiers_table         = $wpdb->prefix . 'netscore_lty_lty_tiers_table';
    $loyalty_tier_levels_table   = $wpdb->prefix . 'netscore_lty_tier_lvl_pts_table';
    $social_share_config_table   = $wpdb->prefix . 'netscore_lty_lty_config_table';

    $lrp_nonce = isset( $_POST['lrp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lrp_nonce'] ) ) : '';
    if ( $lrp_nonce && wp_verify_nonce( $lrp_nonce, 'lrp_nonce' ) ) {
        $errors = [];

        // ---------------- App config (customer_signup_points etc.) ----------------
        if ( isset( $_POST['customer_signup_points'] ) ) {
            $customer_signup_points = sanitize_text_field( wp_unslash( $_POST['customer_signup_points'] ) );
            $product_review_points  = sanitize_text_field( wp_unslash( $_POST['product_review_points'] ?? '' ) );
            $referral_points        = sanitize_text_field( wp_unslash( $_POST['referral_points'] ?? '' ) );
            $birthday_points        = sanitize_text_field( wp_unslash( $_POST['birthday_points'] ?? '' ) );
            $anniversary_points     = sanitize_text_field( wp_unslash( $_POST['anniversary_points'] ?? '' ) );

            // keep original intent but handle "0" correctly: check for empty string instead of empty()
            if ( $customer_signup_points === '' ) {
                $errors[] = 'Customer Signup Points is required';
            } elseif ( ! is_numeric( $customer_signup_points ) || $customer_signup_points < 0 ) {
                $errors[] = 'Invalid Customer Signup Points';
            }

            if ( $product_review_points === '' ) {
                $errors[] = 'Product Review Points is required';
            } elseif ( ! is_numeric( $product_review_points ) || $product_review_points < 0 ) {
                $errors[] = 'Invalid Product Review Points';
            }

            if ( $referral_points === '' ) {
                $errors[] = 'Referral Points is required';
            } elseif ( ! is_numeric( $referral_points ) || $referral_points < 0 ) {
                $errors[] = 'Invalid Referral Points';
            }

            if ( $birthday_points === '' ) {
                $errors[] = 'Birthday Points is required';
            } elseif ( ! is_numeric( $birthday_points ) || $birthday_points < 0 ) {
                $errors[] = 'Invalid Birthday Points';
            }

            if ( $anniversary_points === '' ) {
                $errors[] = 'Anniversary Points is required';
            } elseif ( ! is_numeric( $anniversary_points ) || $anniversary_points < 0 ) {
                $errors[] = 'Invalid Anniversary Points';
            }

            if ( empty( $errors ) ) {
                $res = $wpdb->update(
                    $app_config_table,
                    [
                        'customer_signup_points' => floatval( $customer_signup_points ),
                        'product_review_points'  => floatval( $product_review_points ),
                        'referral_points'        => floatval( $referral_points ),
                        'birthday_points'        => floatval( $birthday_points ),
                        'anniversary_points'     => floatval( $anniversary_points ),
                    ],
                    [ 'id' => 1 ],
                    [ '%f', '%f', '%f', '%f', '%f' ],
                    [ '%d' ]
                );

                if ( false === $res ) {
                    lrp_log( 'Database Update Error for app_configurations: ' . $wpdb->last_error );
                    $errors[] = 'Failed to save App Configurations';
                }
            }
        }

        // -------------------- Points config save (replacement) --------------------
if ( isset( $_POST['each_point_value'] ) ) {

    // Basic sanitize & un-slash
    $each_point_value_raw    = trim( sanitize_text_field( wp_unslash( $_POST['each_point_value'] ) ) );
    $loyalty_point_value_raw = trim( sanitize_text_field( wp_unslash( $_POST['loyalty_point_value'] ?? '' ) ) );
    $exp_raw                 = isset( $_POST['points_expiration_days'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['points_expiration_days'] ) ) ) : '';
    $giftcard_raw            = isset( $_POST['giftcard_expiry_days'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['giftcard_expiry_days'] ) ) ) : '';

    // ⭐ NEW: NetSuite Endpoint URL raw value (can be empty)
    $netsuite_url_raw        = isset( $_POST['netsuite_endpoint_url'] )
        ? trim( esc_url_raw( wp_unslash( $_POST['netsuite_endpoint_url'] ) ) )
        : '';

    // Validation
    if ( $each_point_value_raw === '' ) {
        $errors[] = 'Each Point Value is required';
    } elseif ( ! is_numeric( $each_point_value_raw ) || floatval( $each_point_value_raw ) <= 0 ) {
        $errors[] = 'Invalid Each Point Value';
    }

    if ( $loyalty_point_value_raw === '' ) {
        $errors[] = 'Loyalty Point Value is required';
    } elseif ( ! is_numeric( $loyalty_point_value_raw ) || floatval( $loyalty_point_value_raw ) <= 0 ) {
        $errors[] = 'Invalid Loyalty Point Value';
    }

    // Validate points expiration (empty => null)
    $points_expiration_value = null;
    if ( $exp_raw !== '' ) {
        if ( ctype_digit( $exp_raw ) ) {
            $points_expiration_value = intval( $exp_raw );
        } else {
            $errors[] = 'Points Expiration must be a non-negative whole number (e.g. 30) or left empty.';
        }
    }

    // Validate giftcard expiry (empty => null)
    $giftcard_expiry_value = null;
    if ( $giftcard_raw !== '' ) {
        if ( ctype_digit( $giftcard_raw ) ) {
            $giftcard_expiry_value = absint( $giftcard_raw );
        } else {
            $errors[] = 'Giftcard Expiry Days must be a non-negative whole number (e.g. 365) or left empty.';
        }
    }

    // ⭐ NEW: Normalize NetSuite endpoint (empty => null, otherwise esc_url_raw)
    $netsuite_endpoint_value = null;
    if ( $netsuite_url_raw !== '' ) {
        $netsuite_endpoint_value = esc_url_raw( $netsuite_url_raw );
        // Optional: simple validation – if esc_url_raw returns empty, treat as invalid
        if ( $netsuite_endpoint_value === '' ) {
            $errors[] = 'NetSuite Endpoint URL is invalid.';
        }
    }

    if ( empty( $errors ) ) {
        // Prepare update array
        $update = [
            'each_point_value'    => floatval( $each_point_value_raw ),
            'loyalty_point_value' => floatval( $loyalty_point_value_raw ),
            'updated_at'          => current_time( 'mysql' ),
        ];

        $formats = [ '%f', '%f', '%s' ]; // updated_at is a string (%s)

        // Add points_expiration_days (integer) or leave to NULL-set step
        if ( ! is_null( $points_expiration_value ) ) {
            $update['points_expiration_days'] = $points_expiration_value;
            $formats[] = '%d';
        }

        // Add giftcard_expiry_days (integer) or leave to NULL-set step
        if ( ! is_null( $giftcard_expiry_value ) ) {
            $update['giftcard_expiry_days'] = $giftcard_expiry_value;
            $formats[] = '%d';
        }

        // ⭐ NEW: NetSuite endpoint URL (string) or leave to NULL-set step
        if ( ! is_null( $netsuite_endpoint_value ) ) {
            $update['netsuite_endpoint_url'] = $netsuite_endpoint_value;
            $formats[] = '%s';
        }

        wp_cache_delete( 'lrp_netsuite_endpoint' );

        $where         = [ 'id' => 1 ];
        $where_formats = [ '%d' ];

        $result = $wpdb->update(
            $points_config_table,
            $update,
            $where,
            $formats,
            $where_formats
        );

        if ( false === $result ) {
            lrp_log( '[lrp] Database Update Error for points_configurations: ' . $wpdb->last_error );
            $errors[] = 'Failed to save Points Configurations: ' . esc_html( $wpdb->last_error );
        } else {
            // If any fields should be NULL, set them explicitly (so they are not left unchanged)
            $null_updates = [];

            if ( is_null( $points_expiration_value ) ) {
                $null_updates[] = "points_expiration_days = NULL";
            }
            if ( is_null( $giftcard_expiry_value ) ) {
                $null_updates[] = "giftcard_expiry_days = NULL";
            }
            // ⭐ NEW: clear NetSuite endpoint if field left empty
            if ( is_null( $netsuite_endpoint_value ) ) {
                $null_updates[] = "netsuite_endpoint_url = NULL";
            }

            if ( ! empty( $null_updates ) ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SET list is built from fixed column names above.
                $sql      = "UPDATE {$points_config_table} SET " . implode( ', ', $null_updates ) . " WHERE id = %d";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared; identifier/SET list is fixed plugin schema.
                $prepared = $wpdb->prepare( $sql, 1 );
                $wpdb->query( $prepared );
                if ( $wpdb->last_error ) {
                    lrp_log( '[lrp] Error setting NULL columns: ' . $wpdb->last_error );
                    $errors[] = 'Failed to clear expiration / endpoint columns: ' . esc_html( $wpdb->last_error );
                }
            }

            if ( empty( $errors ) ) {
                $success_message = 'Points configuration saved successfully.';
            }
        }
    }
}
// -------------------- end Points config save --------------------

        // ---------------- Threshold config ----------------
        if ( isset( $_POST['minimum_redemption_points'] ) ) {
            $minimum_redemption_points = sanitize_text_field( wp_unslash( $_POST['minimum_redemption_points'] ) );
            if ( $minimum_redemption_points === '' ) {
                $errors[] = 'Customer Minimum Points is required';
            } elseif ( ! is_numeric( $minimum_redemption_points ) || $minimum_redemption_points < 0 ) {
                $errors[] = 'Invalid Customer Minimum Points';
            } else {
                $res = $wpdb->update(
                    $threshold_config_table,
                    [ 'minimum_redemption_points' => (int) $minimum_redemption_points ],
                    [ 'id' => 1 ],
                    [ '%d' ],
                    [ '%d' ]
                );
                if ( false === $res ) {
                    lrp_log( 'Database Update Error for threshold_configurations: ' . $wpdb->last_error );
                    $errors[] = 'Failed to save Threshold Configurations: ' . $wpdb->last_error;
                }
            }
        }

        // ---------------- Loyalty tiers (unchanged logic except safer sanitization) ----------------
        $tier_data_raw = filter_input( INPUT_POST, 'tier_data', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
        if ( ! is_array( $tier_data_raw ) ) {
            $tier_data_raw = [];
        }
        $tier_data_raw = wp_unslash( $tier_data_raw );

        if ( is_array( $tier_data_raw ) ) {
            $tier_data = array_map(
                function( $tier ) {
                    return is_array( $tier ) ? array_map( 'sanitize_text_field', $tier ) : [];
                },
                $tier_data_raw
            );

            // basic validation loop
            foreach ( $tier_data as $i => $tier ) {
                $name      = isset( $tier['name'] ) ? trim( $tier['name'] ) : '';
                $threshold = isset( $tier['threshold'] ) ? $tier['threshold'] : '';
                $points    = isset( $tier['points'] ) ? $tier['points'] : '';
                $level     = isset( $tier['level'] ) ? $tier['level'] : '';
                $active    = isset( $tier['active'] ) ? $tier['active'] : 0;

                if ( $name === '' ) {
                    $errors[] = "Tier #{$i}: name is required.";
                }

                if ( ! is_numeric( $threshold ) || floatval( $threshold ) < 0 ) {
                    $errors[] = "Tier '{$name}': invalid threshold.";
                }

                if ( ! is_numeric( $points ) || floatval( $points ) < 0 ) {
                    $errors[] = "Tier '{$name}': invalid points.";
                }

                if ( ! is_numeric( $level ) || intval( $level ) < 1 ) {
                    $errors[] = "Tier '{$name}': invalid level.";
                }

                if ( ! in_array( intval( $active ), [ 0, 1 ], true ) ) {
                    $errors[] = "Tier '{$name}': invalid active flag.";
                }
            }

            if ( empty( $errors ) ) {
                // Start transaction (requires InnoDB)
                $wpdb->query( 'START TRANSACTION' );

                // Delete existing and reinsert (preserves original behavior)
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted plugin table identifier.
                $wpdb->query( "DELETE FROM {$loyalty_tier_levels_table}" );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted plugin table identifier.
                $wpdb->query( "DELETE FROM {$loyalty_tiers_table}" );

                $had_error = false;

                foreach ( $tier_data as $tier ) {
                    $name        = sanitize_text_field( $tier['name'] );
                    $description = isset( $tier['description'] ) ? sanitize_textarea_field( $tier['description'] ) : null;
                    $threshold   = floatval( $tier['threshold'] );
                    $points      = floatval( $tier['points'] );
                    $level       = intval( $tier['level'] );
                    $active      = intval( $tier['active'] ) === 1 ? 'active' : 'inactive';

                    $insert_tier = $wpdb->insert(
                        $loyalty_tiers_table,
                        [
                            'tier_name'   => $name,
                            'description' => $description,
                            'status'      => $active,
                        ],
                        [ '%s', '%s', '%s' ]
                    );

                    if ( false === $insert_tier ) {
                        $had_error = true;
                        $errors[]  = 'DB error inserting tier "' . esc_html( $name ) . '": ' . $wpdb->last_error;
                        break;
                    }

                    $tier_id = (int) $wpdb->insert_id;

                    $insert_level = $wpdb->insert(
                        $loyalty_tier_levels_table,
                        [
                            'tier_id'             => $tier_id,
                            'threshold'           => $threshold,
                            'points_for_currency' => $points,
                            'level'               => $level,
                        ],
                        [ '%d', '%f', '%f', '%d' ]
                    );

                    if ( false === $insert_level ) {
                        $had_error = true;
                        $errors[]  = 'DB error inserting tier level for "' . esc_html( $name ) . '": ' . $wpdb->last_error;
                        break;
                    }
                } // end foreach

                if ( $had_error ) {
                    $wpdb->query( 'ROLLBACK' );
                } else {
                    $wpdb->query( 'COMMIT' );
                }
            } // end if no validation errors
        }

        // ---------------- Social Share Config ----------------
        if ( isset( $_POST['email_share_points'] ) ) {
            $email_share_points    = sanitize_text_field( wp_unslash( $_POST['email_share_points'] ) );
            $facebook_share_points = sanitize_text_field( wp_unslash( $_POST['facebook_share_points'] ?? '' ) );

            if ( $email_share_points === '' ) {
                $errors[] = 'Email Share Points is required';
            } elseif ( ! is_numeric( $email_share_points ) || $email_share_points < 0 ) {
                $errors[] = 'Invalid Email Share Points';
            }

            if ( $facebook_share_points === '' ) {
                $errors[] = 'Facebook Share Points is required';
            } elseif ( ! is_numeric( $facebook_share_points ) || $facebook_share_points < 0 ) {
                $errors[] = 'Invalid Facebook Share Points';
            }

            if ( empty( $errors ) ) {
                $res = $wpdb->update(
                    $social_share_config_table,
                    [
                        'email_share_points'    => floatval( $email_share_points ),
                        'facebook_share_points' => floatval( $facebook_share_points ),
                    ],
                    [ 'id' => 1 ],
                    [ '%f', '%f' ],
                    [ '%d' ]
                );

                if ( false === $res ) {
                    lrp_log( 'Database Update Error for social_share_configurations: ' . $wpdb->last_error );
                    $errors[] = 'Failed to save Social Share Configurations';
                } else {
                }
            }
        }

        // ---------------- Handle result messages and redirect ----------------
        if ( ! empty( $errors ) ) {
            foreach ( $errors as $err ) {
                lrp_log( '[loyalty] ' . $err );
            }
            set_transient( 'lrp_admin_error', implode( '. ', $errors ), 30 );
        } else {
            set_transient( 'lrp_admin_notice', 'Configurations saved successfully.', 30 );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=lrp-settings' ) );
        exit;
    }
}

    public function add_loyalty_product_tab($tabs) {
    // Always show the loyalty product tab, but if expired, only show message in content
    $tabs['lrp_loyalty'] = array(
        'label' => __('NetScore Loyalty Rewards', 'NetScore Loyalty Rewards'),
        'target' => 'lrp_loyalty_product_data',
        'priority' => 25,
    );
    return $tabs;
}

    

    public function add_loyalty_product_tab_content() {
    global $post, $wpdb;
    $table_name = $wpdb->prefix . 'netscore_lty_item_lty_pts_table';
    $product_id = $post->ID;

    // Ensure columns exist (optional call)
    if ( method_exists( $this, 'ensure_item_points_table_columns' ) ) {
        $this->ensure_item_points_table_columns();
    }

    $data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE item_id = %d", $product_id ) );

    $enable_loyalty    = $data ? (bool) $data->is_eligible_for_loyalty_program : false;
    $enable_collection = $data ? (bool) $data->enable_collection_type : false;
    $collection_type   = $data ? $data->collection_type : 'points';
    $points_value      = $data ? (float) $data->points_based_points : 0;
    $sku_multiplier    = $data ? (float) $data->sku_based_points : 0;

    $is_expired = $this->is_license_expired();
    ?>

    <div id="lrp_loyalty_product_data" class="panel woocommerce_options_panel">
        <div class="options_group">
            <?php if ($is_expired): ?>
                <div style="padding:15px;border:1px solid #ffcc00;background:#fff8e1;margin-bottom:15px;">
                    <strong>Loyalty rewards disabled:</strong> License expired. Please renew your license.
                </div>
            <?php endif; ?>

            <?php if ($this->is_authenticated()): ?>

                <?php
                // Render checkboxes with the correct initial state (no postmeta involved)
                woocommerce_wp_checkbox(array(
                    'id'    => '_lrp_enable_loyalty',
                    'label' => __('Enable Loyalty Rewards for this product', 'NetScore Loyalty Rewards'),
                    'value' => $enable_loyalty ? 'yes' : 'no',
                    'cbvalue' => 'yes',
                ));
                ?>

                <div id="lrp_collection_enable_wrapper" style="margin-top:10px; display: <?php echo $enable_loyalty ? 'block' : 'none'; ?>;">
                    <?php
                    woocommerce_wp_checkbox(array(
                        'id'    => '_lrp_enable_collection',
                        'label' => __('Enable Collection Type', 'NetScore Loyalty Rewards'),
                        'value' => $enable_collection ? 'yes' : 'no',
                        'cbvalue' => 'yes',
                    ));
                    ?>
                </div>

                <div id="lrp_collection_wrapper" style="margin-top:10px; display: <?php echo $enable_collection ? 'block' : 'none'; ?>;">
                    <p class="form-field">
                        <label for="_lrp_collection_type"><?php esc_html_e('Collection Type', 'NetScore Loyalty Rewards'); ?></label>
                        <select id="_lrp_collection_type" name="_lrp_collection_type">
                            <option value="points" <?php selected($collection_type, 'points'); ?>>Points Based</option>
                            <option value="amount" <?php selected($collection_type, 'amount'); ?>>Amount Based</option>
                        </select>
                    </p>
                </div>

                <div id="lrp_points_based_wrapper" style="display: <?php echo ($enable_collection && $collection_type === 'points') ? 'block' : 'none'; ?>;">
                    <p class="form-field">
                        <label for="_lrp_points_value"><?php esc_html_e('Points Value', 'NetScore Loyalty Rewards'); ?></label>
                        <input type="number" id="_lrp_points_value" name="_lrp_points_value" value="<?php echo esc_attr($points_value); ?>" min="0" step="0.01">
                        <span class="description">Enter points to display in front-end (manual override).</span>
                    </p>
                </div>

                <div id="lrp_amount_based_wrapper" style="display: <?php echo ($enable_collection && $collection_type === 'amount') ? 'block' : 'none'; ?>;">
                    <p class="form-field">
                        <label for="_lrp_sku_multiplier"><?php esc_html_e('SKU Multiplier', 'NetScore Loyalty Rewards'); ?></label>
                        <input type="number" id="_lrp_sku_multiplier" name="_lrp_sku_multiplier" value="<?php echo esc_attr($sku_multiplier); ?>" step="0.01" min="0">
                        <span class="description">Multiplier used in amount-based calculation.</span>
                    </p>
                </div>
                <?php
                /**
                 * NEW: If this product is a NetSuite customer item, disable loyalty fields.
                 * This calls your existing method and does not modify other functionality.
                 */
                if ( method_exists( $this, 'is_netsuite_customer' ) && method_exists( $this, 'disable_fields_for_netsuite' ) ) {
                    if ( $this->is_netsuite_customer() ) {
                        $this->disable_fields_for_netsuite();
                    }
                }
                ?>

            <?php endif; ?>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {

        function toggleMain() {
            if ($('#_lrp_enable_loyalty').is(':checked')) {
                $('#lrp_collection_enable_wrapper').show();
            } else {
                $('#lrp_collection_enable_wrapper, #lrp_collection_wrapper, #lrp_points_based_wrapper, #lrp_amount_based_wrapper').hide();
            }
        }

        function toggleCollection() {
            if ($('#_lrp_enable_collection').is(':checked')) {
                $('#lrp_collection_wrapper').show();
                toggleCollectionType();
            } else {
                $('#lrp_collection_wrapper, #lrp_points_based_wrapper, #lrp_amount_based_wrapper').hide();
                $('#_lrp_collection_type').val('points');
            }
        }

        function toggleCollectionType() {
            var type = $('#_lrp_collection_type').val();
            if (type === 'points') {
                $('#lrp_points_based_wrapper').show();
                $('#lrp_amount_based_wrapper').hide();
            } else {
                $('#lrp_points_based_wrapper').hide();
                $('#lrp_amount_based_wrapper').show();
            }
        }

        $('#_lrp_enable_loyalty').on('change', toggleMain);
        $('#_lrp_enable_collection').on('change', toggleCollection);
        $('#_lrp_collection_type').on('change', toggleCollectionType);

        toggleMain();
        toggleCollection();
    });
    </script>

    <?php
}




    public function save_product_meta($post_id) {
    // Only run on product save; WP will pass autosave, revision checks to you usually before calling this.
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( $this->is_license_expired() || ! $this->is_authenticated() ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'netscore_lty_item_lty_pts_table';

    // Read all field values safely (use name attributes from form)
    $enable_loyalty     = isset($_POST['_lrp_enable_loyalty']) ? 1 : 0;
    $enable_collection  = isset($_POST['_lrp_enable_collection']) ? 1 : 0;
    $collection_type    = isset($_POST['_lrp_collection_type']) ? sanitize_text_field( $_POST['_lrp_collection_type'] ) : 'points';
    $points_value       = isset($_POST['_lrp_points_value']) ? floatval( $_POST['_lrp_points_value'] ) : 0;
    $sku_multiplier     = isset($_POST['_lrp_sku_multiplier']) ? floatval( $_POST['_lrp_sku_multiplier'] ) : 0;

    // Ensure table columns exist before inserting/updating
    if ( method_exists( $this, 'ensure_item_points_table_columns' ) ) {
        $this->ensure_item_points_table_columns();
    }

    // Build data array for insert/update
    $now = current_time('mysql');
    $data = array(
        'item_id'                         => $post_id,
        'is_eligible_for_loyalty_program' => $enable_loyalty,
        'enable_collection_type'          => $enable_collection,
        'collection_type'                 => $collection_type,
        'points_based_points'             => $points_value,
        'sku_based_points'                => $sku_multiplier,
        'updated_at'                      => $now,
    );

    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE item_id = %d", $post_id ) );

    if ( $exists ) {
        // Update
        $wpdb->update( $table, $data, array( 'item_id' => $post_id ), null, array( '%d' ) );
    } else {
        // Insert: add created_at as well
        $data['created_at'] = $now;
        $wpdb->insert( $table, $data );
    }
}




    /**
     * Send payload to NetSuite endpoint configured in Points Settings.
     *
     * @param string $event_type e.g. 'order_created', 'gift_card_created'.
     * @param array  $data       Event data to send.
     */
    public static function send_to_netsuite( $event_type, $data = array() ) {
        // TODO: If your option name is different, change 'lrp_points_settings'
        $points_settings = get_option( 'lrp_points_settings', array() );

        // Field you said is already created in Points Config section:
        // netsuite_endpoint_url
        $endpoint_url = '';
        if ( ! empty( $points_settings['netsuite_endpoint_url'] ) ) {
            $endpoint_url = trim( $points_settings['netsuite_endpoint_url'] );
        }

        if ( empty( $endpoint_url ) || ! filter_var( $endpoint_url, FILTER_VALIDATE_URL ) ) {
            lrp_log( '[LRP] NetSuite endpoint URL missing or invalid.' );
            return;
        }

        // Build payload
        $payload = array(
            'marketplace' => 'woocommerce',                    // as you requested
            'event_type'  => $event_type,                      // 'order_created', 'gift_card_created', etc.
            'site_url'    => get_site_url(),
            'timestamp'   => current_time( 'mysql', true ),
            'data'        => $data,                            // actual business data
        );

        $args = array(
            'method'      => 'POST',
            'timeout'     => 30,
            'headers'     => array(
                'Content-Type' => 'application/json',
            ),
            'body'        => wp_json_encode( $payload ),
        );

        $response = wp_remote_post( $endpoint_url, $args );

        if ( is_wp_error( $response ) ) {
            lrp_log( '[LRP] NetSuite API error: ' . $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            lrp_log( '[LRP] NetSuite API HTTP ' . $code . ' Response: ' . $body );
        }
    }
}
/**
 * Add NetScore Loyalty Rewards section to user profile
 */
class LRP_User_Profile {

    public function __construct() {
        add_action( 'show_user_profile', [ $this, 'add_loyalty_fields' ] );
        add_action( 'edit_user_profile', [ $this, 'add_loyalty_fields' ] );
        add_action( 'personal_options_update', [ $this, 'save_loyalty_fields' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_loyalty_fields' ] );
    }

    /**
     * Output fields on user profile. Values are taken from custom table
     * wp_{prefix}netscore_lty_cust_lty_pts_table. If no row exists, fall back to usermeta.
     *
     * @param WP_User $user
     */
    public function add_loyalty_fields( $user ) {
    global $wpdb;

    $pts_table = $wpdb->prefix . 'netscore_lty_cust_lty_pts_table';

    // Detect NetSuite mode
    $is_netsuite = false;
    if ( method_exists( 'LRP_Utils', 'get_admin_customer_type' ) ) {
        $is_netsuite = ( LRP_Utils::get_admin_customer_type() === 'netsuite' );
    }

    $disabled_attr = $is_netsuite ? 'disabled="disabled"' : '';

    // Try to fetch from custom table
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$pts_table} WHERE customer_id = %d LIMIT 1",
            $user->ID
        ),
        ARRAY_A
    );

    // Fallback to user meta
    $is_eligible = is_array( $row ) && isset( $row['is_eligible_for_loyalty_program'] )
        ? $row['is_eligible_for_loyalty_program']
        : get_user_meta( $user->ID, 'is_eligible_for_loyalty', true );

    $birthdate = is_array( $row ) ? $row['birthdate'] : get_user_meta( $user->ID, 'loyalty_birthday', true );
    $anniv     = is_array( $row ) ? $row['anniversary_date'] : get_user_meta( $user->ID, 'loyalty_anniversary', true );
    if ( $is_netsuite ) {
    // NetSuite → DB first, meta fallback
    $referred = ! empty( $row['referral_code_by_friend'] )
        ? $row['referral_code_by_friend']
        : get_user_meta( $user->ID, 'referral_code', true );
} else {
    // Loyalty → meta first, DB fallback
    $referred = get_user_meta( $user->ID, 'referral_code_used', true );

    if ( empty( $referred ) && ! empty( $row['referral_code_by_friend'] ) ) {
        $referred = $row['referral_code_by_friend'];
    }
}
    $ref_code  = is_array( $row ) ? $row['referral_code'] : get_user_meta( $user->ID, 'loyalty_referral_code', true );
    $eligible_dt = is_array( $row ) ? $row['loyalty_eligible_date'] : '';

    $is_eligible = (string) intval( $is_eligible );
    ?>
    <h2>NetScore Loyalty Rewards</h2>

    <table class="form-table" role="presentation">

    <tr>
        <th><label for="is_eligible_for_loyalty">Eligible for Loyalty Program</label></th>
        <td>
            <?php if ( ! $is_netsuite ): ?>
                <input type="hidden" name="is_eligible_for_loyalty" value="0">
            <?php endif; ?>

            <input type="checkbox"
           name="is_eligible_for_loyalty"
           id="is_eligible_for_loyalty"
           value="1"
           <?php checked( $is_eligible, '1' ); ?>
           <?php disabled( $is_netsuite ); ?> />

            <?php if ( $is_netsuite ): ?>
                <input type="hidden"
                       name="is_eligible_for_loyalty"
                       value="<?php echo esc_attr( $is_eligible ); ?>">
            <?php endif; ?>

            <p class="description">
                <?php
                echo $is_netsuite
                    ? 'This field is managed by NetSuite and cannot be edited.'
                    : 'Check if this customer is eligible for the loyalty program.';
                ?>
            </p>
        </td>
    </tr>

    <tr>
        <th><label for="loyalty_birthday">Birthday</label></th>
        <td>
            <input type="date"
                   name="loyalty_birthday"
                   id="loyalty_birthday"
                   value="<?php echo esc_attr( $birthdate ); ?>"
                   <?php disabled( $is_netsuite ); ?> />

            <?php if ( $is_netsuite ): ?>
                <p class="description">Managed by NetSuite.</p>
            <?php endif; ?>
        </td>
    </tr>

    <tr>
        <th><label for="loyalty_anniversary">Anniversary Date</label></th>
        <td>
            <input type="date"
                   name="loyalty_anniversary"
                   id="loyalty_anniversary"
                   value="<?php echo esc_attr( $anniv ); ?>"
                   <?php disabled( $is_netsuite ); ?> />
        </td>
    </tr>

    <tr>
        <th><label for="loyalty_referred_friend">Referred Friend</label></th>
        <td>
            <input type="text"
                   name="loyalty_referred_friend"
                   id="loyalty_referred_friend"
                   value="<?php echo esc_attr( $referred ); ?>"
                   <?php disabled( $is_netsuite ); ?> />
        </td>
    </tr>

    <tr>
        <th><label for="loyalty_referral_code">Customer Referral Code</label></th>
        <td>
            <input type="text"
                   name="loyalty_referral_code"
                   id="loyalty_referral_code"
                   value="<?php echo esc_attr( $ref_code ); ?>"
                   <?php disabled( $is_netsuite ); ?> />

            <?php if ( $is_netsuite ): ?>
                <p class="description">Managed by NetSuite.</p>
            <?php endif; ?>
        </td>
    </tr>

    <tr>
        <th><label for="loyalty_eligible_date">Loyalty Eligible Date</label></th>
        <td>
            <input type="date"
                   name="loyalty_eligible_date"
                   id="loyalty_eligible_date"
                   value="<?php echo esc_attr( $eligible_dt ); ?>"
                   <?php disabled( $is_netsuite ); ?> />
        </td>
    </tr>

    </table>
    <?php
}

    /**
     * Save loyalty fields into custom customer table (insert or update).
     *
     * @param int $user_id
     * @return bool
     */
    public function save_loyalty_fields( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }

        $customer_type = '';
        $is_netsuite = false;
        if ( method_exists( 'LRP_Utils', 'get_admin_customer_type' ) ) {
            $customer_type = LRP_Utils::get_admin_customer_type();
            $is_netsuite = ( $customer_type === 'netsuite' );
        }

        if ( $is_netsuite ) {
            // Do not overwrite NetSuite-controlled data.
            return true;
        }

        global $wpdb;
        $pts_table = $wpdb->prefix . 'netscore_lty_cust_lty_pts_table';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pts_table ) ) !== $pts_table ) {
            if ( class_exists( 'LRP_Activator' ) ) {
                LRP_Activator::activate();
            }

            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pts_table ) ) !== $pts_table ) {
                lrp_log( 'LRP: Could not save loyalty profile fields because table is missing: ' . $pts_table );
                return false;
            }
        }

            // Fetch existing value from DB
            $existing_eligible = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT is_eligible_for_loyalty_program FROM {$pts_table} WHERE customer_id = %d LIMIT 1",
                    $user_id
                )
            );

        // Only update if field was actually submitted
        $is_eligible = isset( $_POST['is_eligible_for_loyalty'] )
            ? ( intval( wp_unslash( $_POST['is_eligible_for_loyalty'] ) ) ? 1 : 0 )
            : ( $existing_eligible !== null ? (int) $existing_eligible : 0 );
        $birthdate   = isset( $_POST['loyalty_birthday'] ) && $_POST['loyalty_birthday'] !== '' ? sanitize_text_field( wp_unslash( $_POST['loyalty_birthday'] ) ) : null;
        $anniv       = isset( $_POST['loyalty_anniversary'] ) && $_POST['loyalty_anniversary'] !== '' ? sanitize_text_field( wp_unslash( $_POST['loyalty_anniversary'] ) ) : null;
        $referred    = isset( $_POST['loyalty_referred_friend'] ) ? sanitize_text_field( wp_unslash( $_POST['loyalty_referred_friend'] ) ) : null;
        $ref_code    = isset( $_POST['loyalty_referral_code'] ) ? sanitize_text_field( wp_unslash( $_POST['loyalty_referral_code'] ) ) : null;
        $eligible_dt = isset( $_POST['loyalty_eligible_date'] ) && $_POST['loyalty_eligible_date'] !== '' ? sanitize_text_field( wp_unslash( $_POST['loyalty_eligible_date'] ) ) : null;

        // Prepare data for update/insert
        $data = [
            'is_eligible_for_loyalty_program' => $is_eligible,
            'birthdate' => $birthdate,
            'anniversary_date' => $anniv,
            'referral_code_by_friend' => $referred,
            'referral_code' => $ref_code,
            'loyalty_eligible_date' => $eligible_dt,
            'updated_at' => current_time( 'mysql' ),
        ];

        // Check if a row exists for this customer
        $exists_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$pts_table} WHERE customer_id = %d LIMIT 1",
            $user_id
        ) );

        if ( $exists_id ) {
            // Update existing row
            $where = [ 'customer_id' => $user_id ];
            $formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]; // match $data order
            $updated = $wpdb->update( $pts_table, $data, $where, $formats, [ '%d' ] );
            if ( $updated === false ) {
                lrp_log( 'LRP: Failed to update loyalty profile fields for user ' . $user_id . ': ' . $wpdb->last_error );
                return false;
            }
        } else {
            // Insert new row (set customer_id and created_at)
            $data_insert = $data;
            $data_insert['customer_id'] = $user_id;
            $data_insert['created_at'] = current_time( 'mysql' );

            $inserted = $wpdb->insert( $pts_table, $data_insert );
            if ( $inserted === false ) {
                lrp_log( 'LRP: Failed to insert loyalty profile fields for user ' . $user_id . ': ' . $wpdb->last_error );
                return false;
            }
        }

        // Optional: remove usermeta duplicates so data source is single (commented out)
        // delete_user_meta( $user_id, 'is_eligible_for_loyalty' );
        // delete_user_meta( $user_id, 'loyalty_birthday' );
        // delete_user_meta( $user_id, 'loyalty_anniversary' );
        // delete_user_meta( $user_id, 'loyalty_referred_friend' );
        // delete_user_meta( $user_id, 'loyalty_referral_code' );

        if ( $customer_type === 'loyalty' && (int) $is_eligible === 1 ) {
            do_action( 'lrp_award_signup_points_if_eligible', $user_id );
        }

        return true;
    }
}

if ( function_exists( 'add_action' ) ) {
    new LRP_User_Profile();
}
?>
