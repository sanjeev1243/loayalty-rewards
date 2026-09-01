<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checkout & Cart Loyalty Redemption Handler
 *
 * Manages point redemptions on checkout, discount calculations,
 * and order meta recording for loyalty points.
 *
 * @package NetScore Loyalty Rewards
 */
class LRP_Checkout {

    /**
     * Clear loyalty session data after order completion.
     */
    public static function clear_session() {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( 'applied_loyalty_points' );
            WC()->session->__unset( 'applied_loyalty_discount' );
            WC()->session->__unset( 'lrp_points_applied' );
        }
    }

    /**
     * Calculate maximum redeemable points for the current cart total.
     *
     * @param float $cart_total Total eligible cart amount.
     * @param float $points_conversion Points per currency unit.
     * @param float $available_points Customer's available point balance.
     * @param float $max_redemption Maximum points allowed per order.
     * @return float
     */
    public static function calculate_max_redeemable( $cart_total, $points_conversion, $available_points, $max_redemption = 0 ) {
        if ( $points_conversion <= 0 ) {
            return 0.0;
        }

        $max_points_for_cart = $cart_total * $points_conversion;
        $redeemable = min( $available_points, $max_points_for_cart );

        if ( $max_redemption > 0 ) {
            $redeemable = min( $redeemable, $max_redemption );
        }

        return max( 0.0, (float) $redeemable );
    }
}
