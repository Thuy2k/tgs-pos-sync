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
    public static function log_event($event_type, $table_name, $operation, $data, $transaction_id = null, $parent_event_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_OUTBOX;

        $event_id = self::generate_event_id();

        $result = $wpdb->insert(
            $table,
            array(
                'event_id' => $event_id,
                'transaction_id' => $transaction_id,
                'parent_event_id' => $parent_event_id,
                'event_type' => $event_type,
                'table_name' => $table_name,
                'operation' => $operation,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $result ? $event_id : false;
    }

    /**
     * Log transaction - Batch events trong cùng 1 transaction (đơn hàng)
     * Đảm bảo ledger + items + meta được push cùng nhau (all-or-nothing)
     *
     * @param array $events Array of ['table_name' => '', 'operation' => '', 'data' => []]
     * @param string $transaction_id Unique transaction ID (e.g., 'txn_ledger_123')
     * @return array ['transaction_id' => '...', 'event_ids' => [...]]
     */
    public static function log_transaction($events, $transaction_id = null) {
        global $wpdb;

        if (empty($events) || !is_array($events)) {
            return false;
        }

        // Generate transaction_id nếu không có
        if (!$transaction_id) {
            $transaction_id = 'txn_' . time() . '_' . wp_generate_password(8, false);
        }

        $event_ids = array();
        $parent_event_id = null;

        foreach ($events as $index => $event) {
            $table_name = $event['table_name'] ?? '';
            $operation = $event['operation'] ?? 'INSERT';
            $data = $event['data'] ?? array();
            $event_type = $event['event_type'] ?? 'transaction_event';

            // Event đầu tiên là parent
            if ($index === 0) {
                $event_id = self::log_event($event_type, $table_name, $operation, $data, $transaction_id, null);
                $parent_event_id = $event_id;
            } else {
                // Các event sau là children của parent
                $event_id = self::log_event($event_type, $table_name, $operation, $data, $transaction_id, $parent_event_id);
            }

            if ($event_id) {
                $event_ids[] = $event_id;
            }
        }

        return array(
            'success' => count($event_ids) === count($events),
            'transaction_id' => $transaction_id,
            'event_ids' => $event_ids,
            'total_events' => count($events),
        );
    }

    /**
     * Log order created (legacy - single event)
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
     * Log order with items và meta - Atomic transaction
     *
     * @param array $ledger_data Dữ liệu bảng ledger
     * @param array $items Array of items data
     * @param array $metas Array of meta data
     * @return array Transaction info
     */
    public static function log_order_transaction($ledger_data, $items = array(), $metas = array()) {
        $events = array();

        // Event 1: Parent - Ledger
        $events[] = array(
            'event_type' => 'order_created',
            'table_name' => 'wp_local_ledger',
            'operation' => 'INSERT',
            'data' => $ledger_data,
        );

        // Event 2-N: Children - Items
        foreach ($items as $item) {
            $events[] = array(
                'event_type' => 'order_item_created',
                'table_name' => 'wp_local_ledger_item',
                'operation' => 'INSERT',
                'data' => $item,
            );
        }

        // Event N+1-M: Children - Metas
        foreach ($metas as $meta) {
            $events[] = array(
                'event_type' => 'order_meta_created',
                'table_name' => 'wp_local_ledger_meta',
                'operation' => 'INSERT',
                'data' => $meta,
            );
        }

        $ledger_id = $ledger_data['local_ledger_id'] ?? time();
        $transaction_id = 'txn_order_' . $ledger_id;

        return self::log_transaction($events, $transaction_id);
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
