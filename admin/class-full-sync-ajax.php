<?php
/**
 * Full Sync AJAX Handlers
 * Xử lý AJAX requests cho Full Sync
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Full_Sync_AJAX {

    /**
     * Register AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_tgs_full_sync_truncate', array(__CLASS__, 'handle_truncate'));
        add_action('wp_ajax_tgs_full_sync_batch', array(__CLASS__, 'handle_batch'));
    }

    /**
     * Handle TRUNCATE request - Xóa data cũ
     */
    public static function handle_truncate() {
        check_ajax_referer('tgs_full_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        global $wpdb;

        $global_tables = isset($_POST['global_tables']) ? $_POST['global_tables'] : array();
        $local_tables = isset($_POST['local_tables']) ? $_POST['local_tables'] : array();

        // TRUNCATE GLOBAL tables
        foreach ($global_tables as $method_name) {
            $table_name = 'wp_' . str_replace('sql_', '', $method_name);
            $wpdb->query("TRUNCATE TABLE {$table_name}");
        }

        // TRUNCATE LOCAL tables
        foreach ($local_tables as $method_name) {
            $table_name = $wpdb->prefix . str_replace('sql_', '', $method_name);
            $wpdb->query("TRUNCATE TABLE {$table_name}");
        }

        wp_send_json_success(array(
            'message' => 'Tables truncated',
            'global_count' => count($global_tables),
            'local_count' => count($local_tables),
        ));
    }

    /**
     * Handle BATCH request - Pull một batch từ Hub
     */
    public static function handle_batch() {
        check_ajax_referer('tgs_full_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $cursors = isset($_POST['cursors']) ? $_POST['cursors'] : array(
            'categories' => 0,
            'products' => 0,
            'policies' => 0,
            'lots' => 0,
        );

        $batch_count = isset($_POST['batch_count']) ? intval($_POST['batch_count']) : 0;

        // Pull một batch từ Hub (since = null để pull full)
        $result = TGS_POS_HTTP_Client::pull_schema(null, $cursors);

        if (!$result['success']) {
            wp_send_json_error('Hub error: ' . ($result['message'] ?? 'Unknown'));
        }

        $schema_data = $result['data'];

        // Execute SQL statements (chỉ lần đầu)
        if ($batch_count === 1 && !empty($schema_data['sql_statements'])) {
            TGS_POS_Database_Schema::execute_sql_statements($schema_data['sql_statements']);
        }

        // UPSERT batch này
        $batch_summary = TGS_POS_Schema_Manager::upsert_global_data_direct($schema_data['global_data']);

        // Check còn data không
        $has_more = $schema_data['global_data']['summary']['has_more'] ?? false;

        // Update cursors cho batch tiếp theo
        $next_cursors = array(
            'categories' => $schema_data['global_data']['cursor_cat_next'] ?? PHP_INT_MAX,
            'products' => $schema_data['global_data']['cursor_product_next'] ?? PHP_INT_MAX,
            'policies' => $schema_data['global_data']['cursor_policy_next'] ?? PHP_INT_MAX,
            'lots' => $schema_data['global_data']['cursor_lot_next'] ?? PHP_INT_MAX,
        );

        // Update watermark nếu hết
        if (!$has_more) {
            $server_time = $schema_data['server_time'] ?? current_time('mysql', true);
            update_option('tgs_pos_last_pull_global_data_at', $server_time);
        }

        wp_send_json_success(array(
            'batch_summary' => $batch_summary,
            'has_more' => $has_more,
            'cursors' => $next_cursors,
        ));
    }
}

// Initialize
TGS_POS_Full_Sync_AJAX::init();
