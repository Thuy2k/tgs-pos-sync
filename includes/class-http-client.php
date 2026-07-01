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
        $url = trailingslashit($hub_url) . 'wp-json/tgs-hub/v1/auth/register';

        $response = wp_remote_post($url, array(
            'body' => json_encode(array('setup_token' => $setup_token)),
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30,
            'sslverify' => false, // For local testing only
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return array('success' => false, 'message' => $body['message'] ?? 'Unknown error', 'code' => $code, 'body' => $body);
        }

        return array('success' => true, 'data' => $body['data']);
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
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return array('success' => false, 'message' => $body['message'] ?? 'Push failed');
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
}
