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
     * Push events lên Hub, sau đó xóa local data và pull về
     */
    public static function push() {
        if (!TGS_POS_Config::is_registered()) {
            return array('success' => false, 'message' => 'Not registered with Hub');
        }

        $events = TGS_POS_Event_Logger::get_pending_events(50);

        if (empty($events)) {
            return array('success' => true, 'message' => 'No events to push', 'pushed' => 0);
        }

        // Transform to API format
        $api_events = array();
        foreach ($events as $event) {
            $data = json_decode($event['data'], true);

            // Extract sale_ledger_id from full payload (new structure)
            $ledger_id = $data['sale_ledger']['local_ledger_id'] ?? 0;

            $api_events[] = array(
                'event_id' => $event['event_id'],
                'transaction_id' => $event['transaction_id'],
                'table_name' => $event['table_name'],
                'record_id' => $ledger_id,
                'action' => strtolower($event['operation']),
                'occurred_at' => $event['created_at'],
                'data_hash' => md5(json_encode($data)),
                'payload' => $data,
            );
        }

        // Push to Hub
        $result = TGS_POS_HTTP_Client::push($api_events);

        if (!$result['success']) {
            error_log('[TGS POS Sync] Push failed: ' . $result['message']);
            return array('success' => false, 'message' => $result['message']);
        }

        $accepted = $result['data']['accepted'] ?? array();
        $rejected = $result['data']['rejected'] ?? array();

        // Mark events as acked
        foreach ($accepted as $event_id) {
            TGS_POS_Event_Logger::mark_as_acked($event_id);
        }

        // Mark rejected as error
        foreach ($rejected as $event_id) {
            TGS_POS_Event_Logger::mark_as_error($event_id, 'Rejected by Hub');
        }

        // Delete local data for accepted events and pull from Hub
        $deleted = 0;
        $pulled = 0;

        if (!empty($accepted)) {
            foreach ($events as $event) {
                if (in_array($event['event_id'], $accepted)) {
                    $data = json_decode($event['data'], true);
                    $ledger_id = $data['sale_ledger']['local_ledger_id'] ?? 0;

                    if ($ledger_id) {
                        self::delete_local_order($ledger_id);
                        $deleted++;
                    }
                }
            }

            // Pull from Hub to sync data
            $pull_result = TGS_POS_Pull_Handler::pull_local_tables();
            $pulled = $pull_result['pulled'] ?? 0;
        }

        TGS_POS_Config::set('last_push_at', current_time('mysql'));

        return array(
            'success' => true,
            'message' => 'Pushed successfully',
            'pushed' => count($api_events),
            'accepted' => count($accepted),
            'rejected' => count($rejected),
            'deleted' => $deleted,
            'pulled' => $pulled,
        );
    }

    /**
     * Delete local order data (all 3 ledgers: SALE + EXPORT + RECEIPT + items + meta)
     */
    private static function delete_local_order($sale_ledger_id) {
        global $wpdb;

        $ledger_table = $wpdb->prefix . 'local_ledger';
        $item_table = $wpdb->prefix . 'local_ledger_item';
        $meta_table = $wpdb->prefix . 'local_ledger_meta';

        // 1. Find all related ledgers (EXPORT and RECEIPT) that are children of SALE
        $child_ledgers = $wpdb->get_results($wpdb->prepare(
            "SELECT local_ledger_id, local_ledger_meta_id FROM {$ledger_table}
             WHERE local_ledger_parent_id = %d",
            $sale_ledger_id
        ), ARRAY_A);

        // 2. Delete items from EXPORT ledgers
        foreach ($child_ledgers as $child) {
            $wpdb->delete($item_table, array('local_ledger_id' => $child['local_ledger_id']), array('%d'));

            // Delete child ledger meta
            if (!empty($child['local_ledger_meta_id'])) {
                $wpdb->delete($meta_table, array('local_ledger_meta_id' => $child['local_ledger_meta_id']), array('%d'));
            }
        }

        // 3. Delete child ledgers (EXPORT + RECEIPT)
        $wpdb->delete($ledger_table, array('local_ledger_parent_id' => $sale_ledger_id), array('%d'));

        // 4. Get SALE meta_id before deleting
        $sale_meta_id = $wpdb->get_var($wpdb->prepare(
            "SELECT local_ledger_meta_id FROM {$ledger_table} WHERE local_ledger_id = %d",
            $sale_ledger_id
        ));

        // 5. Delete SALE ledger meta
        if ($sale_meta_id) {
            $wpdb->delete($meta_table, array('local_ledger_meta_id' => $sale_meta_id), array('%d'));
        }

        // 6. Delete SALE ledger
        $wpdb->delete($ledger_table, array('local_ledger_id' => $sale_ledger_id), array('%d'));
    }
}
