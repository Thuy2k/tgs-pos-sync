<?php
/**
 * Pull Applier
 * Lấy changes từ Hub và apply vào Local database
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Pull_Applier {

    /**
     * Pull changes từ Hub và apply
     */
    public static function pull() {
        // Check if registered
        if (!TGS_POS_Config::is_registered()) {
            return array('success' => false, 'message' => 'Not registered with Hub');
        }

        // Get last pull version
        $last_version = (int) TGS_POS_Config::get('last_pull_version', '0');

        // Pull from Hub
        $result = TGS_POS_HTTP_Client::pull($last_version);

        if (!$result['success']) {
            return array(
                'success' => false,
                'message' => $result['message'],
                'pulled' => 0,
            );
        }

        $changes = $result['data']['changes'] ?? array();
        $latest_version = $result['data']['latest_version'] ?? $last_version;

        if (empty($changes)) {
            return array('success' => true, 'message' => 'No changes to pull', 'pulled' => 0);
        }

        // Save to inbox
        $applied = 0;
        $failed = 0;
        $applied_change_ids = array();

        foreach ($changes as $change) {
            $inbox_result = self::save_to_inbox($change);

            if ($inbox_result) {
                // Apply change
                $apply_result = self::apply_change($change);

                if ($apply_result['success']) {
                    $applied++;
                    $applied_change_ids[] = $change['change_id'];
                } else {
                    $failed++;
                }
            }
        }

        // Send ACK
        if (!empty($applied_change_ids)) {
            TGS_POS_HTTP_Client::ack(array(), $applied_change_ids);
        }

        // Update last pull version
        TGS_POS_Config::set('last_pull_version', $latest_version);
        TGS_POS_Config::set('last_pull_at', current_time('mysql'));

        return array(
            'success' => true,
            'message' => 'Pulled successfully',
            'pulled' => count($changes),
            'applied' => $applied,
            'failed' => $failed,
        );
    }

    /**
     * Save change to inbox
     */
    private static function save_to_inbox($change) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_INBOX;

        return $wpdb->insert(
            $table,
            array(
                'change_id' => $change['change_id'],
                'table_name' => $change['table_name'],
                'operation' => $change['operation'],
                'data' => json_encode($change['data'], JSON_UNESCAPED_UNICODE),
                'version' => $change['version'],
                'status' => 'pending',
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s')
        );
    }

    /**
     * Get primary key field name for a table
     */
    private static function get_primary_key($table_name) {
        $primary_keys = array(
            'wp_global_product_name' => 'global_product_name_id',
            'wp_global_product_cat' => 'global_product_cat_id',
            'wp_global_product_lots' => 'global_product_lot_id',
            'wp_global_selling_policy' => 'selling_policy_id',
            'wp_global_selling_policy_items' => 'sp_item_id',
            'wp_global_purchase_policy' => 'purchase_policy_id',
            'wp_global_purchase_policy_item' => 'pp_item_id',
            'wp_global_supplier' => 'supplier_id',
        );

        return $primary_keys[$table_name] ?? 'id';
    }

    /**
     * Apply change to local database
     */
    private static function apply_change($change) {
        global $wpdb;

        $table_name = $change['table_name'];
        $operation = $change['operation'];
        $data = $change['data'];

        // Only apply global tables (products, policies)
        $allowed_tables = array(
            'wp_global_product_name',
            'wp_global_product_cat',
            'wp_global_product_lots',
            'wp_global_selling_policy',
            'wp_global_selling_policy_items',
            'wp_global_purchase_policy',
            'wp_global_purchase_policy_item',
            'wp_global_supplier',
        );

        if (!in_array($table_name, $allowed_tables)) {
            return array('success' => false, 'message' => 'Table not allowed');
        }

        $table = $wpdb->prefix . str_replace('wp_', '', $table_name);

        // Get primary key for this table
        $primary_key = self::get_primary_key($table_name);

        try {
            switch ($operation) {
                case 'INSERT':
                    $result = $wpdb->insert($table, $data);
                    break;

                case 'UPDATE':
                    // Get ID from primary key field
                    $id = $data[$primary_key] ?? null;
                    if (!$id) {
                        return array('success' => false, 'message' => "Missing {$primary_key} for UPDATE");
                    }
                    unset($data[$primary_key]);
                    $result = $wpdb->update($table, $data, array($primary_key => $id));
                    break;

                case 'DELETE':
                    $id = $data[$primary_key] ?? null;
                    if (!$id) {
                        return array('success' => false, 'message' => "Missing {$primary_key} for DELETE");
                    }
                    $result = $wpdb->delete($table, array($primary_key => $id));
                    break;

                default:
                    return array('success' => false, 'message' => 'Unknown operation');
            }

            if ($result === false) {
                return array('success' => false, 'message' => $wpdb->last_error);
            }

            // Mark as applied in inbox
            self::mark_inbox_applied($change['change_id']);

            return array('success' => true);

        } catch (Exception $e) {
            self::mark_inbox_error($change['change_id'], $e->getMessage());
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Mark inbox item as applied
     */
    private static function mark_inbox_applied($change_id) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_INBOX;

        return $wpdb->update(
            $table,
            array('status' => 'applied', 'applied_at' => current_time('mysql')),
            array('change_id' => $change_id),
            array('%s', '%s'),
            array('%s')
        );
    }

    /**
     * Mark inbox item as error
     */
    private static function mark_inbox_error($change_id, $error_message) {
        global $wpdb;
        $table = $wpdb->prefix . TGS_POS_TABLE_INBOX;

        return $wpdb->update(
            $table,
            array('status' => 'error', 'error_message' => $error_message),
            array('change_id' => $change_id),
            array('%s', '%s'),
            array('%s')
        );
    }

    /**
     * Get inbox stats (deprecated - không dùng inbox nữa)
     */
    public static function get_stats() {
        // Không dùng inbox table nữa
        // Pull trực tiếp UPSERT vào bảng data
        return array(
            'total' => 0,
            'pending' => 0,
            'applied' => 0,
            'errors' => 0,
        );
    }
}
