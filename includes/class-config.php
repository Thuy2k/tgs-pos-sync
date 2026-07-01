<?php
/**
 * Config Manager
 * Quản lý cấu hình sync (hub_url, client_token, store_id)
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Config {

    /**
     * Get config value
     */
    public static function get($key, $default = '') {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_STATE;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT state_value FROM {$table} WHERE state_key = %s",
            $key
        ));

        return $value !== null ? $value : $default;
    }

    /**
     * Set config value
     */
    public static function set($key, $value) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_STATE;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE state_key = %s",
            $key
        ));

        if ($exists) {
            return $wpdb->update(
                $table,
                array('state_value' => $value),
                array('state_key' => $key),
                array('%s'),
                array('%s')
            );
        } else {
            return $wpdb->insert(
                $table,
                array('state_key' => $key, 'state_value' => $value),
                array('%s', '%s')
            );
        }
    }

    /**
     * Check if registered with Hub
     */
    public static function is_registered() {
        return (bool) self::get('is_registered', '0');
    }

    /**
     * Get Hub URL
     */
    public static function get_hub_url() {
        return self::get('hub_url');
    }

    /**
     * Get client token
     */
    public static function get_client_token() {
        return self::get('client_token');
    }

    /**
     * Get store ID
     */
    public static function get_store_id() {
        return self::get('store_id');
    }

    /**
     * Get blog ID
     */
    public static function get_blog_id() {
        return self::get('blog_id');
    }

    /**
     * Save registration data
     */
    public static function save_registration($data) {
        self::set('hub_url', $data['hub_url']);
        self::set('client_token', $data['client_token']);
        self::set('blog_id', $data['blog_id']);
        self::set('store_id', $data['store_id']);
        self::set('is_registered', '1');
    }

    /**
     * Clear registration
     */
    public static function clear_registration() {
        self::set('hub_url', '');
        self::set('client_token', '');
        self::set('blog_id', '');
        self::set('store_id', '');
        self::set('is_registered', '0');
    }
}
