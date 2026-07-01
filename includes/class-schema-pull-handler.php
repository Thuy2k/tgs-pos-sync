<?php
/**
 * Schema Pull Handler
 * AJAX handler cho việc pull schema từ Hub
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Schema_Pull_Handler {

    /**
     * AJAX handler - Pull schema
     */
    public static function ajax_pull_schema() {
        check_ajax_referer('tgs_pos_pull_schema', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // Kiểm tra đã registered chưa
        if (!TGS_POS_Config::is_registered()) {
            wp_send_json_error(__('Chưa kết nối với Hub. Vui lòng đăng ký trước.', 'tgs-pos-sync'));
        }

        // Pull và apply schema
        $result = TGS_POS_Schema_Manager::pull_and_apply();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX handler - Get schema status
     */
    public static function ajax_get_schema_status() {
        check_ajax_referer('tgs_pos_schema_status', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $status = TGS_POS_Database_Schema::get_schema_status();

        wp_send_json_success(array(
            'local' => $status['local'],
            'global' => $status['global'],
            'is_ready' => $status['is_complete'],
        ));
    }
}

// Register AJAX handlers
add_action('wp_ajax_tgs_pos_pull_schema', array('TGS_POS_Schema_Pull_Handler', 'ajax_pull_schema'));
add_action('wp_ajax_tgs_pos_schema_status', array('TGS_POS_Schema_Pull_Handler', 'ajax_get_schema_status'));
