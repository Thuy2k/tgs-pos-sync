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
     * Hỗ trợ incremental sync
     */
    public static function pull_and_apply() {
        global $wpdb;

        // 1. Lấy timestamp lần pull cuối (incremental sync)
        $last_pull = get_option('tgs_pos_last_pull_global_data_at', null);

        // 2. Pull schema từ Hub (lấy SQL statements + data thay đổi từ $last_pull)
        $result = TGS_POS_HTTP_Client::pull_schema($last_pull);

        if (!$result['success']) {
            return $result;
        }

        $schema_data = $result['data'];

        // 3. Execute SQL statements từ Hub (chỉ chạy nếu có tables mới)
        if (!empty($schema_data['sql_statements'])) {
            $execute_result = TGS_POS_Database_Schema::execute_sql_statements($schema_data['sql_statements']);
        } else {
            $execute_result = array(
                'global' => array('created' => array(), 'failed' => array()),
                'local' => array('created' => array(), 'failed' => array()),
            );
        }

        // 4. UPSERT dữ liệu GLOBAL (insert hoặc update)
        $upsert_result = self::upsert_global_data($schema_data['global_data']);
        if (!$upsert_result['success']) {
            return $upsert_result;
        }

        // 5. Update watermark với server_time từ Hub
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
                'global_data_upserted' => $upsert_result['summary'],
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
        global $wpdb;

        $summary = array(
            'categories' => 0,
            'products' => 0,
            'policies' => 0,
            'lots' => 0,
        );

        // 1. Insert categories - dùng PRIMARY KEY để check duplicate
        if (!empty($global_data['categories'])) {
            foreach ($global_data['categories'] as $cat) {
                // Check bằng primary key (tùy bảng Hub dùng cột gì)
                $pk_column = isset($cat['global_product_cat_id']) ? 'global_product_cat_id' : 'id';
                $pk_value = $cat[$pk_column] ?? null;

                if (!$pk_value) continue;

                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM wp_global_product_cat WHERE {$pk_column} = %d",
                    $pk_value
                ));

                if (!$exists) {
                    $clean_cat = self::filter_columns($cat, 'wp_global_product_cat');
                    $wpdb->insert('wp_global_product_cat', $clean_cat);
                    $summary['categories']++;
                }
            }
        }

        // 2. Insert products
        if (!empty($global_data['products'])) {
            foreach ($global_data['products'] as $product) {
                $pk_column = isset($product['global_product_name_id']) ? 'global_product_name_id' : 'sku';
                $pk_value = $product[$pk_column] ?? null;

                if (!$pk_value) continue;

                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM wp_global_product_name WHERE {$pk_column} = %s",
                    $pk_value
                ));

                if (!$exists) {
                    $clean_product = self::filter_columns($product, 'wp_global_product_name');
                    $wpdb->insert('wp_global_product_name', $clean_product);
                    $summary['products']++;
                }
            }
        }

        // 3. Insert selling policies
        if (!empty($global_data['selling_policies'])) {
            foreach ($global_data['selling_policies'] as $policy) {
                $pk_column = isset($policy['global_selling_policy_id']) ? 'global_selling_policy_id' : 'id';
                $pk_value = $policy[$pk_column] ?? null;

                if (!$pk_value) continue;

                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM wp_global_selling_policy WHERE {$pk_column} = %d",
                    $pk_value
                ));

                if (!$exists) {
                    $clean_policy = self::filter_columns($policy, 'wp_global_selling_policy');
                    $wpdb->insert('wp_global_selling_policy', $clean_policy);
                    $summary['policies']++;
                }
            }
        }

        // 4. Insert product lots
        if (!empty($global_data['product_lots'])) {
            foreach ($global_data['product_lots'] as $lot) {
                $pk_column = isset($lot['global_product_lots_id']) ? 'global_product_lots_id' : 'lot_code';
                $pk_value = $lot[$pk_column] ?? null;

                if (!$pk_value) continue;

                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM wp_global_product_lots WHERE {$pk_column} = %s",
                    $pk_value
                ));

                if (!$exists) {
                    $clean_lot = self::filter_columns($lot, 'wp_global_product_lots');
                    $wpdb->insert('wp_global_product_lots', $clean_lot);
                    $summary['lots']++;
                }
            }
        }

        return array(
            'success' => true,
            'summary' => $summary,
        );
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
