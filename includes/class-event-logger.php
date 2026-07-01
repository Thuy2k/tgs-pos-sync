<?php
/**
 * Event Logger
 * Ghi events vào Outbox khi có thay đổi database
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Event_Logger {

    /**
     * Log event vào outbox
     */
    public static function log_event($event_type, $table_name, $operation, $data) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_OUTBOX;

        $event_id = self::generate_event_id();

        $result = $wpdb->insert(
            $table,
            array(
                'event_id' => $event_id,
                'event_type' => $event_type,
                'table_name' => $table_name,
                'operation' => $operation,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $result ? $event_id : false;
    }

    /**
     * Log order created
     */
    public static function log_order_created($order_data) {
        return self::log_event(
            'order_created',
            'wp_local_ledger',
            'INSERT',
            $order_data
        );
    }

    /**
     * Log customer created
     */
    public static function log_customer_created($customer_data) {
        return self::log_event(
            'customer_created',
            'wp_local_ledger_person',
            'INSERT',
            $customer_data
        );
    }

    /**
     * Generate unique event ID
     */
    private static function generate_event_id() {
        return 'evt_' . time() . '_' . wp_generate_password(12, false);
    }

    /**
     * Get pending events (chờ đẩy lên Hub)
     */
    public static function get_pending_events($limit = 100) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_OUTBOX;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d",
            $limit
        ), ARRAY_A);

        return $results ?: array();
    }

    /**
     * Mark event as sent
     */
    public static function mark_as_sent($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_OUTBOX;

        return $wpdb->update(
            $table,
            array('status' => 'sent', 'sent_at' => current_time('mysql')),
            array('event_id' => $event_id),
            array('%s', '%s'),
            array('%s')
        );
    }

    /**
     * Mark event as acked
     */
    public static function mark_as_acked($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_OUTBOX;

        return $wpdb->update(
            $table,
            array('status' => 'acked', 'acked_at' => current_time('mysql')),
            array('event_id' => $event_id),
            array('%s', '%s'),
            array('%s')
        );
    }

    /**
     * Mark event as error
     */
    public static function mark_as_error($event_id, $error_message) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_OUTBOX;

        return $wpdb->update(
            $table,
            array(
                'status' => 'error',
                'error_message' => $error_message,
                'retry_count' => new \stdClass(), // triggers retry_count = retry_count + 1
            ),
            array('event_id' => $event_id),
            array('%s', '%s'),
            array('%s')
        );
    }

    /**
     * Get outbox stats
     */
    public static function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_OUTBOX;

        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'acked' THEN 1 ELSE 0 END) as acked,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors
             FROM {$table}",
            ARRAY_A
        );

        return $stats;
    }
}
