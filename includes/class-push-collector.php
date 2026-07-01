<?php
/**
 * Push Collector
 * Thu thập events từ outbox và đẩy lên Hub
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Push_Collector {

    /**
     * Collect và push events lên Hub
     * Hỗ trợ transaction atomicity - chỉ push khi có đủ events trong transaction
     */
    public static function push() {
        // Check if registered
        if (!TGS_POS_Config::is_registered()) {
            return array('success' => false, 'message' => 'Not registered with Hub');
        }

        // Get pending events
        $events = TGS_POS_Event_Logger::get_pending_events(50);

        if (empty($events)) {
            return array('success' => true, 'message' => 'No events to push', 'pushed' => 0);
        }

        // Group events by transaction_id
        $grouped = self::group_by_transaction($events);

        // Chỉ push transactions hoàn chỉnh
        $ready_events = array();
        foreach ($grouped as $txn_id => $txn_events) {
            if (self::is_transaction_complete($txn_events)) {
                $ready_events = array_merge($ready_events, $txn_events);
            }
        }

        if (empty($ready_events)) {
            return array('success' => true, 'message' => 'No complete transactions to push', 'pushed' => 0, 'waiting' => count($events));
        }

        // Transform to API format
        $api_events = array();
        foreach ($ready_events as $event) {
            $api_events[] = array(
                'event_id' => $event['event_id'],
                'transaction_id' => $event['transaction_id'],
                'parent_event_id' => $event['parent_event_id'],
                'event_type' => $event['event_type'],
                'table_name' => $event['table_name'],
                'operation' => $event['operation'],
                'data' => json_decode($event['data'], true),
                'timestamp' => $event['created_at'],
            );
        }

        // Push to Hub
        $result = TGS_POS_HTTP_Client::push($api_events);

        if (!$result['success']) {
            // Mark all as error
            foreach ($ready_events as $event) {
                TGS_POS_Event_Logger::mark_as_error($event['event_id'], $result['message']);
            }

            return array(
                'success' => false,
                'message' => $result['message'],
                'pushed' => 0,
                'failed' => count($ready_events),
            );
        }

        // Mark all as sent
        $event_ids = array();
        foreach ($ready_events as $event) {
            TGS_POS_Event_Logger::mark_as_sent($event['event_id']);
            $event_ids[] = $event['event_id'];
        }

        // Send ACK
        TGS_POS_HTTP_Client::ack($event_ids, array());

        // Mark all as acked
        foreach ($event_ids as $event_id) {
            TGS_POS_Event_Logger::mark_as_acked($event_id);
        }

        // Update last push time
        TGS_POS_Config::set('last_push_at', current_time('mysql'));

        return array(
            'success' => true,
            'message' => 'Pushed successfully',
            'pushed' => count($ready_events),
            'waiting' => count($events) - count($ready_events),
            'applied' => $result['data']['applied'] ?? 0,
            'failed' => $result['data']['failed'] ?? 0,
        );
    }

    /**
     * Group events by transaction_id
     */
    private static function group_by_transaction($events) {
        $grouped = array();

        foreach ($events as $event) {
            $txn_id = $event['transaction_id'] ?? 'single_' . $event['event_id'];
            if (!isset($grouped[$txn_id])) {
                $grouped[$txn_id] = array();
            }
            $grouped[$txn_id][] = $event;
        }

        return $grouped;
    }

    /**
     * Check nếu transaction đã complete (có đủ parent + children)
     */
    private static function is_transaction_complete($txn_events) {
        // Single event (không có transaction_id) → complete
        if (count($txn_events) === 1 && empty($txn_events[0]['transaction_id'])) {
            return true;
        }

        // Transaction events → check có parent không
        $has_parent = false;
        foreach ($txn_events as $event) {
            if (empty($event['parent_event_id'])) {
                $has_parent = true;
                break;
            }
        }

        // Nếu có parent → complete (giả định đã log đủ)
        // Logic phức tạp hơn: check expected_count từ parent metadata
        return $has_parent;
    }
}
