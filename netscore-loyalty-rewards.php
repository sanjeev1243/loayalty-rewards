<?php
/**
 * Plugin Name: NetScore Loyalty Rewards for WooCommerce
 * Plugin URI: https://wooloyalty.netscoreapps.com/
 * Description: A WooCommerce loyalty and rewards plugin that enables merchants to reward customers with points for purchases and allow point redemption during checkout.
 * Version: 1.0.0
 * Author: NetScore Technologies
 * Author URI: https://www.netscoretech.com/
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * WC requires at least: 9.0
 * WC tested up to: 10.9.4
 * Text Domain: netscore-loyalty-rewards
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('lrp_log')) {
    function lrp_log($message)
    {
        unset($message);
    }
}

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

define('LRP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LRP_PLUGIN_URL', plugin_dir_url(__FILE__));

/* Admin Classes */
require_once LRP_PLUGIN_DIR . 'admin/class-lrp-admin.php';
require_once LRP_PLUGIN_DIR . 'admin/class-lrp-settings.php';
require_once LRP_PLUGIN_DIR . 'admin/class-lrp-customers.php';
require_once LRP_PLUGIN_DIR . 'admin/class-lrp-events.php';
require_once LRP_PLUGIN_DIR . 'admin/class-lrp-items.php';
require_once LRP_PLUGIN_DIR . 'admin/class-lrp-features.php';
require_once LRP_PLUGIN_DIR . 'admin/class-lrp-gift-cards.php';
require_once LRP_PLUGIN_DIR . 'admin/class-lrp-customer-events.php';

/* Frontend */
require_once LRP_PLUGIN_DIR . 'frontend/class-lrp-frontend.php';
require_once LRP_PLUGIN_DIR . 'frontend/class-lrp-account.php';
require_once LRP_PLUGIN_DIR . 'frontend/class-lrp-checkout.php';
require_once LRP_PLUGIN_DIR . 'frontend/class-lrp-referrals.php';
require_once LRP_PLUGIN_DIR . 'frontend/class-lrp-tier-level-cal.php';

/* Core Includes */
require_once LRP_PLUGIN_DIR . 'includes/class-lrp-activator.php';
require_once LRP_PLUGIN_DIR . 'includes/lrp-functions.php';
require_once LRP_PLUGIN_DIR . 'includes/class-lrp-utils.php';

/* Integrations */
require_once LRP_PLUGIN_DIR . 'integrations/netsuite/class-lrp-netsuite.php';
require_once LRP_PLUGIN_DIR . 'integrations/netsuite/class-lrp-netsuite-api.php';
require_once LRP_PLUGIN_DIR . 'integrations/netsuite/class-netsuite-sync.php';

/* REST APIs */
require_once LRP_PLUGIN_DIR . 'apis/lrp-api-endpoints.php';
require_once LRP_PLUGIN_DIR . 'apis/ns-customer-api.php';
require_once LRP_PLUGIN_DIR . 'apis/class-config-api.php';
require_once LRP_PLUGIN_DIR . 'apis/class-features-api.php';
require_once LRP_PLUGIN_DIR . 'apis/class-tier-api.php';
require_once LRP_PLUGIN_DIR . 'apis/class-product-api.php';
require_once LRP_PLUGIN_DIR . 'apis/class-lrp-items-api.php';
require_once LRP_PLUGIN_DIR . 'apis/class-orders-import-api.php';
require_once LRP_PLUGIN_DIR . 'apis/ns-wc-product-api.php';
require_once LRP_PLUGIN_DIR . 'apis/ns-loyalty-product-api.php';




// Hook after plugins are loaded (or just call directly if you prefer)
add_action('plugins_loaded', function () {
    if (class_exists('LRP_NetSuite_Coupon_Webhook')) {
        LRP_NetSuite_Coupon_Webhook::init();
    }
});

// Schedule daily birthday/anniversary check on activation
register_activation_hook(__FILE__, 'lrp_schedule_daily_special_dates_check');
register_deactivation_hook(__FILE__, 'lrp_clear_daily_special_dates_check');
add_action('wp', 'lrp_manage_daily_special_dates_schedule');

if (!function_exists('lrp_is_netsuite_customer_type')) {
    function lrp_is_netsuite_customer_type()
    {
        return (
            class_exists('LRP_Utils')
            && method_exists('LRP_Utils', 'get_admin_customer_type')
            && LRP_Utils::get_admin_customer_type() === 'netsuite'
        );
    }
}

function lrp_schedule_daily_special_dates_check()
{
    if (lrp_is_netsuite_customer_type()) {
        lrp_clear_daily_special_dates_check();
        return;
    }

    if (!wp_next_scheduled('lrp_daily_special_dates_check')) {

        // Next local midnight + 5 minutes
        $now = current_time('timestamp');
        $tomorrow = strtotime('tomorrow', $now);
        $first_run = $tomorrow + 5 * 60; // 00:05

        wp_schedule_event($first_run, 'daily', 'lrp_daily_special_dates_check');
    }
}

function lrp_clear_daily_special_dates_check()
{
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('lrp_daily_special_dates_check');
        return;
    }

    $timestamp = wp_next_scheduled('lrp_daily_special_dates_check');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'lrp_daily_special_dates_check');
    }
}

function lrp_manage_daily_special_dates_schedule()
{
    if (lrp_is_netsuite_customer_type()) {
        lrp_clear_daily_special_dates_check();
        return;
    }

    lrp_schedule_daily_special_dates_check();
}

function run_lrp()
{
    $activator = new LRP_Activator();
    register_activation_hook(__FILE__, array($activator, 'activate'));
    register_deactivation_hook(__FILE__, array($activator, 'deactivate'));

    $admin = new LRP_Admin();
    $frontend = new LRP_Frontend();
    $lrp_items_page = new LRP_Items_Page();
    $lrp_events_page = new LRP_Events_Page();
}
run_lrp();

add_action('admin_footer', 'lrp_confirm_before_deactivate');
function lrp_confirm_before_deactivate()
{
    if (!is_admin()) {
        return;
    }
    if (!function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || !isset($screen->id)) {
        return;
    }

    if ('plugins' !== $screen->id) {
        return;
    }

    $pluginSlug = 'netscore-loyalty-rewards';
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            const pluginSlug = '<?php echo esc_js($pluginSlug); ?>';
            const deactivateLinks = document.querySelectorAll('tr[data-slug="' + pluginSlug + '"] .deactivate a');
            deactivateLinks.forEach(function (link) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    const confirmBackup = confirm("⚠️ Have you taken a database backup before deactivating the NetScore Loyalty Rewards plugin?\n\nClick OK for Yes, Cancel for No.");
                    if (confirmBackup) {
                        const href = e.target && e.target.href ? e.target.href : link.getAttribute('href');
                        if (href) {
                            window.location.href = href;
                        }
                    } else {
                        alert("Deactivation cancelled. Please take a database backup before proceeding.");
                    }
                });
            });
        });
    </script>
    <?php
}
