<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings Page Handler for NetScore Loyalty Rewards
 */
class LRP_Settings_Page {

    /**
     * Render the main Loyalty Rewards Setup / Settings page.
     *
     * @param LRP_Admin $admin Admin controller instance.
     */
    public static function render( $admin ) {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'netscore-loyalty-rewards' ) );
        }

        // Delegate to existing robust settings tabs in admin controller
        if ( method_exists( $admin, 'settings_page' ) ) {
            $admin->settings_page();
        }
    }
}
