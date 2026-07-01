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
            event_id varchar(64) NOT NULL COMMENT 'Unique event ID',
            transaction_id varchar(64) DEFAULT NULL COMMENT '[NEW v1.0.0] Group events trong cùng 1 transaction (vd: txn_order_123)',
            parent_event_id varchar(64) DEFAULT NULL COMMENT '[NEW v1.0.0] Parent event ID cho child events (atomic transaction)',
            event_type varchar(50) NOT NULL COMMENT 'Loại event: order_created, order_item_created, order_meta_created',
            table_name varchar(100) NOT NULL COMMENT 'Tên bảng: wp_local_ledger, wp_local_ledger_item, wp_local_ledger_meta',
            operation enum('INSERT','UPDATE','DELETE') NOT NULL COMMENT 'Hành động',
            data longtext NOT NULL COMMENT 'Dữ liệu JSON của event',
            status enum('pending','sent','acked','error') DEFAULT 'pending' COMMENT 'Trạng thái sync',
            retry_count int DEFAULT 0 COMMENT 'Số lần retry nếu lỗi',
            error_message text NULL COMMENT 'Thông báo lỗi nếu có',
            created_at datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời gian tạo event',
            sent_at datetime NULL COMMENT 'Thời gian push lên Hub',
            acked_at datetime NULL COMMENT 'Thời gian Hub xác nhận nhận được',
            PRIMARY KEY (id),
            UNIQUE KEY event_id (event_id),
            KEY status (status),
            KEY created_at (created_at),
            KEY transaction_id (transaction_id) COMMENT '[NEW v1.0.0] Index cho transaction_id',
            KEY parent_event_id (parent_event_id) COMMENT '[NEW v1.0.0] Index cho parent_event_id'
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_outbox);

        // Kiểm tra và thêm cột thiếu nếu cần (cho bản cũ)
        self::check_and_add_missing_columns($table_outbox);
    }

    /**
     * Check và thêm các cột thiếu vào bảng outbox (migration tự động)
     *
     * Chạy mỗi lần plugin load để đảm bảo bản cũ được update tự động
     * Không cần deactivate/activate plugin thủ công
     *
     * @since 1.0.0
     */
    private static function check_and_add_missing_columns($table) {
        global $wpdb;

        // Get existing columns
        $existing_columns = $wpdb->get_col("DESCRIBE {$table}", 0);

        // [NEW v1.0.0] Check transaction_id - để group các events của cùng 1 đơn hàng
        if (!in_array('transaction_id', $existing_columns)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN transaction_id varchar(64) DEFAULT NULL COMMENT '[NEW v1.0.0] Group events trong cùng 1 transaction' AFTER event_id");
            $wpdb->query("ALTER TABLE {$table} ADD KEY transaction_id (transaction_id)");
        }

        // [NEW v1.0.0] Check parent_event_id - để đánh dấu parent/child relationship (atomic transaction)
        if (!in_array('parent_event_id', $existing_columns)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN parent_event_id varchar(64) DEFAULT NULL COMMENT '[NEW v1.0.0] Parent event cho atomic transaction' AFTER transaction_id");
            $wpdb->query("ALTER TABLE {$table} ADD KEY parent_event_id (parent_event_id)");
        }
    }
}
