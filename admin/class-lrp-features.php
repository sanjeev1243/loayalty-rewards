<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Features Page Handler for NetScore Loyalty Rewards
 */
class LRP_Features_Page {

    /**
     * Render the Features Configuration view.
     */
    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'netscore-loyalty-rewards' ) );
        }

        $config = self::get_features_config();
        $is_netsuite = function_exists( 'lrp_is_netsuite_customer' ) && lrp_is_netsuite_customer();
        $disabled = $is_netsuite ? 'disabled' : '';

        $feature_columns = [
            [
                [ 'field' => 'loyalty_eligible', 'label' => 'Loyalty Eligible', 'icon' => 'dashicons-star-empty' ],
                [ 'field' => 'login_to_see_points', 'label' => 'Login to See Points', 'icon' => 'dashicons-visibility' ],
                [ 'field' => 'enable_gift_certificate_generation', 'label' => 'Gift Certificate Generation', 'icon' => 'dashicons-tickets-alt' ],
                [ 'field' => 'enable_points_redeem_on_checkout', 'label' => 'Redeem on Checkout', 'icon' => 'dashicons-cart' ],
                [ 'field' => 'enable_referral_code_use_at_signup', 'label' => 'Referral Code at Signup', 'icon' => 'dashicons-star-filled' ],
            ],
            [
                [ 'field' => 'product_sharing_through_email', 'label' => 'Product Sharing through Email', 'icon' => 'dashicons-email' ],
                [ 'field' => 'enable_redeem_history', 'label' => 'Redeem History', 'icon' => 'dashicons-backup' ],
                [ 'field' => 'enable_tiers_info', 'label' => 'Tiers Information', 'icon' => 'dashicons-awards' ],
                [ 'field' => 'enable_profile_info', 'label' => 'Profile Information', 'icon' => 'dashicons-admin-users' ],
                [ 'field' => 'enable_refer_friend', 'label' => 'Refer Friend', 'icon' => 'dashicons-groups' ],
            ],
        ];

        $labels = [
            [ 'field' => 'my_account_tab_heading', 'label' => 'My Account Tab Heading', 'placeholder' => 'Loyalty Rewards' ],
            [ 'field' => 'loyalty_points_earned_label', 'label' => 'Loyalty Points Earned', 'placeholder' => 'Loyalty Points Earned' ],
            [ 'field' => 'redeem_history_label', 'label' => 'Redeem History', 'placeholder' => 'Redeem Points History' ],
            [ 'field' => 'gift_card_label', 'label' => 'Gift Card', 'placeholder' => 'Generate Gift Card' ],
            [ 'field' => 'refer_friend_label', 'label' => 'Refer Friend', 'placeholder' => 'Refer Your Friend' ],
            [ 'field' => 'update_profile_label', 'label' => 'Update Profile', 'placeholder' => 'Update Profile' ],
            [ 'field' => 'tiers_label', 'label' => 'Loyalty Tiers', 'placeholder' => 'Loyalty Tiers' ],
            [ 'field' => 'product_redeem_label', 'label' => 'Product Redeem Label', 'placeholder' => 'Spend Your Loyalty Rewards Points' ],
        ];
        ?>
        <div class="wrap lrp-features-page">
            <?php LRP_Admin::render_loyalty_nav_tabs( 'lrp-features' ); ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=lrp-features' ) ); ?>">
                <?php wp_nonce_field( 'lrp_features_nonce', 'lrp_features_nonce' ); ?>
                <div class="lrp-features-panel">
                    <h1>Features configuration</h1>
                    <?php if ( $is_netsuite ) : ?>
                        <p class="lrp-features-lock-note">NetSuite accounts manage features through NetSuite. These controls are read-only.</p>
                    <?php endif; ?>

                    <div class="lrp-features-grid">
                        <?php foreach ( $feature_columns as $column ) : ?>
                            <div class="lrp-features-card">
                                <?php foreach ( $column as $item ) : ?>
                                    <label class="lrp-feature-row">
                                        <span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span>
                                        <span class="lrp-feature-label"><?php echo esc_html( $item['label'] ); ?></span>
                                        <input type="checkbox" name="<?php echo esc_attr( $item['field'] ); ?>" value="1" <?php checked( 1, (int) $config[ $item['field'] ] ); ?> <?php echo esc_attr( $disabled ); ?>>
                                        <span class="lrp-feature-switch" aria-hidden="true"></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="lrp-label-card">
                        <h2>Label configuration</h2>
                        <p>Modify labels used within the rewards app.</p>
                        <div class="lrp-label-grid">
                            <?php foreach ( $labels as $label ) : ?>
                                <label class="lrp-label-field">
                                    <span><?php echo esc_html( $label['label'] ); ?></span>
                                    <input type="text" name="<?php echo esc_attr( $label['field'] ); ?>" value="<?php echo esc_attr( $config[ $label['field'] ] ); ?>" placeholder="<?php echo esc_attr( $label['placeholder'] ); ?>" <?php echo esc_attr( $disabled ); ?>>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ( ! $is_netsuite ) : ?>
                        <div class="lrp-features-actions">
                            <button type="submit" class="button button-primary">Save Features</button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Get features config row from DB.
     */
    public static function get_features_config() {
        global $wpdb;
        $table = $wpdb->prefix . 'netscore_lty_features_table';
        $row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id ASC LIMIT 1", ARRAY_A );

        $defaults = [
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

        return array_merge( $defaults, is_array( $row ) ? $row : [] );
    }

    /**
     * Handle saving features config form.
     */
    public static function handle_save() {
        if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'lrp-features' ) {
            return;
        }
        if ( ! isset( $_POST['lrp_features_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lrp_features_nonce'] ) ), 'lrp_features_nonce' ) ) {
            return;
        }
        if ( ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'netscore_lty_features_table';
        $row_id = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1" );

        $toggle_fields = [
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
        $text_fields = [
            'my_account_tab_heading',
            'loyalty_points_earned_label',
            'redeem_history_label',
            'refer_friend_label',
            'gift_card_label',
            'tiers_label',
            'update_profile_label',
            'product_redeem_label',
        ];

        $data = [];
        $formats = [];
        foreach ( $toggle_fields as $field ) {
            $data[ $field ] = isset( $_POST[ $field ] ) ? 1 : 0;
            $formats[] = '%d';
        }
        foreach ( $text_fields as $field ) {
            $data[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
            $formats[] = '%s';
        }
        $data['updated_at'] = current_time( 'mysql' );
        $formats[] = '%s';

        if ( $row_id > 0 ) {
            $saved = $wpdb->update( $table, $data, [ 'id' => $row_id ], $formats, [ '%d' ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $formats[] = '%s';
            $saved = $wpdb->insert( $table, $data, $formats );
        }

        if ( false === $saved ) {
            set_transient( 'lrp_admin_error', 'Failed to save Features configuration.', 30 );
        } else {
            set_transient( 'lrp_admin_notice', 'Features configuration saved successfully.', 30 );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=lrp-features' ) );
        exit;
    }
}
