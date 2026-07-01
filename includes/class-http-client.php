<?php
/**
 * HTTP Client
 * Gọi REST API lên Hub
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_HTTP_Client {

    /**
     * Register với Hub bằng QR Code token
     */
    public static function register($hub_url, $setup_token) {
        // Use GET instead of POST to bypass REST API restrictions
        $url = trailingslashit($hub_url) . 'wp-json/tgs-hub/v1/auth/register';
        $url = add_query_arg('setup_token', $setup_token, $url);

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false, // For local testing only
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        // Remove BOM if present (fixes json_decode issue)
        $raw_body = wp_remote_retrieve_body($response);
        $raw_body = preg_replace('/^\xEF\xBB\xBF/', '', $raw_body); // Strip UTF-8 BOM

        $body = json_decode($raw_body, true);
        $code = wp_remote_retrieve_response_code($response);

        // Debug log
        error_log('HTTP Client - Response code: ' . $code);
        error_log('HTTP Client - Response body: ' . $raw_body);
        error_log('HTTP Client - Decoded body: ' . print_r($body, true));

        if ($code !== 200) {
            return array('success' => false, 'message' => $body['message'] ?? 'Unknown error', 'code' => $code, 'body' => $body);
        }

        // Response structure: {"success": true, "data": {...}}
        if (!$body || !isset($body['success'], $body['data'])) {
            return array('success' => false, 'message' => 'Invalid response structure');
        }

        return array('success' => $body['success'], 'data' => $body['data']);
    }

    /**
     * Push events lên Hub
     */
    public static function push($events) {
        $hub_url = TGS_POS_Config::get_hub_url();
        $token = TGS_POS_Config::get_client_token();

        if (!$hub_url || !$token) {
            return array('success' => false, 'message' => 'Not registered');
        }

        $url = trailingslashit($hub_url) . 'wp-json/tgs-hub/v1/sync/push';

        $response = wp_remote_post($url, array(
            'body' => json_encode(array('events' => $events)),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            error_log('[TGS POS Sync] Push error: ' . $response->get_error_message());
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        error_log('[TGS POS Sync] Push response code: ' . $code);
        error_log('[TGS POS Sync] Push response body: ' . wp_remote_retrieve_body($response));

        if ($code !== 200) {
            $message = $body['message'] ?? 'Push failed';
            error_log('[TGS POS Sync] Push failed: ' . $message);
            return array('success' => false, 'message' => $message);
        }

        return array('success' => true, 'data' => $body);
    }

    /**
     * Pull changes từ Hub
     */
    public static function pull($since_version = 0) {
        $hub_url = TGS_POS_Config::get_hub_url();
        $token = TGS_POS_Config::get_client_token();

        if (!$hub_url || !$token) {
            return array('success' => false, 'message' => 'Not registered');
        }

        $url = trailingslashit($hub_url) . 'wp-json/tgs-hub/v1/sync/pull';
        $url = add_query_arg('since_version', $since_version, $url);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
            ),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return array('success' => false, 'message' => $body['message'] ?? 'Pull failed');
        }

        return array('success' => true, 'data' => $body);
    }

    /**
     * Gửi ACK về Hub
     */
    public static function ack($synced_event_ids = array(), $applied_change_ids = array()) {
        $hub_url = TGS_POS_Config::get_hub_url();
        $token = TGS_POS_Config::get_client_token();

        if (!$hub_url || !$token) {
            return array('success' => false, 'message' => 'Not registered');
        }

        $url = trailingslashit($hub_url) . 'wp-json/tgs-hub/v1/sync/ack';

        $response = wp_remote_post($url, array(
            'body' => json_encode(array(
                'synced_event_ids' => $synced_event_ids,
                'applied_change_ids' => $applied_change_ids,
            )),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return array('success' => false, 'message' => $body['message'] ?? 'ACK failed');
        }

        return array('success' => true, 'data' => $body);
    }

    /**
     * Heartbeat
     */
    public static function heartbeat() {
        $hub_url = TGS_POS_Config::get_hub_url();
        $token = TGS_POS_Config::get_client_token();

        if (!$hub_url || !$token) {
            return array('success' => false, 'message' => 'Not registered');
        }

        $url = trailingslashit($hub_url) . 'wp-json/tgs-hub/v1/device/heartbeat';

        $response = wp_remote_post($url, array(
            'body' => json_encode(array('status' => 'online')),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        return array('success' => true);
    }

    /**
     * Pull schema từ Hub
     * Hỗ trợ incremental sync với $since timestamp + cursor-based pagination
     *
     * @param string|null $since Timestamp cho incremental sync
     * @param array $cursors Cursors cho từng bảng: ['categories' => 123, 'products' => 456, ...]
     * @return array Response with success/data
     */
    public static function pull_schema($since = null, $cursors = array()) {
        $hub_url = TGS_POS_Config::get_hub_url();
        $token = TGS_POS_Config::get_client_token();
        $blog_id = TGS_POS_Config::get_blog_id();

        if (!$hub_url || !$token || !$blog_id) {
            return array('success' => false, 'message' => 'Not registered');
        }

        $url = trailingslashit($hub_url) . 'wp-json/tgs-hub/v1/sync/pull-schema';

        // Thêm param 'since' nếu có (incremental sync)
        if ($since) {
            $url = add_query_arg('since', $since, $url);
        }

        // Thêm cursors nếu có (pagination)
        if (!empty($cursors['categories'])) {
            $url = add_query_arg('cursor_cat', $cursors['categories'], $url);
        }
        if (!empty($cursors['products'])) {
            $url = add_query_arg('cursor_product', $cursors['products'], $url);
        }
        if (!empty($cursors['policies'])) {
            $url = add_query_arg('cursor_policy', $cursors['policies'], $url);
        }
        if (!empty($cursors['lots'])) {
            $url = add_query_arg('cursor_lot', $cursors['lots'], $url);
        }

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'X-Blog-ID' => $blog_id,
            ),
            'timeout' => 60,
            'sslverify' => false,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        // Remove BOM if present
        $raw_body = wp_remote_retrieve_body($response);
        $raw_body = preg_replace('/^\xEF\xBB\xBF/', '', $raw_body);

        $body = json_decode($raw_body, true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return array('success' => false, 'message' => $body['message'] ?? 'Pull schema failed');
        }

        if (!$body || !isset($body['success'], $body['data'])) {
            return array('success' => false, 'message' => 'Invalid response structure');
        }

        return array('success' => $body['success'], 'data' => $body['data']);
    }
}
