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

        // Transform to API format
        $api_events = array();
        foreach ($events as $event) {
            $api_events[] = array(
                'event_id' => $event['event_id'],
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
            foreach ($events as $event) {
                TGS_POS_Event_Logger::mark_as_error($event['event_id'], $result['message']);
            }

            return array(
                'success' => false,
                'message' => $result['message'],
                'pushed' => 0,
                'failed' => count($events),
            );
        }

        // Mark all as sent
        $event_ids = array();
        foreach ($events as $event) {
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
            'pushed' => count($events),
            'applied' => $result['data']['applied'] ?? 0,
            'failed' => $result['data']['failed'] ?? 0,
        );
    }
}
