<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class LRP_Items_Page {

    public function __construct() {

        // CSV export handler
        add_action('admin_init', [$this, 'export_items_csv']);
    }

    /**
     * Standalone admin page outside LRP Admin
     */
   public static function render_items_page_static() {
        $obj = new self();
        $obj->render_items_page();
    }
    

    /**
     * Render Items Page
     */
    public function render_items_page() {
    global $wpdb;

    $table = $wpdb->prefix . 'netscore_lty_item_lty_pts_table';

    // Filters
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $filter_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';
    $filter_eligible = isset($_GET['filter_eligible']) ? sanitize_text_field($_GET['filter_eligible']) : '';
    if (!in_array($filter_type, ['points', 'amount'], true)) {
        $filter_type = '';
    }
    if (!in_array($filter_eligible, ['yes', 'no'], true)) {
        $filter_eligible = '';
    }

    // Pagination
    $per_page = 10;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;

    $search_like = '%' . $wpdb->esc_like($search) . '%';
    $eligible_value = $filter_eligible === 'yes' ? 1 : 0;

    // Count total
    $total = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE (%s = '' OR item_id LIKE %s)
               AND (%s = '' OR collection_type = %s)
               AND (%s = '' OR is_eligible_for_loyalty_program = %d)",
            $table,
            $search,
            $search_like,
            $filter_type,
            $filter_type,
            $filter_eligible,
            $eligible_value
        )
    );

    // Fetch items
    $items = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM %i
             WHERE (%s = '' OR item_id LIKE %s)
               AND (%s = '' OR collection_type = %s)
               AND (%s = '' OR is_eligible_for_loyalty_program = %d)
             ORDER BY id DESC
             LIMIT %d OFFSET %d",
            $table,
            $search,
            $search_like,
            $filter_type,
            $filter_type,
            $filter_eligible,
            $eligible_value,
            $per_page,
            $offset
        )
    );
    ?>

    <style>
        .lrp-items-wrap { margin: 0 20px 0 0; }
        .lrp-items-card {
            background: #fff;
            border: 1px solid #e3eaf5;
            border-radius: 14px;
            padding: 26px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.10);
            margin-top: 22px;
        }
        .lrp-items-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 28px;
        }
        .lrp-items-title-group {
            display: grid;
            grid-template-columns: 60px minmax(0, 1fr);
            gap: 18px;
            align-items: center;
        }
        .lrp-items-icon {
            width: 60px;
            height: 60px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: #edf7ff;
            color: #133851;
        }
        .lrp-items-icon .dashicons {
            width: 30px;
            height: 30px;
            font-size: 30px;
        }
        .lrp-items-title {
            margin: 0 0 7px;
            color: #07173d;
            font-size: 28px;
            font-weight: 700;
            line-height: 1.1;
        }
        .lrp-items-subtitle {
            margin: 0;
            color: #33476b;
            font-size: 15px;
        }
        .lrp-items-export {
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
        }
        .lrp-items-export .dashicons { color: #fff; }
        .lrp-items-filters {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }
        .lrp-items-search {
            width: 376px;
            max-width: 100%;
            position: relative;
            display: block;
        }
        .lrp-items-search .dashicons {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #133851;
            pointer-events: none;
        }
        .lrp-items-input,
        .lrp-items-select {
            height: 50px;
            min-height: 50px;
            border: 1px solid #c9def4 !important;
            border-radius: 5px !important;
            background-color: #fff !important;
            color: #07173d !important;
            box-shadow: none !important;
            font-size: 16px !important;
            line-height: 50px !important;
            box-sizing: border-box;
        }
        .lrp-items-input {
            width: 100%;
            padding: 0 16px 0 52px !important;
        }
        .lrp-items-select {
            width: 238px;
            padding: 0 46px 0 16px !important;
            appearance: none;
            -webkit-appearance: none;
            background-image: linear-gradient(45deg, transparent 50%, #07173d 50%), linear-gradient(135deg, #07173d 50%, transparent 50%) !important;
            background-position: calc(100% - 20px) 21px, calc(100% - 14px) 21px !important;
            background-size: 6px 6px, 6px 6px !important;
            background-repeat: no-repeat !important;
        }
        .lrp-items-filter-btn {
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
        }
        .lrp-items-table-wrap {
            overflow-x: auto;
            border-radius: 5px;
            border: 1px solid #e3eaf5;
        }
        .lrp-items-table {
            width: 100%;
            min-width: 980px;
            border-collapse: collapse;
            font-size: 14px;
            text-align: left;
            color: #07173d;
        }
        .lrp-items-table thead { background: #133851 ; }
        .lrp-items-table th {
            padding: 16px 18px;
            border-bottom: 1px solid #dfe7f3;
            color: #fff;
            font-weight: 700;
            white-space: nowrap;
        }
        .lrp-items-table td {
            padding: 16px 18px;
            border-bottom: 1px solid #e9eef6;
            color: #07173d;
            vertical-align: middle;
        }
        .lrp-items-table tbody tr:nth-child(even) { background: #f8fafc; }
        .lrp-items-table tbody tr:last-child td { border-bottom: 0; }
        .lrp-items-badge {
            min-width: 48px;
            min-height: 28px;
            padding: 0 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #bce9c7;
            border-radius: 8px;
            background: #dcf8e1;
            color: #087b20;
            font-weight: 700;
        }
        .lrp-items-badge.no {
            border-color: #ffd4d4;
            background: #fff0f0;
            color: #b42336;
        }
        .lrp-items-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            margin-top: 28px;
            color: #07173d;
            font-size: 15px;
        }
        .lrp-pager {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            margin: 0;
        }
        .lrp-pager a,
        .lrp-pager span {
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
        }
        .lrp-pager .current {
            background: #133851;
            color: #fff;
            border-color: #38A1E7;
            box-shadow: 0 8px 16px rgba(56, 161, 231, 0.18);
        }
        .lrp-pager a:hover {
            border-color: #38A1E7;
            color: #38A1E7;
        }
        @media (max-width: 782px) {
            .lrp-items-wrap { margin-right: 10px; }
            .lrp-items-card { padding: 18px; }
            .lrp-items-header,
            .lrp-items-footer {
                flex-direction: column;
                align-items: stretch;
            }
            .lrp-items-filters {
                flex-direction: column;
                align-items: stretch;
            }
            .lrp-items-search,
            .lrp-items-select {
                width: 100%;
            }
            .lrp-pager {
                justify-content: flex-start;
                flex-wrap: wrap;
            }
        }
    </style>

    <div class="wrap lrp-items-wrap">
        <?php
        if ( class_exists( 'LRP_Admin' ) && is_callable( [ 'LRP_Admin', 'render_loyalty_nav_tabs' ] ) ) {
            LRP_Admin::render_loyalty_nav_tabs( 'lrp-items' );
        }
        ?>

        <!-- MAIN CARD -->
        <div class="lrp-items-card">

            <!-- HEADER -->
            <div class="lrp-items-header">
                <div class="lrp-items-title-group">
                    <span class="lrp-items-icon"><span class="dashicons dashicons-products"></span></span>
                    <div>
                        <h1 class="lrp-items-title">Loyalty Items</h1>
                        <p class="lrp-items-subtitle">View and manage loyalty program items.</p>
                    </div>
                </div>

                <a href="<?php echo esc_url(admin_url('admin.php?page=lrp-items&export=csv')); ?>" class="lrp-items-export">
                    <span class="dashicons dashicons-download"></span>
                    Export CSV
                </a>
            </div>

            <!-- FILTERS -->
            <form method="GET" class="lrp-items-filters">

                <input type="hidden" name="page" value="lrp-items">

                <label class="lrp-items-search">
                    <span class="dashicons dashicons-search"></span>
                    <input type="search"
                            name="s"
                            class="lrp-items-input"
                            placeholder="Search by Product ID"
                            value="<?php echo esc_attr($search); ?>">
                </label>

                <select name="filter_type" class="lrp-items-select">
                    <option value="">Filter by Points Type</option>
                    <option value="points" <?php selected($filter_type,'points'); ?>>Points</option>
                    <option value="amount" <?php selected($filter_type,'amount'); ?>>Amount</option>
                </select>

                <select name="filter_eligible" class="lrp-items-select">
                    <option value="">Filter by Eligibility</option>
                    <option value="yes" <?php selected($filter_eligible,'yes'); ?>>Yes</option>
                    <option value="no" <?php selected($filter_eligible,'no'); ?>>No</option>
                </select>

                <button class="lrp-items-filter-btn">Filter</button>

            </form>

            <!-- TABLE -->
            <div class="lrp-items-table-wrap">
                <table class="lrp-items-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Is Loyalty Eligibility</th>
                            <th>Points Type</th>
                            <th>Points Based</th>
                            <th>SKU Based</th>
                            <th>Amount Based</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($items): $serial = $offset + 1; ?>
                            <?php foreach ($items as $row):
                                $product = wc_get_product($row->item_id);
                                $title = $product ? $product->get_name() : '—';
                                $eligibility = $row->is_eligible_for_loyalty_program ? 'Yes' : 'No';
                                $collect_type = ucfirst($row->collection_type);
                                $points = $row->points_based_points ?: 0;
                                $sku = $row->sku_based_points ?: 0;
                                $amount_based = $collect_type === 'Amount' ? $sku : '—';
                            ?>
                                <tr>
                                    <td><?php echo esc_html($serial++); ?></td>
                                    <td><?php echo esc_html($row->item_id); ?></td>
                                    <td><?php echo esc_html($title); ?></td>
                                    <td><span class="lrp-items-badge <?php echo $eligibility === 'No' ? 'no' : ''; ?>"><?php echo esc_html($eligibility); ?></span></td>
                                    <td><?php echo esc_html($collect_type); ?></td>
                                    <td><?php echo esc_html($points); ?></td>
                                    <td><?php echo esc_html($sku); ?></td>
                                    <td><?php echo esc_html($amount_based); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center;padding:28px;">No items found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <?php
            $total_pages = ceil($total / $per_page);
            $first_entry = $total > 0 ? $offset + 1 : 0;
            $last_entry  = $total > 0 ? min($offset + $per_page, $total) : 0;
            $pagination_html = '';
            if ($total_pages > 1):

                // preserve filters
                $args = ['page' => 'lrp-items'];
                if ($search !== '') $args['s'] = $search;
                if ($filter_type !== '') $args['filter_type'] = $filter_type;
                if ($filter_eligible !== '') $args['filter_eligible'] = $filter_eligible;

                $base = esc_url_raw(
                    add_query_arg(array_merge($args, ['paged' => '%#%']), admin_url('admin.php'))
                );

                $page_links = paginate_links([
                    'base'      => $base,
                    'format'    => '',
                    'prev_text' => '&lsaquo;',
                    'next_text' => 'Next <span class="dashicons dashicons-arrow-right-alt2"></span>',
                    'total'     => $total_pages,
                    'current'   => $paged,
                    'type'      => 'plain'
                ]);

                if (!empty($page_links)):
                    $pagination_html = $page_links;
            ?>
            <?php endif; endif; ?>

            <div class="lrp-items-footer">
                <div>
                    Showing <?php echo esc_html($first_entry); ?> to <?php echo esc_html($last_entry); ?> of <?php echo esc_html($total); ?> entries
                </div>
                <div class="lrp-pager">
                    <?php echo wp_kses_post($pagination_html); ?>
                </div>
            </div>

        </div>

    </div>
<?php
}



    /**
     * CSV Export
     */
    public function export_items_csv() {
        if (!isset($_GET['export']) || $_GET['export'] !== 'csv') return;
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'netscore_lty_item_lty_pts_table';

        $items = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM %i ORDER BY id DESC", $table)
        );

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="loyalty-items.csv"');

        $output = fopen("php://output", "w");

        fputcsv($output, [
            'S.No','Product ID','Product Name','Is Loyalty Eligibility',
            'Points Type','Points Based','SKU Based','Amount Based','Total Loyalty Points'
        ], ',', '"', '\\');

        $serial = 1;

        foreach ($items as $row) {

            $product = wc_get_product($row->item_id);
            $title = $product ? $product->get_name() : '—';

            $eligibility = $row->is_eligible_for_loyalty_program ? 'Yes' : 'No';
            $collect_type = ucfirst($row->collection_type);
            $points = $row->points_based_points ?: 0;
            $sku = $row->sku_based_points ?: 0;
            $amount_based = ($collect_type === 'Amount') ? $sku : '—';
            $total_points = ($collect_type === 'Points') ? $points : $sku;

            fputcsv($output, [
                $serial++,
                $row->item_id,
                $title,
                $eligibility,
                $collect_type,
                $points,
                $sku,
                $amount_based,
                $total_points
            ], ',', '"', '\\');
        }

        fclose($output);
        exit;
    }
}
