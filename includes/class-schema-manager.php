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

        // 1. Insert categories
        if (!empty($global_data['categories'])) {
            foreach ($global_data['categories'] as $cat) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM wp_global_product_cat WHERE id = %d",
                    $cat['id']
                ));

                if (!$exists) {
                    $wpdb->insert('wp_global_product_cat', $cat);
                    $summary['categories']++;
                }
            }
        }

        // 2. Insert products
        if (!empty($global_data['products'])) {
            foreach ($global_data['products'] as $product) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM wp_global_product_name WHERE sku = %s",
                    $product['sku']
                ));

                if (!$exists) {
                    $wpdb->insert('wp_global_product_name', $product);
                    $summary['products']++;
                }
            }
        }

        // 3. Insert selling policies
        if (!empty($global_data['selling_policies'])) {
            foreach ($global_data['selling_policies'] as $policy) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM wp_global_selling_policy WHERE id = %d",
                    $policy['id']
                ));

                if (!$exists) {
                    $wpdb->insert('wp_global_selling_policy', $policy);
                    $summary['policies']++;
                }
            }
        }

        // 4. Insert product lots
        if (!empty($global_data['product_lots'])) {
            foreach ($global_data['product_lots'] as $lot) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM wp_global_product_lots WHERE lot_code = %s",
                    $lot['lot_code']
                ));

                if (!$exists) {
                    $wpdb->insert('wp_global_product_lots', $lot);
                    $summary['lots']++;
                }
            }
        }

        return array(
            'success' => true,
            'summary' => $summary,
        );
    }
}
