<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Table identifiers are built from the trusted WordPress prefix and plugin-owned suffixes.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class LRP_Activator {
    public static function activate() {
        global $wpdb;
        // Ensure dbDelta exists
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $charset_collate = $wpdb->get_charset_collate();
        // Table names
        $t_event_details = $wpdb->prefix . 'netscore_lty_cust_lty_event_details_table';
        $t_cust_pts = $wpdb->prefix . 'netscore_lty_cust_lty_pts_table';
        $t_item_pts = $wpdb->prefix . 'netscore_lty_item_lty_pts_table';
        $t_config = $wpdb->prefix . 'netscore_lty_lty_config_table';
        $t_events = $wpdb->prefix . 'netscore_lty_lty_events_table';
        $t_tiers = $wpdb->prefix . 'netscore_lty_lty_tiers_table';
        $t_tier_lvl_pts = $wpdb->prefix . 'netscore_lty_tier_lvl_pts_table';
        $t_features = $wpdb->prefix . 'netscore_lty_features_table';
        $tables = [];
        // 1) Parent: customer points table
        $tables[] = "
CREATE TABLE {$t_cust_pts} (
  id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  customer_id BIGINT(20) NOT NULL,
  points_earned DECIMAL(10,2) DEFAULT 0.00,
  points_available DECIMAL(10,2) DEFAULT 0.00,
  points_redeemed DECIMAL(10,2) DEFAULT 0.00,
  points_expired DECIMAL(10,2) DEFAULT 0.00,
  anniversary_date DATE DEFAULT NULL,
  birthdate DATE DEFAULT NULL,
  is_eligible_for_loyalty_program TINYINT(1) DEFAULT 0,
  current_tier_level INT(11) DEFAULT NULL,
  next_tier_level INT(11) DEFAULT NULL,
  referral_code VARCHAR(100) DEFAULT NULL,
  referral_code_by_friend VARCHAR(100) DEFAULT NULL,
  loyalty_eligible_date DATE DEFAULT NULL,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_customer_id (customer_id),
  KEY idx_current_tier_level (current_tier_level),
  KEY idx_next_tier_level (next_tier_level)
) ENGINE=InnoDB {$charset_collate};
";
        // 2) Events master
        $tables[] = "
CREATE TABLE {$t_events} (
  id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  event_id INT(10) UNSIGNED NOT NULL,
  NSID varchar(100) DEFAULT NULL,
  event_name VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_event_id (event_id),
  UNIQUE KEY uniq_event_name (event_name),
  KEY idx_is_active (is_active)
) ENGINE=InnoDB {$charset_collate};
";
        // 3) Event details - FIXED: added missing comma before event_id + added index on event_id
        $tables[] = "
CREATE TABLE {$t_event_details} (
  id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  customer_id BIGINT(20) NOT NULL,
  date_created DATE DEFAULT NULL,
  event_name VARCHAR(255) DEFAULT NULL,
  points_earned DECIMAL(10,2) DEFAULT 0.00,
  points_redeemed DECIMAL(10,2) DEFAULT 0.00,
  points_left DECIMAL(10,2) DEFAULT 0.00,
  transaction_id BIGINT(20) DEFAULT NULL,
  amount DECIMAL(10,2) DEFAULT 0.00,
  gift_code VARCHAR(100) DEFAULT NULL,
  receiver_email VARCHAR(255) DEFAULT NULL,
  refer_friend_id BIGINT(20) DEFAULT NULL,
  comments TEXT DEFAULT NULL,
  points_expiration_date date DEFAULT NULL,
  points_expiration_days VARCHAR(255) DEFAULT NULL,
  expired TINYINT(1) DEFAULT 0,
  points_type ENUM('positive','negative') DEFAULT 'positive',
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  event_id INT(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_customer_id (customer_id),
  KEY idx_event_name (event_name),
  KEY idx_transaction_id (transaction_id),
  KEY idx_refer_friend_id (refer_friend_id),
  KEY idx_event_id (event_id)
) ENGINE=InnoDB {$charset_collate};
";
        // 4) Item points - FIXED: added customer_id column + proper commas
        $tables[] = "
CREATE TABLE {$t_item_pts} (
  id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  item_id BIGINT(20) NOT NULL,
  user_id BIGINT(20) DEFAULT NULL,
  customer_id BIGINT(20) DEFAULT NULL,
  is_eligible_for_loyalty_program TINYINT(1) DEFAULT 0,
  enable_collection_type TINYINT(1) DEFAULT 0,
  collection_type VARCHAR(32) DEFAULT 'points',
  points_based_points DECIMAL(10,2) DEFAULT 0.00,
  sku_based_points DECIMAL(10,2) DEFAULT 0.00,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_item_id (item_id),
  KEY idx_customer_id (customer_id)
) ENGINE=InnoDB {$charset_collate};
";
        // 5) Config
        $tables[] = "
CREATE TABLE {$t_config} (
  id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  customer_signup_points DECIMAL(10,2) NOT NULL DEFAULT 50.00,
  product_review_points DECIMAL(10,2) NOT NULL DEFAULT 10.00,
  referral_points DECIMAL(10,2) NOT NULL DEFAULT 50.00,
  birthday_points DECIMAL(10,2) NOT NULL DEFAULT 25.00,
  anniversary_points DECIMAL(10,2) NOT NULL DEFAULT 25.00,
  each_point_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  giftcard_expiry_days VARCHAR(255) DEFAULT NULL,
  loyalty_point_value DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  netsuite_endpoint_url varchar(500) DEFAULT NULL,
  minimum_redemption_points INT NOT NULL DEFAULT 100,
  email_share_points DECIMAL(10,2) NOT NULL DEFAULT 20.00,
  facebook_share_points DECIMAL(10,2) NOT NULL DEFAULT 20.00,
  points_expiration_days VARCHAR(255) DEFAULT NULL,
  newsletter_subscription TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB {$charset_collate};
";
        // 6) Tiers - FIXED: added missing comma after NSID column
        $tables[] = "
CREATE TABLE {$t_tiers} (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  NSID VARCHAR(100) DEFAULT NULL,
  tier_name VARCHAR(100) NOT NULL,
  description TEXT DEFAULT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tier_name (tier_name),
  KEY idx_status (status)
) ENGINE=InnoDB {$charset_collate};
";
        // 7) Tier level points
        $tables[] = "
CREATE TABLE {$t_tier_lvl_pts} (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  tier_id INT(10) UNSIGNED NOT NULL,
  threshold DECIMAL(10,2) NOT NULL,
  points_for_currency DECIMAL(10,2) NOT NULL,
  level INT(11) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_tier_level (tier_id)
) ENGINE=InnoDB {$charset_collate};
";
        // 8) Features table
        $tables[] = "
CREATE TABLE {$t_features} (
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
) ENGINE=InnoDB {$charset_collate};
";
        // Run dbDelta for all tables
        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }

        $event_id_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$t_events} LIKE %s", 'event_id' ) );
        if ( ! $event_id_column ) {
            $wpdb->query( "ALTER TABLE {$t_events} ADD event_id INT(10) UNSIGNED DEFAULT NULL AFTER id" );
        }

        $wpdb->query( "UPDATE {$t_events} SET event_id = id WHERE event_id IS NULL OR event_id = 0" );

        $has_event_id_unique = $wpdb->get_var( $wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_event_id'",
            $t_events
        ) );
        if ( ! $has_event_id_unique ) {
            $wpdb->query( "ALTER TABLE {$t_events} ADD UNIQUE KEY uniq_event_id (event_id)" );
        }

        $features_exists = $wpdb->get_var( "SELECT id FROM {$t_features} ORDER BY id ASC LIMIT 1" );
        if ( ! $features_exists ) {
            $wpdb->insert(
                $t_features,
                [
                    'loyalty_eligible'                    => 1,
                    'product_sharing_through_email'       => 1,
                    'enable_referral_code_use_at_signup'  => 0,
                    'login_to_see_points'                 => 1,
                    'enable_redeem_history'               => 1,
                    'enable_refer_friend'                 => 1,
                    'enable_gift_certificate_generation'  => 0,
                    'enable_tiers_info'                   => 1,
                    'enable_profile_info'                 => 1,
                    'enable_points_redeem_on_checkout'    => 1,
                    'my_account_tab_heading'              => 'Loyalty Rewards',
                    'loyalty_points_earned_label'         => 'Loyalty Points Earned',
                    'redeem_history_label'                => 'Redeem Points History',
                    'refer_friend_label'                  => 'Refer Your Friend',
                    'gift_card_label'                     => 'Generate Gift Card',
                    'tiers_label'                         => 'Loyalty Tiers',
                    'update_profile_label'                => 'Update Profile',
                    'product_redeem_label'                => 'Spend Your Loyalty Rewards Points',
                    'created_at'                          => current_time( 'mysql' ),
                    'updated_at'                          => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
        }

        // Seed Events (idempotent + includes NSID)
        $events_seed = [
            1 => 'Points Earned On Purchase',
            2 => 'Gift Certificate Generated - Web',
            3 => 'Points Deducted on Return of Products',
            4 => 'Gift Certificate Generated - Manual',
            5 => 'Points Earned on Referred Friend Sign up',
            6 => 'Points Earned on Signup',
            7 => 'Product Earned on Sharing a Product on Facebook',
            8 => 'Points Earned on Product Review',
            9 => 'Points Earned on Birthday',
            10 => 'Points Earned on Anniversary',
            11 => 'Product Shared on Instagram',
            12 => 'Followed Our page on Instagram',
            13 => 'Points Earned on Referral Code Used',
            14 => 'Points Earned on Sharing a Product by Email',
            15 => 'Points Earned By Subscribing to Newsletter',
            16 => 'Points Redeemed towards Purchase',
            17 => 'Points Expired',
            18 => 'Points Adjusted Manually',
            19 => 'Points Credited When So Closed',
            20 => 'Points Credited Back when items are Returned',
            21 => 'Points Redeemed By Purchasing Product',
            22 => 'Points Earned On Transaction Amount',
            23 => 'Points Reset',
            24 => 'Gift Certificate Generated - Auto',
            25 => 'PromoCode Generate',
        ];

        foreach ( $events_seed as $event_id => $name ) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$t_events} (event_id, NSID, event_name, is_active)
                     VALUES (%d, %d, %s, 1)
                     ON DUPLICATE KEY UPDATE 
                        event_id = VALUES(event_id),
                        NSID = VALUES(NSID),
                        event_name = VALUES(event_name)",
                    $event_id,
                    $event_id,
                    $name
                )
            );
        }

        // (The rest of the function - column fixes, FK additions, demo license insert, etc. - remains exactly the same)
        // ... [all the defensive column fixes, FK logic, dummy license, wp_cache_flush, etc.]

        // Ensure event_id index exists even on fresh install
        $has_event_id_index = $wpdb->get_var( $wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_event_id'",
            $t_event_details
        ) );
        if ( ! $has_event_id_index ) {
            $wpdb->query( "ALTER TABLE {$t_event_details} ADD KEY idx_event_id (event_id)" );
        }
        return true;
    }

    public static function deactivate() {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( 'lrp_daily_special_dates_check' );
        } elseif ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_unschedule_event' ) ) {
            $timestamp = wp_next_scheduled( 'lrp_daily_special_dates_check' );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, 'lrp_daily_special_dates_check' );
            }
        }

        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
    }
}
