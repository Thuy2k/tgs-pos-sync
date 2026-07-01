<?php
/**
 * Local Database Schema
 * Tạo 4 bảng sync: outbox, inbox, state, id_map
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Database {

    /**
     * Create sync tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // 1. Outbox - Local→Hub (chờ đẩy lên)
        $table_outbox = $wpdb->prefix . TGS_POS_TABLE_OUTBOX;
        $sql_outbox = "CREATE TABLE IF NOT EXISTS {$table_outbox} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id varchar(64) NOT NULL,
            event_type varchar(50) NOT NULL,
            table_name varchar(100) NOT NULL,
            operation enum('INSERT','UPDATE','DELETE') NOT NULL,
            data longtext NOT NULL,
            status enum('pending','sent','acked','error') DEFAULT 'pending',
            retry_count int DEFAULT 0,
            error_message text NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            sent_at datetime NULL,
            acked_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_id (event_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        // 2. Inbox - Hub→Local (đã lấy về)
        $table_inbox = $wpdb->prefix . TGS_POS_TABLE_INBOX;
        $sql_inbox = "CREATE TABLE IF NOT EXISTS {$table_inbox} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            change_id varchar(64) NOT NULL,
            table_name varchar(100) NOT NULL,
            operation enum('INSERT','UPDATE','DELETE') NOT NULL,
            data longtext NOT NULL,
            version bigint(20) NOT NULL,
            status enum('pending','applied','error') DEFAULT 'pending',
            error_message text NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            applied_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY change_id (change_id),
            KEY status (status),
            KEY version (version)
        ) $charset_collate;";

        // 3. Sync State - Trạng thái sync
        $table_state = $wpdb->prefix . TGS_POS_TABLE_STATE;
        $sql_state = "CREATE TABLE IF NOT EXISTS {$table_state} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            state_key varchar(100) NOT NULL,
            state_value longtext NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY state_key (state_key)
        ) $charset_collate;";

        // 4. ID Map - Map Local ID ↔ Hub ID
        $table_id_map = $wpdb->prefix . TGS_POS_TABLE_ID_MAP;
        $sql_id_map = "CREATE TABLE IF NOT EXISTS {$table_id_map} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            table_name varchar(100) NOT NULL,
            local_id varchar(100) NOT NULL,
            hub_id varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY local_mapping (table_name, local_id),
            KEY hub_id (hub_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_outbox);
        dbDelta($sql_inbox);
        dbDelta($sql_state);
        dbDelta($sql_id_map);

        // Insert default state
        self::init_default_state();
    }

    /**
     * Initialize default sync state
     */
    private static function init_default_state() {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_STATE;

        $defaults = array(
            array('state_key' => 'last_push_at', 'state_value' => ''),
            array('state_key' => 'last_pull_at', 'state_value' => ''),
            array('state_key' => 'last_pull_version', 'state_value' => '0'),
            array('state_key' => 'hub_url', 'state_value' => ''),
            array('state_key' => 'client_token', 'state_value' => ''),
            array('state_key' => 'blog_id', 'state_value' => ''),
            array('state_key' => 'store_id', 'state_value' => ''),
            array('state_key' => 'is_registered', 'state_value' => '0'),
        );

        foreach ($defaults as $state) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE state_key = %s",
                $state['state_key']
            ));

            if (!$exists) {
                $wpdb->insert($table, $state);
            }
        }
    }
}
