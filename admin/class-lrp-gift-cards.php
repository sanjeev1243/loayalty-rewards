<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gift Cards Generated Admin Page Handler
 */
class LRP_Gift_Cards_Page {

    /**
     * Render the Gift Cards logs view.
     */
    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'netscore-loyalty-rewards' ) );
        }

        global $wpdb;
        $t_events = $wpdb->prefix . 'netscore_lty_cust_lty_event_details_table';
        $users_table = $wpdb->users;

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t_events ) );
        if ( empty( $exists ) ) {
            echo '<div class="wrap">';
            echo '<h1 class="wp-heading-inline">' . esc_html__( 'Gift Card Users', 'netscore-loyalty-rewards' ) . '</h1>';
            echo '<div class="notice notice-error is-dismissible"><p>' . sprintf( esc_html__( 'Database table %s is missing.', 'netscore-loyalty-rewards' ), esc_html( $t_events ) ) . '</p></div>';
            echo '</div>';
            return;
        }

        /* ---------- Filters ---------- */
        $search_email = isset( $_GET['search_email'] ) ? sanitize_email( wp_unslash( $_GET['search_email'] ) ) : '';
        $search_giftcode = isset( $_GET['search_giftcode'] ) ? sanitize_text_field( wp_unslash( $_GET['search_giftcode'] ) ) : '';
        $search_date = isset( $_GET['search_date'] ) ? sanitize_text_field( wp_unslash( $_GET['search_date'] ) ) : '';

        $where = "WHERE ev.gift_code IS NOT NULL AND ev.gift_code != ''";

        if ( ! empty( $search_email ) ) {
            $where .= $wpdb->prepare( ' AND u.user_email LIKE %s', '%' . $search_email . '%' );
        }
        if ( ! empty( $search_giftcode ) ) {
            $where .= $wpdb->prepare( ' AND ev.gift_code LIKE %s', '%' . $search_giftcode . '%' );
        }
        if ( ! empty( $search_date ) ) {
            $where .= $wpdb->prepare( ' AND DATE(ev.created_at) = %s', $search_date );
        }

        /* -------- Pagination ---------- */
        $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $per_page = 10;
        $offset = ( $paged - 1 ) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM {$t_events} ev 
                      LEFT JOIN {$users_table} u ON ev.customer_id = u.ID
                      $where";

        $total_items = intval( $wpdb->get_var( $count_sql ) );
        $max_pages = max( 1, ceil( $total_items / $per_page ) );
        $first_entry = $total_items > 0 ? $offset + 1 : 0;
        $last_entry  = $total_items > 0 ? min( $offset + $per_page, $total_items ) : 0;

        $rows_sql = $wpdb->prepare(
            "SELECT ev.*, u.display_name, u.user_email
             FROM {$t_events} ev
             LEFT JOIN {$users_table} u ON ev.customer_id = u.ID
             $where
             ORDER BY ev.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );

        $results = $wpdb->get_results( $rows_sql );

        $export_url = add_query_arg( [ 'page' => 'lrp-gift-card-users', 'export' => 'gift_cards' ], admin_url( 'admin.php' ) );

        echo '<style>
            .lrp-gift-wrap { margin: 0 20px 0 0; }
            .lrp-gift-card {
                background: #fff;
                border: 1px solid #e3eaf5;
                border-radius: 14px;
                padding: 26px;
                box-shadow: 0 12px 32px rgba(15, 23, 42, 0.10);
                margin-top: 22px;
            }
            .lrp-gift-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 20px;
                margin-bottom: 28px;
            }
            .lrp-gift-title-group {
                display: grid;
                grid-template-columns: 60px minmax(0, 1fr);
                gap: 18px;
                align-items: center;
            }
            .lrp-gift-header-icon {
                width: 60px !important;
                height: 60px !important;
                min-width: 60px !important;
                min-height: 60px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                border-radius: 9px !important;
                background: #edf7ff !important;
                color: #133851 !important;
                font-size: 30px !important;
                position: static !important;
                box-shadow: none !important;
            }
            .lrp-gift-header-icon::before,
            .lrp-gift-header-icon::after {
                display: none !important;
                content: none !important;
            }
            .lrp-gift-header-icon .dashicons {
                width: 30px !important;
                height: 30px !important;
                font-size: 30px !important;
                color: #133851 !important;
            }
            .lrp-gift-title {
                margin: 0 0 7px !important;
                color: #07173d !important;
                font-size: 28px !important;
                font-weight: 700 !important;
                line-height: 1.1 !important;
            }
            .lrp-gift-subtitle {
                margin: 0 !important;
                color: #33476b !important;
                font-size: 15px !important;
            }
            .lrp-gift-header-actions {
                display: flex;
                gap: 12px;
                align-items: center;
                flex-wrap: wrap;
                justify-content: flex-end;
            }
            .lrp-gift-export {
                min-height: 40px;
                padding: 0 16px;
                display: inline-flex;
                align-items: center;
                gap: 10px;
                border: 1px solid #38A1E7;
                border-radius: 5px;
                background: #133851;
                color: #fff !important;
                font-size: 14px;
                font-weight: 700;
                text-decoration: none;
                box-sizing: border-box;
                transition: all 0.2s ease;
            }
            .lrp-gift-export:hover,
            .lrp-gift-export:focus {
                background: #0d2a3e !important;
                color: #fff !important;
                border-color: #38A1E7;
            }
            .lrp-gift-export .dashicons { color: #fff !important; }
            .lrp-gift-filters {
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 22px;
                justify-content: flex-start;
                flex-wrap: wrap;
            }
            .lrp-gift-search {
                width: 260px;
                max-width: 100%;
                position: relative;
                display: block;
            }
            .lrp-gift-search .dashicons {
                position: absolute;
                left: 18px;
                top: 50%;
                transform: translateY(-50%);
                color: #133851;
                pointer-events: none;
            }
            .lrp-gift-input {
                width: 100%;
                height: 50px;
                min-height: 50px;
                padding: 0 16px 0 52px !important;
                border: 1px solid #c9def4 !important;
                border-radius: 5px !important;
                background-color: #fff !important;
                color: #07173d !important;
                font-size: 15px !important;
                line-height: 50px !important;
                box-shadow: none !important;
                box-sizing: border-box;
            }
            .lrp-gift-date {
                width: 200px;
                max-width: 100%;
                position: relative;
                display: block;
            }
            .lrp-gift-date-input {
                padding: 0 16px !important;
            }
            .lrp-gift-filter-btn {
                height: 50px;
                min-height: 50px;
                padding: 0 24px;
                border: 1px solid #38A1E7;
                border-radius: 5px;
                background: #133851;
                color: #fff !important;
                font-size: 15px;
                font-weight: 700;
                cursor: pointer;
                box-shadow: 0 8px 16px rgba(56, 161, 231, 0.18);
                box-sizing: border-box;
                transition: all 0.2s ease;
            }
            .lrp-gift-filter-btn:hover,
            .lrp-gift-filter-btn:focus {
                background: #0d2a3e !important;
                color: #fff !important;
            }
            .lrp-gift-reset-btn {
                height: 50px;
                min-height: 50px;
                padding: 0 20px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 1px solid #cbdaf5;
                border-radius: 5px;
                background: #fff;
                color: #475569 !important;
                font-size: 15px;
                font-weight: 600;
                text-decoration: none;
                box-sizing: border-box;
                transition: all 0.2s ease;
            }
            .lrp-gift-reset-btn:hover {
                background: #f1f5f9;
                color: #07173d !important;
                border-color: #94a3b8;
            }
            .lrp-gift-table-wrap {
                overflow-x: auto;
                border-radius: 5px;
                border: 1px solid #e3eaf5;
            }
            .lrp-gift-table {
                width: 100%;
                min-width: 960px;
                border-collapse: collapse;
                font-size: 14px;
                text-align: left;
                color: #07173d;
                border: 0 !important;
            }
            .lrp-gift-table thead {
                background: #133851 !important;
            }
            .lrp-gift-table th {
                padding: 16px 18px !important;
                border-bottom: 1px solid #dfe7f3 !important;
                color: #fff !important;
                font-weight: 700;
                white-space: nowrap;
            }
            .lrp-gift-table td {
                padding: 16px 18px !important;
                border-bottom: 1px solid #e9eef6 !important;
                color: #07173d;
                vertical-align: middle;
            }
            .lrp-gift-table tbody tr:nth-child(even) {
                background: #f8fafc;
            }
            .lrp-gift-table tbody tr:hover {
                background: #f1f7fc;
            }
            .lrp-gift-table tbody tr:last-child td {
                border-bottom: 0 !important;
            }
            .lrp-badge {
                min-width: 48px;
                min-height: 28px;
                padding: 4px 12px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 8px;
                font-weight: 700;
                font-size: 13px;
                box-sizing: border-box;
            }
            .lrp-badge-active {
                background: #dcf8e1;
                color: #087b20;
                border: 1px solid #bce9c7;
            }
            .lrp-badge-expired {
                background: #f1f5f9;
                color: #64748b;
                border: 1px solid #cbd5e1;
            }
            .lrp-badge-redeemed {
                background: #fff0f0;
                color: #b42336;
                border: 1px solid #ffd4d4;
            }
            .lrp-badge-notfound {
                background: #f8fafc;
                color: #94a3b8;
                border: 1px solid #e2e8f0;
            }
            .lrp-gift-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 18px;
                margin-top: 28px;
                color: #07173d;
                font-size: 15px;
            }
            .lrp-gift-footer .lrp-pager,
            .lrp-gift-footer .lrp-pagination {
                display: flex;
                justify-content: flex-end;
                align-items: center;
                gap: 12px;
                margin: 0;
            }
            .lrp-gift-footer .page-numbers,
            .lrp-gift-footer a.button {
                min-width: 40px;
                min-height: 40px;
                padding: 0 14px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 5px;
                border: 1px solid #cbdaf5;
                text-decoration: none;
                color: #07173d !important;
                background: #fff;
                font-weight: 700;
                box-sizing: border-box;
                font-size: 14px;
                transition: all 0.2s ease;
            }
            .lrp-gift-footer .page-numbers.current {
                background: #133851 !important;
                color: #fff !important;
                border-color: #38A1E7 !important;
                box-shadow: 0 8px 16px rgba(56, 161, 231, 0.18);
            }
            .lrp-gift-footer .page-numbers:hover:not(.current) {
                border-color: #38A1E7;
                color: #38A1E7 !important;
            }
            @media (max-width: 782px) {
                .lrp-gift-wrap { margin-right: 10px; }
                .lrp-gift-card { padding: 18px; }
                .lrp-gift-header,
                .lrp-gift-footer {
                    flex-direction: column;
                    align-items: stretch;
                }
                .lrp-gift-filters,
                .lrp-gift-header-actions {
                    flex-direction: column;
                    align-items: stretch;
                }
                .lrp-gift-search,
                .lrp-gift-date { width: 100%; }
                .lrp-gift-footer .lrp-pager,
                .lrp-gift-footer .lrp-pagination {
                    justify-content: flex-start;
                    flex-wrap: wrap;
                }
            }
        </style>';

        echo '<div class="wrap lrp-gift-wrap">';
        LRP_Admin::render_loyalty_nav_tabs( 'lrp-gift-card-users' );
        echo '<div class="lrp-gift-card">';

        echo '<div class="lrp-gift-header">
                <div class="lrp-gift-title-group">
                    <span class="lrp-gift-header-icon"><span class="dashicons dashicons-tickets-alt"></span></span>
                    <div>
                        <h1 class="lrp-gift-title">' . esc_html__( 'Gift Card Users', 'netscore-loyalty-rewards' ) . '</h1>
                        <p class="lrp-gift-subtitle">' . esc_html__( 'View and manage generated gift cards.', 'netscore-loyalty-rewards' ) . '</p>
                    </div>
                </div>
                <div class="lrp-gift-header-actions">
                    <a href="' . esc_url( $export_url ) . '" class="lrp-gift-export">
                        <span class="dashicons dashicons-download"></span> ' . esc_html__( 'Export CSV', 'netscore-loyalty-rewards' ) . '
                    </a>
                </div>
              </div>';

        echo '<form method="get" action="" class="lrp-gift-filters">
                <input type="hidden" name="page" value="lrp-gift-card-users" />
                <label class="lrp-gift-search">
                    <span class="dashicons dashicons-email"></span>
                    <input type="text" name="search_email" placeholder="' . esc_attr__( 'Search User Email...', 'netscore-loyalty-rewards' ) . '" class="lrp-gift-input" value="' . esc_attr( $search_email ) . '">
                </label>
                <label class="lrp-gift-search">
                    <span class="dashicons dashicons-tag"></span>
                    <input type="text" name="search_giftcode" placeholder="' . esc_attr__( 'Search Gift Code...', 'netscore-loyalty-rewards' ) . '" class="lrp-gift-input" value="' . esc_attr( $search_giftcode ) . '">
                </label>
                <label class="lrp-gift-date">
                    <input type="date" name="search_date" class="lrp-gift-input lrp-gift-date-input" value="' . esc_attr( $search_date ) . '">
                </label>
                <button type="submit" class="lrp-gift-filter-btn">' . esc_html__( 'Apply Filters', 'netscore-loyalty-rewards' ) . '</button>';

        if ( ! empty( $search_email ) || ! empty( $search_giftcode ) || ! empty( $search_date ) ) {
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=lrp-gift-card-users' ) ) . '" class="lrp-gift-reset-btn">' . esc_html__( 'Reset', 'netscore-loyalty-rewards' ) . '</a>';
        }

        echo '</form>';

        if ( ! empty( $results ) ) {
            echo '<div class="lrp-gift-table-wrap">
                    <table class="lrp-gift-table">
                        <thead>
                            <tr>
                                <th style="width:60px;">' . esc_html__( 'S.No', 'netscore-loyalty-rewards' ) . '</th>
                                <th>' . esc_html__( 'Customer ID', 'netscore-loyalty-rewards' ) . '</th>
                                <th>' . esc_html__( 'Customer Email', 'netscore-loyalty-rewards' ) . '</th>
                                <th>' . esc_html__( 'Receiver Email', 'netscore-loyalty-rewards' ) . '</th>
                                <th>' . esc_html__( 'Gift Code', 'netscore-loyalty-rewards' ) . '</th>
                                <th>' . esc_html__( 'Gift Card Status', 'netscore-loyalty-rewards' ) . '</th>
                                <th>' . esc_html__( 'Created Date', 'netscore-loyalty-rewards' ) . '</th>
                                <th>' . esc_html__( 'Updated Date', 'netscore-loyalty-rewards' ) . '</th>
                                <th>' . esc_html__( 'Expiry Date', 'netscore-loyalty-rewards' ) . '</th>
                            </tr>
                        </thead>
                        <tbody>';

            $sno = $offset + 1;
            foreach ( $results as $row ) {
                if ( empty( $row->gift_code ) ) {
                    continue;
                }

                $customer_id = $row->customer_id ?: 'N/A';
                $user_email  = $row->user_email ?: 'N/A';
                $receiver_email = $row->receiver_email ?: ( $row->sent_email ?: 'N/A' );
                $gift_code   = $row->gift_code;

                $created_src = $row->created_at ?: $row->date_created;
                $created_at = $created_src ? date_i18n( get_option( 'date_format' ) . ' H:i:s', strtotime( $created_src ) ) : 'N/A';
                $updated_at = ! empty( $row->updated_at ) ? date_i18n( get_option( 'date_format' ) . ' H:i:s', strtotime( $row->updated_at ) ) : 'N/A';

                $expiry_date = 'N/A';
                $gift_status = '<span class="lrp-badge lrp-badge-notfound">' . esc_html__( 'Coupon Not Found', 'netscore-loyalty-rewards' ) . '</span>';

                if ( ! empty( $gift_code ) ) {
                    $coupon_post = get_page_by_title( $gift_code, OBJECT, 'shop_coupon' );
                    if ( $coupon_post && $coupon_post->ID ) {
                        $coupon = new WC_Coupon( $coupon_post->ID );
                        $usage_count = (int) $coupon->get_usage_count();
                        $usage_limit = (int) $coupon->get_usage_limit();

                        $date_expires = $coupon->get_date_expires();
                        $expiry_ts = $date_expires ? $date_expires->getTimestamp() : 0;
                        if ( $date_expires ) {
                            $expiry_date = date_i18n( get_option( 'date_format' ) . ' H:i:s', $expiry_ts );
                        }

                        $is_expired = ( $expiry_ts > 0 && $expiry_ts < current_time( 'timestamp' ) );

                        if ( $is_expired ) {
                            $gift_status = '<span class="lrp-badge lrp-badge-expired">' . esc_html__( 'Expired', 'netscore-loyalty-rewards' ) . '</span>';
                        } elseif ( $usage_limit > 0 && $usage_count >= $usage_limit ) {
                            $gift_status = '<span class="lrp-badge lrp-badge-redeemed">' . esc_html__( 'Redeemed', 'netscore-loyalty-rewards' ) . '</span>';
                        } else {
                            $gift_status = '<span class="lrp-badge lrp-badge-active">' . esc_html__( 'Active', 'netscore-loyalty-rewards' ) . '</span>';
                        }
                    }
                }

                echo '<tr>
                        <td>' . esc_html( $sno ) . '</td>
                        <td>' . esc_html( $customer_id ) . '</td>
                        <td>' . esc_html( $user_email ) . '</td>
                        <td>' . esc_html( $receiver_email ) . '</td>
                        <td><strong>' . esc_html( $gift_code ) . '</strong></td>
                        <td>' . wp_kses_post( $gift_status ) . '</td>
                        <td>' . esc_html( $created_at ) . '</td>
                        <td>' . esc_html( $updated_at ) . '</td>
                        <td>' . esc_html( $expiry_date ) . '</td>
                      </tr>';
                $sno++;
            }

            echo '</tbody></table></div>';

            $pagination = paginate_links( [
                'base'      => add_query_arg( 'paged', '%#%' ),
                'format'    => '',
                'prev_text' => '&lsaquo;',
                'next_text' => 'Next <span class="dashicons dashicons-arrow-right-alt2"></span>',
                'total'     => $max_pages,
                'current'   => $paged,
                'type'      => 'plain',
            ] );
            echo '<div class="lrp-gift-footer">';
            echo '<div>' . sprintf( esc_html__( 'Showing %1$s to %2$s of %3$s entries', 'netscore-loyalty-rewards' ), esc_html( $first_entry ), esc_html( $last_entry ), esc_html( $total_items ) ) . '</div>';
            echo '<div class="lrp-pager">' . wp_kses_post( $pagination ) . '</div>';
            echo '</div>';
        } else {
            echo '<p style="padding: 32px; text-align: center; color: #64748b; font-size: 15px;">' . esc_html__( 'No gift card entries found.', 'netscore-loyalty-rewards' ) . '</p>';
        }

        echo '</div></div>';
    }

    /**
     * Handle CSV Export of Gift Cards.
     */
    public static function handle_export() {
        if ( ! isset( $_GET['export'] ) || $_GET['export'] !== 'gift_cards' ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'netscore-loyalty-rewards' ) );
        }

        global $wpdb;
        $t_events = $wpdb->prefix . 'netscore_lty_cust_lty_event_details_table';
        $users_table = $wpdb->users;

        $results = $wpdb->get_results( "
            SELECT ev.*, u.display_name, u.user_email
            FROM {$t_events} ev
            LEFT JOIN {$users_table} u ON ev.customer_id = u.ID
            WHERE ev.gift_code IS NOT NULL
            ORDER BY ev.created_at DESC
        " );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=gift_card_events_' . date( 'Y-m-d_H-i-s' ) . '.csv' );
        $output = fopen( 'php://output', 'w' );

        fputcsv( $output, [
            'S.No',
            'Customer ID',
            'Username',
            'User Email',
            'Receiver Email',
            'Gift Code',
            'Created At',
            'Updated At',
            'Expiry Date'
        ], ',', '"', '\\' );

        $sno = 1;
        foreach ( $results as $row ) {
            if ( empty( $row->gift_code ) ) {
                continue;
            }
            $customer_id = isset( $row->customer_id ) ? intval( $row->customer_id ) : '';
            $username = ! empty( $row->display_name ) ? $row->display_name : '';
            $user_email = ! empty( $row->user_email ) ? $row->user_email : '';
            $receiver_email = ! empty( $row->receiver_email ) ? $row->receiver_email : '';
            $gift_code = ! empty( $row->gift_code ) ? $row->gift_code : '';
            $created_src = $row->created_at ?: $row->date_created;
            $created_at = $created_src ? date_i18n( get_option( 'date_format' ) . ' H:i:s', strtotime( $created_src ) ) : 'N/A';
            $updated_at = ! empty( $row->updated_at ) ? date_i18n( get_option( 'date_format' ) . ' H:i:s', strtotime( $row->updated_at ) ) : 'N/A';

            $expiry_date = 'N/A';
            if ( ! empty( $gift_code ) ) {
                $coupon_post = get_page_by_title( $gift_code, OBJECT, 'shop_coupon' );
                if ( $coupon_post && $coupon_post->ID ) {
                    $coupon = new WC_Coupon( $coupon_post->ID );
                    $date_expires = $coupon->get_date_expires();
                    if ( $date_expires ) {
                        $expiry_date = date_i18n( get_option( 'date_format' ) . ' H:i:s', $date_expires->getTimestamp() );
                    }
                }
            }

            fputcsv( $output, [
                $sno,
                $customer_id,
                $username,
                $user_email,
                $receiver_email,
                $gift_code,
                $created_at,
                $updated_at,
                $expiry_date
            ], ',', '"', '\\' );
            $sno++;
        }

        fclose( $output );
        exit;
    }
}