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
     */
    public static function pull_and_apply() {
        // 1. Pull schema từ Hub (lấy SQL statements)
        $result = TGS_POS_HTTP_Client::pull_schema();

        if (!$result['success']) {
            return $result;
        }

        $schema_data = $result['data'];

        // 2. Execute SQL statements từ Hub
        $execute_result = TGS_POS_Database_Schema::execute_sql_statements($schema_data['sql_statements']);

        // 3. Insert dữ liệu GLOBAL
        $insert_result = self::insert_global_data($schema_data['global_data']);
        if (!$insert_result['success']) {
            return $insert_result;
        }

        return array(
            'success' => true,
            'message' => 'Schema pulled and applied successfully',
            'data' => array(
                'global_tables_created' => $execute_result['global']['created'],
                'local_tables_created' => $execute_result['local']['created'],
                'global_tables_failed' => $execute_result['global']['failed'],
                'local_tables_failed' => $execute_result['local']['failed'],
                'global_data_inserted' => $insert_result['summary'],
            ),
        );
    }

    /**
     * Insert dữ liệu GLOBAL vào local
     */
    private static function insert_global_data($global_data) {
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
