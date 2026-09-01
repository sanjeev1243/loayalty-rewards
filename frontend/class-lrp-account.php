<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Customer My Account Loyalty Dashboard Handler
 *
 * Manages the Loyalty Rewards My Account endpoints, navigation items,
 * point history tables, tier views, and profile update forms.
 *
 * @package NetScore Loyalty Rewards
 */
class LRP_Account {

    /**
     * Register My Account rewrite endpoints.
     */
    public static function register_endpoints() {
        add_rewrite_endpoint( 'loyalty-points', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'loyalty-points-earned', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'redeem-points-history', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'generate-gift-card', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'refer-friend', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'update-profile', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'loyalty-tiers', EP_ROOT | EP_PAGES );
    }

    /**
     * Check if a specific feature is enabled for the account view.
     *
     * @param string $field Feature field key.
     * @return bool
     */
    public static function is_feature_enabled( $field ) {
        global $wpdb;
        $table = $wpdb->prefix . 'netscore_lty_features_table';
        $val = $wpdb->get_var( $wpdb->prepare( "SELECT `{$field}` FROM `{$table}` ORDER BY id ASC LIMIT 1" ) );
        if ( is_null( $val ) ) {
            return true;
        }
        return (int) $val === 1;
    }

    /**
     * Render the disabled feature notice.
     *
     * @param string $feature_name
     */
    public static function render_disabled_message( $feature_name ) {
        echo '<div class="woocommerce-error" style="margin:20px 0;">' .
            sprintf( esc_html__( '%s is currently disabled by the store administrator.', 'netscore-loyalty-rewards' ), esc_html( $feature_name ) ) .
            '</div>';
    }
}
