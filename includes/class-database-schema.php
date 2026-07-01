<?php
/**
 * Database Schema Manager
 * Quản lý schema database cho Local POS
 * Schema được pull từ Hub (single source of truth)
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Database_Schema {

    const DB_VERSION = '1.0.0';
    const OPTION_DB_VERSION = 'tgs_pos_db_version';

    /**
     * Execute SQL statements từ Hub
     * @param array $sql_statements Array of SQL CREATE TABLE statements
     * @return array Result with success/failure info
     */
    public static function execute_sql_statements($sql_statements) {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $results = array(
            'global' => array('created' => array(), 'failed' => array()),
            'local' => array('created' => array(), 'failed' => array()),
        );

        // Execute GLOBAL tables
        if (!empty($sql_statements['global'])) {
            foreach ($sql_statements['global'] as $statement) {
                $table_name = self::extract_table_name($statement['sql']);
                try {
                    dbDelta($statement['sql']);

                    // Verify table created
                    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
                        $results['global']['created'][] = $table_name;
                    } else {
                        $results['global']['failed'][] = $table_name;
                    }
                } catch (Exception $e) {
                    $results['global']['failed'][] = $table_name . ' - ' . $e->getMessage();
                }
            }
        }

        // Execute LOCAL tables
        if (!empty($sql_statements['local'])) {
            foreach ($sql_statements['local'] as $statement) {
                $table_name = self::extract_table_name($statement['sql']);
                try {
                    dbDelta($statement['sql']);

                    // Verify table created (with wp_ prefix)
                    $full_name = $wpdb->prefix . str_replace($wpdb->prefix, '', $table_name);
                    if ($wpdb->get_var("SHOW TABLES LIKE '{$full_name}'") === $full_name) {
                        $results['local']['created'][] = $full_name;
                    } else {
                        $results['local']['failed'][] = $full_name;
                    }
                } catch (Exception $e) {
                    $results['local']['failed'][] = $table_name . ' - ' . $e->getMessage();
                }
            }
        }

        // Update version
        if (empty($results['global']['failed']) && empty($results['local']['failed'])) {
            update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
        }

        return $results;
    }

    /**
     * Extract table name from CREATE TABLE SQL
     */
    private static function extract_table_name($sql) {
        if (preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }
        return 'unknown';
    }

    /**
     * Kiểm tra trạng thái schema
     * Lấy danh sách bảng từ Hub response để kiểm tra
     */
    public static function get_schema_status($expected_tables = null) {
        global $wpdb;

        if ($expected_tables === null) {
            // Fallback: danh sách bảng cơ bản
            $expected_tables = array(
                'local' => array(
                    'local_ledger_person',
                    'local_ledger',
                    'local_ledger_item',
                    'local_ledger_meta',
                ),
                'global' => array(
                    'wp_global_product_name',
                    'wp_global_product_cat',
                    'wp_global_product_lots',
                    'wp_global_selling_policy',
                    'wp_global_selling_policy_items',
                ),
            );
        }

        // Check LOCAL tables (with prefix)
        $local_existing = array();
        $local_missing = array();
        foreach ($expected_tables['local'] as $table) {
            $full_name = $wpdb->prefix . $table;
            if ($wpdb->get_var("SHOW TABLES LIKE '{$full_name}'") === $full_name) {
                $local_existing[] = $table;
            } else {
                $local_missing[] = $table;
            }
        }

        // Check GLOBAL tables (full table name)
        $global_existing = array();
        $global_missing = array();
        foreach ($expected_tables['global'] as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $global_existing[] = $table;
            } else {
                $global_missing[] = $table;
            }
        }

        return array(
            'local' => array(
                'required' => $expected_tables['local'],
                'existing' => $local_existing,
                'missing' => $local_missing,
                'is_complete' => empty($local_missing),
                'progress' => count($local_existing) . '/' . count($expected_tables['local']),
            ),
            'global' => array(
                'required' => $expected_tables['global'],
                'existing' => $global_existing,
                'missing' => $global_missing,
                'is_complete' => empty($global_missing),
                'progress' => count($global_existing) . '/' . count($expected_tables['global']),
            ),
            'is_complete' => empty($local_missing) && empty($global_missing),
        );
    }
}
