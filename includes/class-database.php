<?php
/**
 * Local Database Schema
 * Tạo bảng sync: outbox (queue events push lên Hub)
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

        // Outbox - Local→Hub (chờ đẩy lên)
        $table_outbox = $wpdb->prefix . TGS_POS_TABLE_OUTBOX;
        $sql_outbox = "CREATE TABLE IF NOT EXISTS {$table_outbox} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id varchar(64) NOT NULL,
            transaction_id varchar(64) DEFAULT NULL COMMENT 'Group events trong cùng 1 transaction',
            parent_event_id varchar(64) DEFAULT NULL COMMENT 'Parent event (for child events)',
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
            KEY created_at (created_at),
            KEY transaction_id (transaction_id),
            KEY parent_event_id (parent_event_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_outbox);
    }
}
