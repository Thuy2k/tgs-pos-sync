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

        foreach ($changes as $change) {
            $table = $change['table_name'];
            $action = $change['action'];
            $payload = $change['payload'];

            if (self::apply_change($table, $action, $payload)) {
                $applied++;
            }
        }

        TGS_POS_Config::set('last_pull_local_at', current_time('mysql'));

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
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE {$pk} = %d",
                    $record_id
                ));

                if ($exists) {
                    $wpdb->update($table, $payload, array($pk => $record_id));
                } else {
                    $wpdb->insert($table, $payload);
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
