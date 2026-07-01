<?php
/**
 * Schema Manager
 * Xử lý pull schema từ Hub và tạo bảng local + insert dữ liệu global
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Schema_Manager {

    /**
     * Pull schema từ Hub và áp dụng
     * Hỗ trợ incremental sync + cursor-based pagination
     */
    public static function pull_and_apply() {
        // 1. Lấy timestamp lần pull cuối (incremental sync)
        $last_pull = get_option('tgs_pos_last_pull_global_data_at', null);

        // 2. Lấy cursors từ lần pull trước (pagination)
        $cursors = get_option('tgs_pos_pull_cursors', array(
            'categories' => PHP_INT_MAX,
            'products' => PHP_INT_MAX,
            'policies' => PHP_INT_MAX,
            'lots' => PHP_INT_MAX,
        ));

        // 3. Pull batches cho đến khi hết data
        $total_summary = array(
            'categories' => array('inserted' => 0, 'updated' => 0, 'deleted' => 0),
            'products' => array('inserted' => 0, 'updated' => 0, 'deleted' => 0),
            'policies' => array('inserted' => 0, 'updated' => 0, 'deleted' => 0),
            'lots' => array('inserted' => 0, 'updated' => 0, 'deleted' => 0),
        );

        $batch_count = 0;
        $has_more = true;
        $execute_result = array(
            'global' => array('created' => array(), 'failed' => array()),
            'local' => array('created' => array(), 'failed' => array()),
        );

        while ($has_more) {
            $batch_count++;

            // Pull một batch từ Hub
            $result = TGS_POS_HTTP_Client::pull_schema($last_pull, $cursors);

            if (!$result['success']) {
                return $result;
            }

            $schema_data = $result['data'];

            // 4. Execute SQL statements từ Hub (chỉ chạy lần đầu nếu có tables mới)
            if ($batch_count === 1 && !empty($schema_data['sql_statements'])) {
                $execute_result = TGS_POS_Database_Schema::execute_sql_statements($schema_data['sql_statements']);
            }

            // 5. UPSERT dữ liệu GLOBAL batch này
            $upsert_result = self::upsert_global_data($schema_data['global_data']);
            if (!$upsert_result['success']) {
                return $upsert_result;
            }

            // 6. Cộng dồn summary
            foreach ($total_summary as $key => $counts) {
                foreach ($counts as $action => $count) {
                    $total_summary[$key][$action] += $upsert_result['summary'][$key][$action];
                }
            }

            // 7. Update cursors cho lần gọi tiếp theo
            $has_more = $schema_data['global_data']['summary']['has_more'] ?? false;

            if ($has_more) {
                // Lưu cursors mới
                $cursors = array(
                    'categories' => $schema_data['global_data']['cursor_cat_next'] ?? PHP_INT_MAX,
                    'products' => $schema_data['global_data']['cursor_product_next'] ?? PHP_INT_MAX,
                    'policies' => $schema_data['global_data']['cursor_policy_next'] ?? PHP_INT_MAX,
                    'lots' => $schema_data['global_data']['cursor_lot_next'] ?? PHP_INT_MAX,
                );
                update_option('tgs_pos_pull_cursors', $cursors);
            } else {
                // Hết data rồi - reset cursors về đầu
                delete_option('tgs_pos_pull_cursors');
            }
        }

        // 8. Update watermark với server_time từ Hub
        $server_time = $schema_data['server_time'] ?? current_time('mysql', true);
        update_option('tgs_pos_last_pull_global_data_at', $server_time);

        return array(
            'success' => true,
            'message' => 'Schema pulled and applied successfully',
            'data' => array(
                'global_tables_created' => $execute_result['global']['created'],
                'local_tables_created' => $execute_result['local']['created'],
                'global_tables_failed' => $execute_result['global']['failed'],
                'local_tables_failed' => $execute_result['local']['failed'],
                'global_data_upserted' => $total_summary,
                'batch_count' => $batch_count,
                'is_incremental' => !empty($last_pull),
                'last_pull' => $last_pull,
                'new_watermark' => $server_time,
            ),
        );
    }

    /**
     * UPSERT dữ liệu GLOBAL vào local
     * INSERT nếu chưa có, UPDATE nếu đã có, DELETE nếu bị xóa
     */
    private static function upsert_global_data($global_data) {
        global $wpdb;

        $summary = array(
            'categories' => array('inserted' => 0, 'updated' => 0, 'deleted' => 0),
            'products' => array('inserted' => 0, 'updated' => 0, 'deleted' => 0),
            'policies' => array('inserted' => 0, 'updated' => 0, 'deleted' => 0),
            'lots' => array('inserted' => 0, 'updated' => 0, 'deleted' => 0),
        );

        // 1. UPSERT categories
        if (!empty($global_data['categories'])) {
            foreach ($global_data['categories'] as $cat) {
                $result = self::upsert_record('wp_global_product_cat', $cat, 'global_product_cat_id');
                $summary['categories'][$result]++;
            }
        }

        // 2. UPSERT products
        if (!empty($global_data['products'])) {
            foreach ($global_data['products'] as $product) {
                $result = self::upsert_record('wp_global_product_name', $product, 'global_product_name_id');
                $summary['products'][$result]++;
            }
        }

        // 3. UPSERT selling policies
        if (!empty($global_data['selling_policies'])) {
            foreach ($global_data['selling_policies'] as $policy) {
                $result = self::upsert_record('wp_global_selling_policy', $policy, 'global_selling_policy_id');
                $summary['policies'][$result]++;
            }
        }

        // 4. UPSERT product lots
        if (!empty($global_data['product_lots'])) {
            foreach ($global_data['product_lots'] as $lot) {
                $result = self::upsert_record('wp_global_product_lots', $lot, 'global_product_lots_id');
                $summary['lots'][$result]++;
            }
        }

        return array(
            'success' => true,
            'summary' => $summary,
        );
    }

    /**
     * UPSERT một record vào bảng
     * @return string 'inserted', 'updated', hoặc 'deleted'
     */
    private static function upsert_record($table_name, $data, $pk_column) {
        global $wpdb;

        // Lọc chỉ giữ các cột tồn tại
        $clean_data = self::filter_columns($data, $table_name);

        if (empty($clean_data)) {
            return 'skipped';
        }

        $pk_value = $data[$pk_column] ?? null;
        if (!$pk_value) {
            return 'skipped';
        }

        // Check nếu bị xóa (deleted_at hoặc is_deleted)
        $is_deleted = !empty($data['deleted_at']) || (!empty($data['is_deleted']) && $data['is_deleted'] == 1);

        if ($is_deleted) {
            // DELETE record
            $wpdb->delete($table_name, array($pk_column => $pk_value));
            return 'deleted';
        }

        // Check record đã tồn tại chưa
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE {$pk_column} = %s",
            $pk_value
        ));

        if ($exists) {
            // UPDATE
            $wpdb->update($table_name, $clean_data, array($pk_column => $pk_value));
            return 'updated';
        } else {
            // INSERT
            $wpdb->insert($table_name, $clean_data);
            return 'inserted';
        }
    }

    /**
     * Filter data để chỉ giữ các cột tồn tại trong bảng Local
     */
    private static function filter_columns($data, $table_name) {
        global $wpdb;

        // Lấy danh sách cột của bảng
        $columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        // Chỉ giữ các key có trong bảng
        $filtered = array();
        foreach ($data as $key => $value) {
            if (in_array($key, $columns)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
