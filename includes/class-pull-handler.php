<?php
/**
 * Pull Handler
 * Pull local tables từ Hub về trước khi push (conflict prevention)
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Pull_Handler {

    /**
     * Pull local tables từ Hub về
     * Chỉ pull các bảng LOCAL mà POS sẽ push (để detect conflict)
     *
     * @return array ['success' => bool, 'pulled' => int, 'conflicts_resolved' => int]
     */
    public static function pull_local_tables() {
        global $wpdb;

        if (!TGS_POS_Config::is_registered()) {
            return array('success' => false, 'message' => 'Not registered');
        }

        $since_version = get_option('tgs_pos_local_pull_version', 0);

        // Pull từ Hub
        $result = TGS_POS_HTTP_Client::pull($since_version);

        if (!$result['success']) {
            return array('success' => false, 'message' => $result['message']);
        }

        $changes = $result['data']['changes'] ?? array();
        $new_version = $result['data']['version'] ?? $since_version;

        $pulled = 0;
        $conflicts_resolved = 0;

        // Chỉ xử lý các bảng LOCAL (ledger, ledger_item, ledger_meta)
        $local_tables = array('wp_local_ledger', 'wp_local_ledger_item', 'wp_local_ledger_meta');

        foreach ($changes as $change) {
            $table_name = $change['table_name'] ?? '';

            // Skip nếu không phải bảng LOCAL
            if (!in_array($table_name, $local_tables)) {
                continue;
            }

            $action = $change['action'] ?? '';
            $data = $change['data'] ?? array();

            // Xử lý INSERT từ Hub
            if ($action === 'insert') {
                $result = self::handle_insert_with_conflict_check($table_name, $data);
                if ($result['applied']) {
                    $pulled++;
                }
                if ($result['conflict_resolved']) {
                    $conflicts_resolved++;
                }
            }
        }

        // Update version
        update_option('tgs_pos_local_pull_version', $new_version);

        return array(
            'success' => true,
            'pulled' => $pulled,
            'conflicts_resolved' => $conflicts_resolved,
        );
    }

    /**
     * Handle INSERT với conflict check
     * Nếu khóa chính đã tồn tại → di chuyển local record xuống cuối bảng (auto-increment mới)
     * Update tất cả foreign keys trong bảng liên quan
     *
     * @return array ['applied' => bool, 'conflict_resolved' => bool]
     */
    private static function handle_insert_with_conflict_check($table_name, $data) {
        global $wpdb;

        $table = $wpdb->prefix . str_replace('wp_', '', $table_name);
        $pk = self::get_primary_key($table_name);
        $hub_id = $data[$pk] ?? null;

        if (!$hub_id) {
            return array('applied' => false, 'conflict_resolved' => false);
        }

        // Check nếu ID đã tồn tại local
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$pk} = %d",
            $hub_id
        ));

        if (!$exists) {
            // Không conflict → insert trực tiếp
            $wpdb->insert($table, $data);
            return array('applied' => true, 'conflict_resolved' => false);
        }

        // CONFLICT DETECTED!
        // Ưu tiên dữ liệu từ Hub (mới hơn), di chuyển local record xuống cuối bảng

        error_log("[TGS POS Pull] Conflict detected: {$table_name} ID={$hub_id} - Reassigning local record to new ID");

        // Step 1: Đọc local record hiện tại
        $local_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$pk} = %d",
            $hub_id
        ), ARRAY_A);

        // Step 2: Xóa tạm local record
        $wpdb->delete($table, array($pk => $hub_id));

        // Step 3: Insert local record lại KHÔNG set primary key → auto-increment sẽ tạo ID mới
        unset($local_record[$pk]); // Remove primary key để trigger auto-increment
        $wpdb->insert($table, $local_record);
        $new_local_id = $wpdb->insert_id;

        // Step 4: Update foreign keys trong các bảng liên quan
        self::update_foreign_keys($table_name, $hub_id, $new_local_id);

        // Step 5: Update outbox events với ID mới
        self::update_outbox_events($table_name, $hub_id, $new_local_id);

        // Step 6: Insert Hub record vào đúng ID ban đầu
        $wpdb->insert($table, $data);

        error_log("[TGS POS Pull] Conflict resolved: {$table_name} Local record moved from ID={$hub_id} to ID={$new_local_id}, Hub record inserted at ID={$hub_id}");

        return array('applied' => true, 'conflict_resolved' => true);
    }

    /**
     * Update foreign keys trong các bảng liên quan khi reassign ID
     */
    private static function update_foreign_keys($table_name, $old_id, $new_id) {
        global $wpdb;

        // Map bảng cha → bảng con
        $relationships = array(
            'wp_local_ledger' => array(
                // Bảng con có foreign key trỏ đến local_ledger
                array('table' => 'local_ledger_item', 'fk' => 'local_ledger_id'),
                array('table' => 'local_ledger_meta', 'fk' => 'local_ledger_id'),
            ),
        );

        if (!isset($relationships[$table_name])) {
            return; // Không có bảng con
        }

        foreach ($relationships[$table_name] as $relation) {
            $child_table = $wpdb->prefix . $relation['table'];
            $fk_column = $relation['fk'];

            // Update foreign key
            $updated = $wpdb->update(
                $child_table,
                array($fk_column => $new_id),
                array($fk_column => $old_id)
            );

            if ($updated !== false && $updated > 0) {
                error_log("[TGS POS Pull] Updated {$updated} rows in {$child_table}: {$fk_column} from {$old_id} to {$new_id}");
            }
        }
    }

    /**
     * Update outbox events với ID mới sau khi reassign
     */
    private static function update_outbox_events($table_name, $old_id, $new_id) {
        global $wpdb;
        $outbox = $wpdb->prefix . TGS_POS_TABLE_OUTBOX;
        $pk = self::get_primary_key($table_name);

        // Update JSON data field trong outbox
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT id, data FROM {$outbox} WHERE table_name = %s AND status = 'pending'",
            $table_name
        ), ARRAY_A);

        foreach ($events as $event) {
            $data = json_decode($event['data'], true);
            if (isset($data[$pk]) && $data[$pk] == $old_id) {
                $data[$pk] = $new_id;
                $wpdb->update(
                    $outbox,
                    array('data' => json_encode($data, JSON_UNESCAPED_UNICODE)),
                    array('id' => $event['id'])
                );
            }
        }
    }

    /**
     * Get primary key của bảng
     */
    private static function get_primary_key($table_name) {
        $map = array(
            'wp_local_ledger' => 'local_ledger_id',
            'wp_local_ledger_item' => 'local_ledger_item_id',
            'wp_local_ledger_meta' => 'local_ledger_meta_id',
            'wp_local_ledger_person' => 'local_ledger_person_id',
        );
        return $map[$table_name] ?? 'id';
    }
}
