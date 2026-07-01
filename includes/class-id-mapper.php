<?php
/**
 * ID Mapper
 * Map Local ID ↔ Hub ID
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_ID_Mapper {

    /**
     * Save ID mapping
     */
    public static function save_mapping($table_name, $local_id, $hub_id) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_ID_MAP;

        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE table_name = %s AND local_id = %s",
            $table_name,
            $local_id
        ));

        if ($exists) {
            return $wpdb->update(
                $table,
                array('hub_id' => $hub_id),
                array('table_name' => $table_name, 'local_id' => $local_id),
                array('%s'),
                array('%s', '%s')
            );
        } else {
            return $wpdb->insert(
                $table,
                array(
                    'table_name' => $table_name,
                    'local_id' => $local_id,
                    'hub_id' => $hub_id,
                ),
                array('%s', '%s', '%s')
            );
        }
    }

    /**
     * Get Hub ID from Local ID
     */
    public static function get_hub_id($table_name, $local_id) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_ID_MAP;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT hub_id FROM {$table} WHERE table_name = %s AND local_id = %s",
            $table_name,
            $local_id
        ));
    }

    /**
     * Get Local ID from Hub ID
     */
    public static function get_local_id($table_name, $hub_id) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_ID_MAP;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT local_id FROM {$table} WHERE table_name = %s AND hub_id = %s",
            $table_name,
            $hub_id
        ));
    }

    /**
     * Check if Local ID has Hub mapping
     */
    public static function has_hub_id($table_name, $local_id) {
        return !is_null(self::get_hub_id($table_name, $local_id));
    }
}
