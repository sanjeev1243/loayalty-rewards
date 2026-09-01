<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Loyalty Customers Admin Page Handler
 */
class LRP_Customers_Page {

    /**
     * Render the Loyalty Customers table view.
     */
    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'netscore-loyalty-rewards' ) );
        }

        // Include customer events class if present
        $events_class_file = plugin_dir_path( __FILE__ ) . 'class-lrp-customer-events.php';
        if ( file_exists( $events_class_file ) ) {
            require_once $events_class_file;
        }

        global $wpdb;

        // Unified search box
        $search_term = isset( $_GET['lrp_search'] ) 
            ? trim( sanitize_text_field( wp_unslash( $_GET['lrp_search'] ) ) ) 
            : '';

        // Pagination
        $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $per_page = 10;
        $offset = ( $paged - 1 ) * $per_page;

        // Tables
        $users_table = $wpdb->users;
        $usermeta    = $wpdb->usermeta;
        $pts_table   = $wpdb->prefix . 'netscore_lty_cust_lty_pts_table';

        // WHERE clause
        $where_clauses = [];
        $params = [];

        if ( $search_term !== '' ) {
            $where_clauses[] = "AND (u.display_name LIKE %s OR u.user_email LIKE %s)";
            $like = '%' . $wpdb->esc_like( $search_term ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // Show users who are eligible in the pts table
        $where_clauses[] = "AND COALESCE(pts.is_eligible_for_loyalty_program, 0) = %d";
        $params[] = 1;

        $where_sql = '';
        if ( ! empty( $where_clauses ) ) {
            $where_sql = ' ' . implode( ' ', $where_clauses );
        }

        // Count total
        $count_sql = "
            SELECT COUNT(DISTINCT u.ID)
            FROM {$users_table} u
            INNER JOIN {$usermeta} um_role
                ON (um_role.user_id = u.ID 
                    AND um_role.meta_key LIKE %s 
                    AND um_role.meta_value LIKE %s)
            LEFT JOIN {$pts_table} pts ON pts.customer_id = u.ID
            WHERE 1=1
        ";
        $count_params = [ '%capabilities%', '%\"customer\"%' ];
        $count_params = array_merge( $count_params, $params );

        $total_users = (int) $wpdb->get_var( $wpdb->prepare( $count_sql . $where_sql, $count_params ) );
        $max_pages = ( $total_users > 0 ) ? ceil( $total_users / $per_page ) : 1;

        // Select rows
        $select_sql = "
            SELECT DISTINCT
                u.ID,
                u.display_name,
                u.user_email,
                COALESCE(pts.points_earned,0) AS total_earned,
                COALESCE(pts.points_redeemed,0) AS total_redeemed_points,
                COALESCE(pts.points_available,0) AS available_points,
                COALESCE(pts.is_eligible_for_loyalty_program, 0) AS is_eligible_for_loyalty_program,
                pts.loyalty_eligible_date AS loyalty_eligible_date,
                pts.birthdate AS dob,
                pts.anniversary_date AS anniversary
            FROM {$users_table} u
            INNER JOIN {$usermeta} um_role
                ON (um_role.user_id = u.ID 
                    AND um_role.meta_key LIKE %s 
                    AND um_role.meta_value LIKE %s)
            LEFT JOIN {$pts_table} pts ON pts.customer_id = u.ID
            WHERE 1=1
        ";

        $select_params = [ '%capabilities%', '%\"customer\"%' ];
        $select_params = array_merge( $select_params, $params );

        $select_sql .= $where_sql . " ORDER BY u.ID DESC LIMIT %d OFFSET %d";
        $select_params[] = $per_page;
        $select_params[] = $offset;

        $results = $wpdb->get_results( $wpdb->prepare( $select_sql, $select_params ) );

        $first_entry = $total_users > 0 ? $offset + 1 : 0;
        $last_entry  = $total_users > 0 ? min( $offset + $per_page, $total_users ) : 0;

        echo '<style>
            .lrp-customers-wrap { margin: 0 20px 0 0; }
            .lrp-customers-card {
                background: #fff;
                border: 1px solid #e3eaf5;
                border-radius: 14px;
                padding: 26px;
                box-shadow: 0 12px 32px rgba(15, 23, 42, 0.10);
                margin-top: 22px;
            }
            .lrp-customers-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 20px;
                margin-bottom: 28px;
            }
            .lrp-customers-title-group {
                display: grid;
                grid-template-columns: 60px minmax(0, 1fr);
                gap: 18px;
                align-items: center;
            }
            .lrp-customers-icon {
                width: 60px;
                height: 60px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 9px;
                background: #edf7ff;
                color: #133851;
            }
            .lrp-customers-icon .dashicons {
                width: 30px;
                height: 30px;
                font-size: 30px;
            }
            .lrp-customers-title {
                margin: 0 0 7px;
                color: #07173d;
                font-size: 28px;
                font-weight: 700;
                line-height: 1.1;
            }
            .lrp-customers-subtitle {
                margin: 0;
                color: #33476b;
                font-size: 15px;
            }
            .lrp-customers-header-actions {
                display: flex;
                gap: 12px;
                align-items: center;
                flex-wrap: wrap;
                justify-content: flex-end;
            }
            .lrp-customers-export {
                min-height: 40px;
                padding: 0 16px;
                display: inline-flex;
                align-items: center;
                gap: 10px;
                border: 1px solid #38A1E7;
                border-radius: 5px;
                background: #133851;
                color: #fff;
                font-size: 14px;
                font-weight: 700;
                text-decoration: none;
                box-sizing: border-box;
                transition: all 0.2s ease;
            }
            .lrp-customers-export:hover,
            .lrp-customers-export:focus {
                background: #0d2a3e;
                color: #fff;
                border-color: #38A1E7;
            }
            .lrp-customers-export .dashicons { color: #fff; }
            .lrp-customers-toolbar {
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 22px;
                justify-content: flex-start;
                flex-wrap: wrap;
            }
            .lrp-customers-search {
                width: 376px;
                max-width: 100%;
                position: relative;
                display: block;
            }
            .lrp-customers-search .dashicons {
                position: absolute;
                left: 18px;
                top: 50%;
                transform: translateY(-50%);
                color: #133851;
                pointer-events: none;
            }
            .lrp-customers-input {
                width: 100%;
                height: 50px;
                min-height: 50px;
                padding: 0 16px 0 52px !important;
                border: 1px solid #c9def4 !important;
                border-radius: 5px !important;
                background-color: #fff !important;
                color: #07173d !important;
                font-size: 16px !important;
                line-height: 50px !important;
                box-shadow: none !important;
                box-sizing: border-box;
            }
            .lrp-customers-search-btn {
                height: 50px;
                min-height: 50px;
                padding: 0 24px;
                border: 1px solid #38A1E7;
                border-radius: 5px;
                background: #133851;
                color: #fff;
                font-size: 15px;
                font-weight: 700;
                cursor: pointer;
                box-shadow: 0 8px 16px rgba(56, 161, 231, 0.18);
                box-sizing: border-box;
                transition: all 0.2s ease;
            }
            .lrp-customers-search-btn:hover,
            .lrp-customers-search-btn:focus {
                background: #0d2a3e;
                color: #fff;
            }
            .lrp-customers-reset-btn {
                height: 50px;
                min-height: 50px;
                padding: 0 20px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 1px solid #cbdaf5;
                border-radius: 5px;
                background: #fff;
                color: #475569;
                font-size: 15px;
                font-weight: 600;
                text-decoration: none;
                box-sizing: border-box;
                transition: all 0.2s ease;
            }
            .lrp-customers-reset-btn:hover {
                background: #f1f5f9;
                color: #07173d;
                border-color: #94a3b8;
            }
            .lrp-customers-table-wrap {
                overflow-x: auto;
                border-radius: 5px;
                border: 1px solid #e3eaf5;
            }
            .lrp-customers-table {
                width: 100%;
                min-width: 900px;
                border-collapse: collapse;
                font-size: 14px;
                text-align: left;
                color: #07173d;
                border: 0 !important;
            }
            .lrp-customers-table thead {
                background: #133851 !important;
            }
            .lrp-customers-table th {
                padding: 16px 18px !important;
                border-bottom: 1px solid #dfe7f3 !important;
                color: #fff !important;
                font-weight: 700;
                white-space: nowrap;
            }
            .lrp-customers-table td {
                padding: 16px 18px !important;
                border-bottom: 1px solid #e9eef6 !important;
                color: #07173d;
                vertical-align: middle;
            }
            .lrp-customers-table tbody tr:nth-child(even) {
                background: #f8fafc;
            }
            .lrp-customers-table tbody tr:hover {
                background: #f1f7fc;
            }
            .lrp-customers-table tbody tr:last-child td {
                border-bottom: 0 !important;
            }
            .lrp-badge {
                min-width: 48px;
                min-height: 28px;
                padding: 4px 12px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 1px solid #bce9c7;
                border-radius: 8px;
                background: #dcf8e1;
                color: #087b20;
                font-weight: 700;
                font-size: 13px;
                box-sizing: border-box;
            }
            .lrp-badge.no,
            .lrp-badge.inactive {
                border-color: #ffd4d4;
                background: #fff0f0;
                color: #b42336;
            }
            .lrp-view-btn {
                width: 36px;
                height: 36px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 6px;
                border: 1px solid #cbdaf5;
                background: #fff;
                color: #133851;
                text-decoration: none;
                transition: all 0.2s ease;
                box-sizing: border-box;
            }
            .lrp-view-btn:hover,
            .lrp-view-btn:focus {
                background: #133851;
                color: #fff;
                border-color: #133851;
                box-shadow: 0 4px 8px rgba(19, 56, 81, 0.15);
            }
            .lrp-view-btn .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
            }
            .lrp-customers-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 18px;
                margin-top: 28px;
                color: #07173d;
                font-size: 15px;
            }
            .lrp-customers-footer .lrp-pager,
            .lrp-customers-footer .lrp-pagination {
                display: flex;
                justify-content: flex-end;
                align-items: center;
                gap: 12px;
                margin: 0;
            }
            .lrp-customers-footer .page-numbers,
            .lrp-customers-footer a.button {
                min-width: 40px;
                min-height: 40px;
                padding: 0 14px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 5px;
                border: 1px solid #cbdaf5;
                text-decoration: none;
                color: #07173d;
                background: #fff;
                font-weight: 700;
                box-sizing: border-box;
                font-size: 14px;
                transition: all 0.2s ease;
            }
            .lrp-customers-footer .page-numbers.current {
                background: #133851;
                color: #fff;
                border-color: #38A1E7;
                box-shadow: 0 8px 16px rgba(56, 161, 231, 0.18);
            }
            .lrp-customers-footer .page-numbers:hover:not(.current) {
                border-color: #38A1E7;
                color: #38A1E7;
            }
            @media (max-width: 782px) {
                .lrp-customers-wrap { margin-right: 10px; }
                .lrp-customers-card { padding: 18px; }
                .lrp-customers-header,
                .lrp-customers-footer {
                    flex-direction: column;
                    align-items: stretch;
                }
                .lrp-customers-toolbar,
                .lrp-customers-header-actions {
                    flex-direction: column;
                    align-items: stretch;
                }
                .lrp-customers-search { width: 100%; }
                .lrp-customers-footer .lrp-pager,
                .lrp-customers-footer .lrp-pagination {
                    justify-content: flex-start;
                    flex-wrap: wrap;
                }
            }
        </style>';

        echo '<div class="wrap lrp-customers-wrap">';
        LRP_Admin::render_loyalty_nav_tabs( 'lrp-loyalty-customers' );

        echo '<div class="lrp-customers-card">';
        echo '<div class="lrp-customers-header">
                <div class="lrp-customers-title-group">
                    <span class="lrp-customers-icon"><span class="dashicons dashicons-groups"></span></span>
                    <div>
                        <h1 class="lrp-customers-title">' . esc_html__( 'Loyalty Customers', 'netscore-loyalty-rewards' ) . '</h1>
                        <p class="lrp-customers-subtitle">' . esc_html__( 'View and manage customers enrolled in your loyalty rewards program.', 'netscore-loyalty-rewards' ) . '</p>
                    </div>
                </div>';

        $export_url = wp_nonce_url(
            admin_url( 'admin.php?page=lrp-loyalty-customers&lrp_export_loyalty_customers=1' ),
            'lrp_export_loyalty_customers_action',
            'lrp_export_nonce'
        );

        echo '<div class="lrp-customers-header-actions">
                <a href="' . esc_url( $export_url ) . '" class="lrp-customers-export">
                    <span class="dashicons dashicons-download"></span> ' . esc_html__( 'Export CSV', 'netscore-loyalty-rewards' ) . '
                </a>
              </div>';
        echo '</div>';

        echo '<form method="get" action="" class="lrp-customers-toolbar">';
        echo '<input type="hidden" name="page" value="lrp-loyalty-customers" />';
        echo '<label class="lrp-customers-search">
                <span class="dashicons dashicons-search"></span>
                <input type="search" name="lrp_search" value="' . esc_attr( $search_term ) . '" class="lrp-customers-input" placeholder="' . esc_attr__( 'Search by name or email...', 'netscore-loyalty-rewards' ) . '" />
              </label>';
        echo '<button type="submit" class="lrp-customers-search-btn">' . esc_html__( 'Filter', 'netscore-loyalty-rewards' ) . '</button>';
        if ( ! empty( $search_term ) ) {
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=lrp-loyalty-customers' ) ) . '" class="lrp-customers-reset-btn">' . esc_html__( 'Reset', 'netscore-loyalty-rewards' ) . '</a>';
        }
        echo '</form>';

        echo '<div class="lrp-customers-table-wrap">';
        echo '<table class="lrp-customers-table">
                <thead>
                    <tr>
                        <th style="width:60px;">' . esc_html__( 'S.No', 'netscore-loyalty-rewards' ) . '</th>
                        <th>' . esc_html__( 'Display Name', 'netscore-loyalty-rewards' ) . '</th>
                        <th>' . esc_html__( 'Email', 'netscore-loyalty-rewards' ) . '</th>
                        <th>' . esc_html__( 'Points Earned', 'netscore-loyalty-rewards' ) . '</th>
                        <th>' . esc_html__( 'Points Redeemed', 'netscore-loyalty-rewards' ) . '</th>
                        <th>' . esc_html__( 'Points Available', 'netscore-loyalty-rewards' ) . '</th>
                        <th>' . esc_html__( 'Eligible', 'netscore-loyalty-rewards' ) . '</th>
                        <th style="text-align:center;width:90px;">' . esc_html__( 'Action', 'netscore-loyalty-rewards' ) . '</th>
                    </tr>
                </thead>
                <tbody>';

        if ( ! empty( $results ) ) {
            $sno = $offset + 1;
            foreach ( $results as $row ) {
                $total_earned = number_format( (float) $row->total_earned, 2 );
                $total_redeemed = number_format( (float) $row->total_redeemed_points, 2 );
                $available_points = number_format( (float) $row->available_points, 2 );
                $is_eligible_bool = (int) $row->is_eligible_for_loyalty_program;
                $is_eligible_label = $is_eligible_bool ? __( 'Yes', 'netscore-loyalty-rewards' ) : __( 'No', 'netscore-loyalty-rewards' );
                $badge_class = $is_eligible_bool ? 'lrp-badge yes' : 'lrp-badge no';

                echo '<tr>
                    <td>' . esc_html( $sno ) . '</td>
                    <td>' . esc_html( $row->display_name ?: 'N/A' ) . '</td>
                    <td>' . esc_html( $row->user_email ?: 'N/A' ) . '</td>
                    <td>' . esc_html( $total_earned ) . '</td>
                    <td>' . esc_html( $total_redeemed ) . '</td>
                    <td>' . esc_html( $available_points ) . '</td>
                    <td><span class="' . esc_attr( $badge_class ) . '">' . esc_html( $is_eligible_label ) . '</span></td>
                    <td style="text-align:center;">
                        <a href="#" class="lrp-view-btn lrp-open-events" data-customer-id="' . esc_attr( $row->ID ) . '" title="' . esc_attr__( 'View customer events', 'netscore-loyalty-rewards' ) . '" aria-label="' . esc_attr__( 'View customer events', 'netscore-loyalty-rewards' ) . '">
                            <span class="dashicons dashicons-visibility"></span>
                        </a>
                    </td>
                </tr>';
                $sno++;
            }
        } else {
            echo '<tr><td colspan="8" style="padding:28px;text-align:center;color:#64748b;">' . esc_html__( 'No customer data found.', 'netscore-loyalty-rewards' ) . '</td></tr>';
        }

        echo '</tbody></table></div>';

        // Pagination
        $pagination_args = [
            'base'      => add_query_arg( 'paged', '%#%' ),
            'format'    => '',
            'current'   => $paged,
            'total'     => $max_pages,
            'prev_text' => '&lsaquo;',
            'next_text' => 'Next <span class="dashicons dashicons-arrow-right-alt2"></span>',
            'type'      => 'plain',
        ];

        $pagination = paginate_links( $pagination_args );

        echo '<div class="lrp-customers-footer">';
        echo '<div>' . sprintf( esc_html__( 'Showing %1$s to %2$s of %3$s entries', 'netscore-loyalty-rewards' ), esc_html( $first_entry ), esc_html( $last_entry ), esc_html( $total_users ) ) . '</div>';
        echo '<div class="lrp-pager">' . wp_kses_post( $pagination ) . '</div>';
        echo '</div>';

        echo '</div></div>';
    }

    /**
     * Handle CSV export of loyalty customers.
     */
    public static function handle_export() {
        if ( ! isset( $_GET['lrp_export_loyalty_customers'] ) || $_GET['lrp_export_loyalty_customers'] !== '1' ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'netscore-loyalty-rewards' ) );
        }

        check_admin_referer( 'lrp_export_loyalty_customers_action', 'lrp_export_nonce' );

        global $wpdb;
        $users_table = $wpdb->users;
        $usermeta    = $wpdb->usermeta;
        $pts_table   = $wpdb->prefix . 'netscore_lty_cust_lty_pts_table';

        $sql = "
            SELECT DISTINCT
                u.ID,
                u.display_name,
                u.user_email,
                COALESCE(pts.points_earned,0) AS total_earned,
                COALESCE(pts.points_redeemed,0) AS total_redeemed_points,
                COALESCE(pts.points_available,0) AS available_points,
                COALESCE(pts.is_eligible_for_loyalty_program, 0) AS is_eligible_for_loyalty_program,
                pts.loyalty_eligible_date AS loyalty_eligible_date
            FROM {$users_table} u
            INNER JOIN {$usermeta} um_role
                ON (um_role.user_id = u.ID 
                    AND um_role.meta_key LIKE %s 
                    AND um_role.meta_value LIKE %s)
            LEFT JOIN {$pts_table} pts ON pts.customer_id = u.ID
            WHERE COALESCE(pts.is_eligible_for_loyalty_program, 0) = 1
            ORDER BY u.ID DESC
        ";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, '%capabilities%', '%\"customer\"%' ) );

        $filename = 'loyalty-customers-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, [ 'ID', 'Display Name', 'Email', 'Points Earned', 'Points Redeemed', 'Points Available', 'Eligible', 'Eligible Date' ] );

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                fputcsv( $output, [
                    $row->ID,
                    $row->display_name,
                    $row->user_email,
                    $row->total_earned,
                    $row->total_redeemed_points,
                    $row->available_points,
                    $row->is_eligible_for_loyalty_program ? 'Yes' : 'No',
                    $row->loyalty_eligible_date ?: 'N/A',
                ] );
            }
        }

        fclose( $output );
        exit;
    }
}
