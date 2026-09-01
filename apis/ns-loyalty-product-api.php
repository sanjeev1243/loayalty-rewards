<?php
/**
 * REST endpoint for resolving a WooCommerce product ID by SKU.
 *
 * This file is loaded by NetScore-loyalty-rewards.php and is not a
 * standalone WordPress plugin.
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('lrp/v1', '/get-items', [
        'methods'  => ['GET', 'POST'],
        'callback' => 'lrp_get_product_sku_api',
        'permission_callback' => 'lrp_rest_permission_check',
        'args'     => [
            'sku' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ]
        ]
    ]);
});

function lrp_get_product_sku_api($request) {
    if (!class_exists('WooCommerce')) {
        return new WP_REST_Response(['error' => 'WooCommerce not active'], 500);
    }

    $sku = trim($request->get_param('sku'));

    if (empty($sku)) {
        return new WP_REST_Response(['error' => 'SKU is required'], 400);
    }

    // Try WooCommerce native lookup
    $product_id = wc_get_product_id_by_sku($sku);

    // Strong fallback for simple + variable products
    if (!$product_id) {
        global $wpdb;

        $product_id = $wpdb->get_var($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm 
                ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku'
              AND pm.meta_value = %s 
              AND p.post_type IN ('product', 'product_variation')
            LIMIT 1
        ", $sku));
    }

    if (!$product_id) {
        return new WP_REST_Response([
            'sku'        => $sku,
            'product_id' => null,
            'message'    => 'Product not found'
        ], 404);
    }

    return new WP_REST_Response([
        'sku'        => $sku,
        'product_id' => (int) $product_id
    ], 200);
}
