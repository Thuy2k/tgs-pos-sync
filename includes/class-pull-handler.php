<?php
/**
 * Pull Handler
 * Pull data từ Hub về Local (based on updated_at/deleted_at)
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Pull_Handler {

    /**
     * Pull local tables từ Hub (ledger, items, meta)
     */
    public static function pull_local_tables() {
        if (!TGS_POS_Config::is_registered()) {
            return array('success' => false, 'message' => 'Not registered with Hub');
        }

        $last_pull = TGS_POS_Config::get('last_pull_local_at');
        $tables = array('wp_local_ledger', 'wp_local_ledger_item', 'wp_local_ledger_meta');

        $result = TGS_POS_HTTP_Client::pull_local($last_pull, $tables);

        if (!$result['success']) {
            return array('success' => false, 'message' => $result['message']);
        }

        $changes = $result['data']['changes'] ?? array();
        $applied = 0;

        // Track max updated_at from pulled data (same pattern as Pull Global)
        $max_updated_at = $last_pull;

        foreach ($changes as $change) {
            $table = $change['table_name'];
            $action = $change['action'];
            $payload = $change['payload'];

            if (self::apply_change($table, $action, $payload)) {
                $applied++;
            }

            // Track max updated_at from this change
            $updated_at = $change['updated_at'] ?? $payload['updated_at'] ?? null;
            if ($updated_at && $updated_at > $max_updated_at) {
                $max_updated_at = $updated_at;
            }
        }

        // Only update watermark if we got data (same as Pull Global logic)
        // If pull returns 0 records, keep old watermark
        if ($max_updated_at && $max_updated_at > $last_pull) {
            TGS_POS_Config::set('last_pull_local_at', $max_updated_at);
        }

        return array(
            'success' => true,
            'pulled' => $applied,
        );
    }

    /**
     * Apply change từ Hub vào local database
     */
    private static function apply_change($table_name, $action, $payload) {
        global $wpdb;

        $table = $wpdb->prefix . str_replace('wp_', '', $table_name);
        $pk = self::get_primary_key($table_name);
        $record_id = $payload[$pk] ?? 0;

        if (!$record_id) {
            return false;
        }

        switch ($action) {
            case 'insert':
            case 'update':
                // For ledger table, check duplicate by PRIMARY KEY (Hub's ID)
                if ($table_name === 'wp_local_ledger' && !empty($payload['local_ledger_id'])) {
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT local_ledger_id FROM {$table} WHERE local_ledger_id = %d",
                        $payload['local_ledger_id']
                    ));

                    if ($exists) {
                        // Update existing record with Hub's data
                        $wpdb->update($table, $payload, array('local_ledger_id' => $payload['local_ledger_id']));
                    } else {
                        // Insert new record with Hub's ID
                        $wpdb->insert($table, $payload);
                    }
                }
                // For item table, check if same ledger_id + product combination exists
                elseif ($table_name === 'wp_local_ledger_item') {
                    $ledger_id = $payload['local_ledger_id'] ?? 0;
                    $product_id = $payload['local_product_name_id'] ?? 0;

                    if ($ledger_id && $product_id) {
                        $exists = $wpdb->get_var($wpdb->prepare(
                            "SELECT local_ledger_item_id FROM {$table}
                             WHERE local_ledger_id = %d AND local_product_name_id = %d",
                            $ledger_id, $product_id
                        ));

                        if ($exists) {
                            // Update existing item
                            $wpdb->update($table, $payload, array(
                                'local_ledger_id' => $ledger_id,
                                'local_product_name_id' => $product_id
                            ));
                        } else {
                            // Insert new item
                            $wpdb->insert($table, $payload);
                        }
                    }
                }
                // For meta table, check by primary key only (1-1 with ledger)
                elseif ($table_name === 'wp_local_ledger_meta') {
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE {$pk} = %d",
                        $record_id
                    ));

                    if ($exists) {
                        $wpdb->update($table, $payload, array($pk => $record_id));
                    } else {
                        $wpdb->insert($table, $payload);
                    }
                }
                // Other tables: check by primary key
                else {
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE {$pk} = %d",
                        $record_id
                    ));

                    if ($exists) {
                        $wpdb->update($table, $payload, array($pk => $record_id));
                    } else {
                        $wpdb->insert($table, $payload);
                    }
                }
                break;

            case 'delete':
                $wpdb->update(
                    $table,
                    array('is_deleted' => 1, 'deleted_at' => current_time('mysql')),
                    array($pk => $record_id)
                );
                break;
        }

        return true;
    }

    /**
     * Get primary key
     */
    private static function get_primary_key($table_name) {
        $map = array(
            'wp_local_ledger' => 'local_ledger_id',
            'wp_local_ledger_item' => 'local_ledger_item_id',
            'wp_local_ledger_meta' => 'local_ledger_meta_id',
        );
        return $map[$table_name] ?? 'id';
    }
}
