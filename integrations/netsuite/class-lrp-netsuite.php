<?php
/**
 * NetSuite Integration Module for NetScore Loyalty Rewards
 *
 * Provides decoupled, optional enterprise synchronization between
 * WooCommerce and Oracle NetSuite ERP.
 *
 * @package NetScore Loyalty Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LRP_NetSuite_Integration {

    /**
     * Single instance of this class.
     *
     * @var LRP_NetSuite_Integration|null
     */
    private static $instance = null;

    /**
     * Returns singleton instance.
     *
     * @return LRP_NetSuite_Integration
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required NetSuite integration components.
     */
    private function load_dependencies() {
        if ( file_exists( __DIR__ . '/class-lrp-netsuite-api.php' ) ) {
            require_once __DIR__ . '/class-lrp-netsuite-api.php';
        }
        if ( file_exists( __DIR__ . '/class-netsuite-sync.php' ) ) {
            require_once __DIR__ . '/class-netsuite-sync.php';
        }
    }

    /**
     * Initialize integration hooks.
     */
    private function init_hooks() {
        add_action( 'plugins_loaded', [ $this, 'init_webhooks' ] );
    }

    /**
     * Initialize NetSuite webhooks and listeners.
     */
    public function init_webhooks() {
        if ( class_exists( 'LRP_NetSuite_Coupon_Webhook' ) ) {
            LRP_NetSuite_Coupon_Webhook::init();
        }
    }

    /**
     * Check if NetSuite integration is actively configured by the merchant.
     *
     * @return bool
     */
    public static function is_configured() {
        $auth_token = get_option( 'lrp_netsuite_auth_token', '' );
        return ! empty( $auth_token );
    }

    /**
     * Check if the store is running in NetSuite ERP synchronized mode.
     *
     * @return bool
     */
    public static function is_netsuite_customer() {
        if ( function_exists( 'lrp_is_netsuite_customer' ) ) {
            return (bool) lrp_is_netsuite_customer();
        }
        return false;
    }
}
