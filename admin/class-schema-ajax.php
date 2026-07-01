<?php
/**
 * Schema AJAX Handler
 * Xử lý AJAX sync schema (chỉ tạo tables, không pull data)
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Schema_AJAX {

    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_tgs_sync_schema', array(__CLASS__, 'sync_schema'));
    }

    /**
     * Sync schema: Pull SQL từ Hub và execute (tạo tables)
     */
    public static function sync_schema() {
        check_ajax_referer('tgs_sync_schema', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // Pull schema từ Hub (chỉ SQL statements, không có data)
        $result = TGS_POS_HTTP_Client::pull_schema(null);

        if (!$result['success']) {
            wp_send_json_error($result['message'] ?? 'Failed to pull schema from Hub');
        }

        $schema_data = $result['data'];

        // Execute SQL statements để tạo tables
        $execute_result = TGS_POS_Database_Schema::execute_sql_statements($schema_data['sql_statements']);

        wp_send_json_success(array(
            'global_created' => $execute_result['global']['created'],
            'local_created' => $execute_result['local']['created'],
            'global_failed' => $execute_result['global']['failed'],
            'local_failed' => $execute_result['local']['failed'],
        ));
    }
}

// Initialize
TGS_POS_Schema_AJAX::init();
