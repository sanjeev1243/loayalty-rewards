<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Table identifiers are built from the trusted WordPress prefix and plugin-owned suffixes.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class LRP_Features_API {
    protected static $instance;
    protected $namespace = 'lrp/v1';
    protected $base      = 'features';

    protected $toggle_fields = [
        'loyalty_eligible',
        'product_sharing_through_email',
        'enable_referral_code_use_at_signup',
        'login_to_see_points',
        'enable_redeem_history',
        'enable_refer_friend',
        'enable_gift_certificate_generation',
        'enable_tiers_info',
        'enable_profile_info',
        'enable_points_redeem_on_checkout',
    ];

    protected $text_fields = [
        'my_account_tab_heading',
        'loyalty_points_earned_label',
        'redeem_history_label',
        'refer_friend_label',
        'gift_card_label',
        'tiers_label',
        'update_profile_label',
        'product_redeem_label',
    ];

    public static function init() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            add_action( 'rest_api_init', [ self::$instance, 'register_routes' ] );
        }

        return self::$instance;
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->base, [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_features' ],
                'permission_callback' => [ $this, 'permission_check_read' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'update_features' ],
                'permission_callback' => [ $this, 'permission_check_write' ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_features' ],
                'permission_callback' => [ $this, 'permission_check_write' ],
            ],
        ] );
    }

    public function permission_check_read( $request ) {
        return current_user_can( 'manage_options' );
    }

    public function permission_check_write( $request ) {
        return current_user_can( 'manage_options' );
    }

    public function get_features( WP_REST_Request $request ) {
        $row = $this->get_or_create_row();

        return new WP_REST_Response(
            [
                'features' => $row,
            ],
            200
        );
    }

    public function update_features( WP_REST_Request $request ) {
        global $wpdb;

        $table = $this->get_table_name();
        $row   = $this->get_or_create_row();
        $data  = [];
        $formats = [];
        $payload = $request->get_json_params();
        if ( isset( $payload['features'] ) && is_array( $payload['features'] ) ) {
            $payload = $payload['features'];
        }

        foreach ( $this->toggle_fields as $field ) {
            if ( array_key_exists( $field, $payload ) || $request->has_param( $field ) ) {
                $value = array_key_exists( $field, $payload ) ? $payload[ $field ] : $request->get_param( $field );
                $data[ $field ] = $this->sanitize_bool( $value );
                $formats[]      = '%d';
            }
        }

        foreach ( $this->text_fields as $field ) {
            if ( array_key_exists( $field, $payload ) || $request->has_param( $field ) ) {
                $value = array_key_exists( $field, $payload ) ? $payload[ $field ] : $request->get_param( $field );
                $data[ $field ] = sanitize_text_field( $value );
                $formats[]      = '%s';
            }
        }

        if ( empty( $data ) ) {
            return new WP_REST_Response(
                [
                    'message'  => 'No feature changes provided.',
                    'features' => $row,
                ],
                200
            );
        }

        $data['updated_at'] = current_time( 'mysql' );
        $formats[]          = '%s';

        $updated = $wpdb->update(
            $table,
            $data,
            [ 'id' => (int) $row['id'] ],
            $formats,
            [ '%d' ]
        );

        if ( false === $updated ) {
            return new WP_REST_Response(
                [
                    'message' => 'Failed to update features.',
                    'error'   => $wpdb->last_error,
                ],
                500
            );
        }

        return new WP_REST_Response(
            [
                'message'  => 'Features updated successfully.',
                'features' => $this->get_or_create_row(),
            ],
            200
        );
    }

    protected function sanitize_bool( $value ) {
        if ( is_bool( $value ) ) {
            return $value ? 1 : 0;
        }

        if ( is_numeric( $value ) ) {
            return (int) $value ? 1 : 0;
        }

        return in_array( strtolower( (string) $value ), [ 'true', 'yes', 'on' ], true ) ? 1 : 0;
    }

    protected function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'netscore_lty_features_table';
    }

    protected function get_defaults() {
        return [
            'loyalty_eligible'                   => 1,
            'product_sharing_through_email'      => 1,
            'enable_referral_code_use_at_signup' => 0,
            'login_to_see_points'                => 1,
            'enable_redeem_history'              => 1,
            'enable_refer_friend'                => 1,
            'enable_gift_certificate_generation' => 0,
            'enable_tiers_info'                  => 1,
            'enable_profile_info'                => 1,
            'enable_points_redeem_on_checkout'   => 1,
            'my_account_tab_heading'             => 'Loyalty Rewards',
            'loyalty_points_earned_label'        => 'Loyalty Points Earned',
            'redeem_history_label'               => 'Redeem Points History',
            'refer_friend_label'                 => 'Refer Your Friend',
            'gift_card_label'                    => 'Generate Gift Card',
            'tiers_label'                        => 'Loyalty Tiers',
            'update_profile_label'               => 'Update Profile',
            'product_redeem_label'               => 'Spend Your Loyalty Rewards Points',
        ];
    }

    protected function ensure_table() {
        global $wpdb;

        $table = $this->get_table_name();
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id INT(11) NOT NULL AUTO_INCREMENT,
            loyalty_eligible TINYINT(1) NOT NULL DEFAULT 1,
            product_sharing_through_email TINYINT(1) NOT NULL DEFAULT 1,
            enable_referral_code_use_at_signup TINYINT(1) NOT NULL DEFAULT 0,
            login_to_see_points TINYINT(1) NOT NULL DEFAULT 1,
            enable_redeem_history TINYINT(1) NOT NULL DEFAULT 1,
            enable_refer_friend TINYINT(1) NOT NULL DEFAULT 1,
            enable_gift_certificate_generation TINYINT(1) NOT NULL DEFAULT 0,
            enable_tiers_info TINYINT(1) NOT NULL DEFAULT 1,
            enable_profile_info TINYINT(1) NOT NULL DEFAULT 1,
            enable_points_redeem_on_checkout TINYINT(1) NOT NULL DEFAULT 1,
            my_account_tab_heading TEXT DEFAULT NULL,
            loyalty_points_earned_label TEXT DEFAULT NULL,
            redeem_history_label TEXT DEFAULT NULL,
            refer_friend_label TEXT DEFAULT NULL,
            gift_card_label TEXT DEFAULT NULL,
            tiers_label TEXT DEFAULT NULL,
            update_profile_label TEXT DEFAULT NULL,
            product_redeem_label TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta( $sql );
    }

    protected function get_or_create_row() {
        global $wpdb;

        $this->ensure_table();
        $table = $this->get_table_name();
        $row   = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id ASC LIMIT 1", ARRAY_A );

        if ( $row ) {
            return $row;
        }

        $defaults = $this->get_defaults();
        $defaults['created_at'] = current_time( 'mysql' );
        $defaults['updated_at'] = current_time( 'mysql' );

        $wpdb->insert(
            $table,
            $defaults,
            [ '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id ASC LIMIT 1", ARRAY_A );
    }
}

LRP_Features_API::init();
