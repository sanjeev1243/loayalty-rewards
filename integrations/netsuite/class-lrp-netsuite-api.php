<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Table identifiers are built from the trusted WordPress prefix and plugin-owned suffixes.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Single NetSuite API helper.
 * All events (order, giftcard, etc.) POST to ONE URL from netscore_lty_lty_config_table.
 */
class LRP_NetSuite_API {

    /**
     * Get NetSuite endpoint URL from config table.
     *
     * @return string
     */
    public static function get_endpoint() {
        $cache_key = 'lrp_netsuite_endpoint';
        $endpoint  = wp_cache_get( $cache_key );

        if ( false !== $endpoint ) {
            return $endpoint;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'netscore_lty_lty_config_table';
        $table_escaped = str_replace( '`', '``', $table );

        $url = $wpdb->get_var(
            "SELECT netsuite_endpoint_url
             FROM `{$table_escaped}`
             WHERE netsuite_endpoint_url IS NOT NULL
               AND netsuite_endpoint_url <> ''
             ORDER BY id ASC
             LIMIT 1"
        );

        if ( empty( $url ) ) {
            wp_cache_set( $cache_key, '' );
            return '';
        }

        $clean = esc_url_raw( $url );

        wp_cache_set( $cache_key, $clean );

        return $clean;
    }

    /**
     * Send payload to NetSuite.
     *
     * @param string $event_type e.g. 'order_created', 'giftcard_created'.
     * @param array  $payload    Payload array; must already contain 'marketplace' key.
     *
     * @return array|WP_Error
     */
    public static function send_event( $event_type, array $payload ) {
        $endpoint = self::get_endpoint();

        if ( empty( $endpoint ) ) {
            return new WP_Error( 'lrp_no_endpoint', 'NetSuite endpoint URL not configured.' );
        }

        // Ensure marketplace exists
        if ( empty( $payload['marketplace'] ) ) {
            $payload['marketplace'] = 'woocommerce';
        }

        $body = $payload;
        $body['event_type'] = (string) $event_type;

        $args = [
            'method'  => 'POST',
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ];

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'lrp_netsuite_http_error', 'NetSuite returned HTTP ' . $code . '.' );
        }

        return $response;
    }
}
