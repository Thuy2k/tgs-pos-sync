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
        $selected_global_tables = isset($_POST['selected_global_tables']) ? $_POST['selected_global_tables'] : array();
        $selected_local_tables = isset($_POST['selected_local_tables']) ? $_POST['selected_local_tables'] : array();

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

        // UPSERT GLOBAL data (chỉ các bảng được chọn)
        $batch_summary = self::upsert_selected_global_data($schema_data['global_data'], $selected_global_tables);

        // UPSERT LOCAL data (chỉ các bảng được chọn)
        $local_summary = self::upsert_selected_local_data($schema_data['local_data'], $selected_local_tables);

        // Merge summary
        $batch_summary = array_merge($batch_summary, $local_summary);

        // Check còn data không - CHỈ check nếu có GLOBAL tables được chọn
        $has_more = false;
        if (!empty($selected_global_tables)) {
            $has_more = $schema_data['global_data']['summary']['has_more'] ?? false;
        }

        // Update cursors cho batch tiếp theo (chỉ khi có GLOBAL tables)
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
            update_option('tgs_pos_last_pull_local_data_at', $server_time);
        }

        wp_send_json_success(array(
            'batch_summary' => $batch_summary,
            'has_more' => $has_more,
            'cursors' => $next_cursors,
        ));
    }

    /**
     * Upsert chỉ GLOBAL tables được chọn
     */
    private static function upsert_selected_global_data($global_data, $selected_tables) {
        if (empty($selected_tables)) {
            return array('categories' => 0, 'products' => 0, 'policies' => 0, 'lots' => 0);
        }

        // Filter data theo tables được chọn
        $filtered_data = array();
        $table_mapping = array(
            'sql_global_product_cat' => 'categories',
            'sql_global_product_name' => 'products',
            'sql_global_selling_policy' => 'selling_policies',
            'sql_global_selling_policy_items' => 'selling_policy_items',
            'sql_global_purchase_policy' => 'purchase_policies',
            'sql_global_purchase_policy_item' => 'purchase_policy_items',
            'sql_global_product_lots' => 'product_lots',
            'sql_global_supplier' => 'suppliers',
        );

        foreach ($selected_tables as $table) {
            $data_key = $table_mapping[$table] ?? null;
            if ($data_key && isset($global_data[$data_key])) {
                $filtered_data[$data_key] = $global_data[$data_key];
            }
        }

        // Chỉ copy cursors nếu bảng tương ứng được chọn
        if (in_array('sql_global_product_cat', $selected_tables)) {
            $filtered_data['cursor_cat_next'] = $global_data['cursor_cat_next'] ?? PHP_INT_MAX;
        }
        if (in_array('sql_global_product_name', $selected_tables)) {
            $filtered_data['cursor_product_next'] = $global_data['cursor_product_next'] ?? PHP_INT_MAX;
        }
        if (in_array('sql_global_selling_policy', $selected_tables) || in_array('sql_global_purchase_policy', $selected_tables)) {
            $filtered_data['cursor_policy_next'] = $global_data['cursor_policy_next'] ?? PHP_INT_MAX;
        }
        if (in_array('sql_global_product_lots', $selected_tables)) {
            $filtered_data['cursor_lot_next'] = $global_data['cursor_lot_next'] ?? PHP_INT_MAX;
        }

        // Copy summary (cần thiết cho logic pagination)
        $filtered_data['summary'] = $global_data['summary'];

        return TGS_POS_Schema_Manager::upsert_global_data_direct($filtered_data);
    }

    /**
     * Upsert chỉ LOCAL tables được chọn
     */
    private static function upsert_selected_local_data($local_data, $selected_tables) {
        global $wpdb;

        if (empty($selected_tables) || empty($local_data)) {
            return array('local_records' => 0);
        }

        $total_records = 0;
        $details = array(); // Debug: track per table

        foreach ($selected_tables as $method_name) {
            // Extract tên bảng: sql_local_ledger -> local_ledger
            $base_table = str_replace('sql_', '', $method_name);

            // Check data có tồn tại không
            if (empty($local_data[$base_table])) {
                $details[$base_table] = 0;
                continue;
            }

            $table_name = $wpdb->prefix . $base_table;
            $records = $local_data[$base_table];
            $table_count = 0;

            // Upsert từng record
            foreach ($records as $record) {
                // Tìm primary key
                $pk = self::get_primary_key_from_record($record);
                if (!$pk) {
                    continue;
                }

                $pk_column = key($pk);
                $pk_value = current($pk);

                // Check exist
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT {$pk_column} FROM {$table_name} WHERE {$pk_column} = %s LIMIT 1",
                    $pk_value
                ));

                if ($exists) {
                    // Update
                    $wpdb->update($table_name, $record, array($pk_column => $pk_value));
                } else {
                    // Insert
                    $wpdb->insert($table_name, $record);
                }

                $table_count++;
                $total_records++;
            }

            $details[$base_table] = $table_count;
        }

        // Log chi tiết
        error_log('LOCAL upsert details: ' . print_r($details, true));

        return array('local_records' => $total_records, 'local_details' => $details);
    }

    /**
     * Get primary key from record
     * Auto-detect từ tên bảng thay vì hardcode
     */
    private static function get_primary_key_from_record($record) {
        // Thử các pattern phổ biến theo thứ tự ưu tiên
        $possible_patterns = array(
            // Pattern 1: {table_name}_id (phổ biến nhất)
            // VD: local_ledger_meta -> local_ledger_meta_id
            function($record) {
                foreach (array_keys($record) as $key) {
                    if (preg_match('/^[a-z_]+_id$/', $key)) {
                        return array($key => $record[$key]);
                    }
                }
                return null;
            },
            // Pattern 2: Cột tên 'id'
            function($record) {
                if (isset($record['id'])) {
                    return array('id' => $record['id']);
                }
                return null;
            },
        );

        // Thử từng pattern
        foreach ($possible_patterns as $pattern) {
            $result = $pattern($record);
            if ($result !== null) {
                return $result;
            }
        }

        // Fallback: Lấy cột đầu tiên có chứa 'id'
        foreach (array_keys($record) as $key) {
            if (stripos($key, 'id') !== false) {
                return array($key => $record[$key]);
            }
        }

        return null;
    }
}

// Initialize
TGS_POS_Full_Sync_AJAX::init();
