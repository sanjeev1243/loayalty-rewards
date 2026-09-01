<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Referrals & Social Sharing Handler
 *
 * Manages customer referral codes, registration tracking,
 * email referral invitations, and social sharing bonuses.
 *
 * @package NetScore Loyalty Rewards
 */
class LRP_Referrals {

    /**
     * Get or generate a unique referral code for a user.
     *
     * @param int $user_id WordPress User ID.
     * @return string Referral code.
     */
    public static function get_referral_code( $user_id ) {
        if ( ! $user_id ) {
            return '';
        }

        global $wpdb;
        $pts_table = $wpdb->prefix . 'netscore_lty_cust_lty_pts_table';

        $code = $wpdb->get_var( $wpdb->prepare( "SELECT referral_code FROM {$pts_table} WHERE customer_id = %d LIMIT 1", $user_id ) );

        if ( empty( $code ) ) {
            $user = get_userdata( $user_id );
            $prefix = $user ? strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', $user->user_login ), 0, 4 ) ) : 'USER';
            $code = $prefix . '-' . strtoupper( wp_generate_password( 6, false, false ) );

            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pts_table} WHERE customer_id = %d", $user_id ) );
            if ( $exists ) {
                $wpdb->update( $pts_table, [ 'referral_code' => $code ], [ 'customer_id' => $user_id ], [ '%s' ], [ '%d' ] );
            }
        }

        return $code;
    }

    /**
     * Build customer shareable referral URL.
     *
     * @param string $referral_code
     * @return string
     */
    public static function get_referral_url( $referral_code ) {
        return add_query_arg( 'ref', $referral_code, wc_get_page_permalink( 'myaccount' ) );
    }
}
