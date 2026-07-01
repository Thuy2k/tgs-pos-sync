<?php
/**
 * QR Scanner
 * Quét QR Code để đăng ký với Hub
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_QR_Scanner {

    /**
     * Register bằng QR Code data
     *
     * QR data format:
     * {
     *   "hub_url": "https://hub.tgsworld.vn",
     *   "setup_token": "abc123...",
     *   "blog_id": 5,
     *   "store_id": "PT001"
     * }
     */
    public static function register_with_qr($qr_data_json) {
        $qr_data = json_decode($qr_data_json, true);

        if (!$qr_data || !isset($qr_data['hub_url'], $qr_data['setup_token'])) {
            return array('success' => false, 'message' => __('QR Code không hợp lệ', 'tgs-pos-sync'));
        }

        // Check if testing within same WordPress instance (internal call)
        $current_site_url = get_site_url(1); // Main site URL
        $is_internal = (strpos($qr_data['hub_url'], $current_site_url) === 0);

        if ($is_internal && class_exists('TGS_Hub_Auth_Handler')) {
            // Direct internal call - bypass HTTP
            $request = new WP_REST_Request('POST', '/tgs-hub/v1/auth/register');
            $request->set_param('setup_token', $qr_data['setup_token']);

            $response = TGS_Hub_Auth_Handler::register($request);

            if (is_wp_error($response)) {
                return array('success' => false, 'message' => $response->get_error_message());
            }

            // Extract data from WP_REST_Response (already has 'success' and 'data' structure)
            $response_data = $response->get_data();
            $result = array('success' => $response_data['success'], 'data' => $response_data['data']);
        } else {
            // External call via HTTP
            $result = TGS_POS_HTTP_Client::register($qr_data['hub_url'], $qr_data['setup_token']);
        }

        if (!$result['success']) {
            return $result;
        }

        // Debug log
        error_log('QR Scanner - Result data: ' . print_r($result['data'], true));

        // Lưu config
        TGS_POS_Config::save_registration(array(
            'hub_url' => $qr_data['hub_url'],
            'client_token' => $result['data']['client_token'] ?? '',
            'blog_id' => $result['data']['blog_id'] ?? '',
            'store_id' => $result['data']['store_id'] ?? '',
        ));

        return array(
            'success' => true,
            'message' => __('Đăng ký thành công!', 'tgs-pos-sync'),
            'data' => $result['data'],
        );
    }

    /**
     * Unregister (xóa kết nối)
     */
    public static function unregister() {
        TGS_POS_Config::clear_registration();

        return array(
            'success' => true,
            'message' => __('Đã xóa kết nối với Hub', 'tgs-pos-sync'),
        );
    }

    /**
     * AJAX handler - Register
     */
    public static function ajax_register() {
        check_ajax_referer('tgs_pos_qr_register', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $qr_data = isset($_POST['qr_data']) ? stripslashes($_POST['qr_data']) : '';

        if (empty($qr_data)) {
            wp_send_json_error(__('Vui lòng nhập dữ liệu QR Code', 'tgs-pos-sync'));
        }

        $result = self::register_with_qr($qr_data);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX handler - Unregister
     */
    public static function ajax_unregister() {
        check_ajax_referer('tgs_pos_qr_unregister', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $result = self::unregister();

        wp_send_json_success($result);
    }
}

// Register AJAX handlers
add_action('wp_ajax_tgs_pos_qr_register', array('TGS_POS_QR_Scanner', 'ajax_register'));
add_action('wp_ajax_tgs_pos_qr_unregister', array('TGS_POS_QR_Scanner', 'ajax_unregister'));
