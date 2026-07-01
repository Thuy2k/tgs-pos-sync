<?php
/**
 * Order Sync Listener
 * Bắt hook 'tgs_after_order_create' và log vào Outbox với payload đầy đủ
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Order_Sync_Listener {

    /**
     * Register hooks
     */
    public static function init() {
        add_action('tgs_after_order_create', array(__CLASS__, 'on_order_created'), 10, 4);
    }

    /**
     * Handle order created event
     * Query lại full data của 3 ledgers + items + meta rồi log vào Outbox
     *
     * @param int $sale_ledger_id SALE ledger ID
     * @param array $order_data Basic order info
     * @param array $products_data Products info
     * @param array $metas Additional meta
     */
    public static function on_order_created($sale_ledger_id, $order_data, $products_data, $metas) {
        global $wpdb;

        // 1. Query SALE ledger + meta
        $sale_ledger = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}local_ledger WHERE local_ledger_id = %d",
            $sale_ledger_id
        ), ARRAY_A);

        if (!$sale_ledger) {
            error_log('[TGS Order Sync] SALE ledger not found: ' . $sale_ledger_id);
            return;
        }

        // Query SALE meta
        $sale_meta = array();
        if (!empty($sale_ledger['local_ledger_meta_id'])) {
            $meta_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}local_ledger_meta WHERE local_ledger_meta_id = %d",
                $sale_ledger['local_ledger_meta_id']
            ), ARRAY_A);

            if ($meta_row && !empty($meta_row['local_ledger_meta_value'])) {
                $sale_meta = json_decode($meta_row['local_ledger_meta_value'], true) ?: array();
            }
        }

        // 2. Query EXPORT ledger + items
        $export_ledger_id = $order_data['export_ledger_id'] ?? 0;
        $export_ledger = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}local_ledger WHERE local_ledger_id = %d",
            $export_ledger_id
        ), ARRAY_A);

        $export_items = array();
        if ($export_ledger_id) {
            $export_items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}local_ledger_item WHERE local_ledger_id = %d",
                $export_ledger_id
            ), ARRAY_A);
        }

        // 3. Query RECEIPT ledgers + meta
        $receipt_ids = $order_data['receipt_ids'] ?? array();
        $receipt_ledgers = array();

        foreach ($receipt_ids as $receipt_id) {
            $receipt = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}local_ledger WHERE local_ledger_id = %d",
                $receipt_id
            ), ARRAY_A);

            if ($receipt) {
                // Query RECEIPT meta
                $receipt_meta = array();
                if (!empty($receipt['local_ledger_meta_id'])) {
                    $meta_row = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}local_ledger_meta WHERE local_ledger_meta_id = %d",
                        $receipt['local_ledger_meta_id']
                    ), ARRAY_A);

                    if ($meta_row && !empty($meta_row['local_ledger_meta_value'])) {
                        $receipt_meta = json_decode($meta_row['local_ledger_meta_value'], true) ?: array();
                    }
                }

                // Attach meta vào receipt
                $receipt['meta'] = $receipt_meta;
                $receipt_ledgers[] = $receipt;
            }
        }

        // 4. Build payload
        $payload = array(
            'sale_ledger' => $sale_ledger,
            'sale_meta' => $sale_meta,
            'export_ledger' => $export_ledger ?: array(),
            'export_items' => $export_items,
            'receipt_ledgers' => $receipt_ledgers,
        );

        // 5. Log vào Outbox
        $event_id = TGS_POS_Event_Logger::log_order_transaction($payload);

        if ($event_id) {
            error_log('[TGS Order Sync] Logged order to Outbox: ' . $event_id . ' (SALE: ' . $sale_ledger_id . ')');
        } else {
            error_log('[TGS Order Sync] Failed to log order: SALE ' . $sale_ledger_id);
        }
    }
}
