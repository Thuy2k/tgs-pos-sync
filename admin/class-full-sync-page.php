<?php
/**
 * Full Sync Page
 * Cho phép pull full data từ Hub (không incremental)
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Full_Sync_Page {

    /**
     * Render full sync page
     */
    public static function render() {
        // Check registered
        if (!TGS_POS_Config::is_registered()) {
            echo '<div class="wrap"><h1>Full Sync</h1>';
            echo '<div class="notice notice-error"><p>Chưa kết nối với Hub. Vui lòng quét QR Code trước.</p></div>';
            echo '</div>';
            return;
        }

        // Handle form submission
        if (isset($_POST['tgs_pull_full_sync']) && check_admin_referer('tgs_full_sync')) {
            $result = self::handle_full_sync($_POST);

            if ($result['success']) {
                echo '<div class="notice notice-success"><p>' . esc_html($result['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
            }
        }

        // Get available tables từ Hub
        $available_tables = self::get_available_tables_from_hub();

        ?>
        <div class="wrap">
            <h1>🔄 Pull Full Sync (Đồng bộ toàn bộ)</h1>
            <p>Pull toàn bộ dữ liệu từ Hub về Local (không incremental). Dùng khi cần reset hoàn toàn hoặc bảng thiếu cột sync.</p>

            <div class="notice notice-warning">
                <p><strong>⚠️ Cảnh báo:</strong></p>
                <ul>
                    <li>Full sync sẽ <strong>XÓA TOÀN BỘ data cũ</strong> và kéo lại từ đầu</li>
                    <li>Chỉ dùng khi: bảng bị lỗi, thiếu data, hoặc cần reset</li>
                    <li>Bảng có đủ <code>updated_at</code> + <code>deleted_at</code> → Nên dùng Incremental Sync (pull thường)</li>
                </ul>
            </div>

            <?php if (!empty($available_tables)): ?>
            <form method="post" action="">
                <?php wp_nonce_field('tgs_full_sync'); ?>

                <h2>Chọn bảng GLOBAL cần pull full</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="check-all-global" />
                            </th>
                            <th>Tên bảng</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($available_tables['global'] ?? array() as $table): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="global_tables[]" value="<?php echo esc_attr($table['name']); ?>" />
                            </td>
                            <td><code><?php echo esc_html($table['name']); ?></code></td>
                            <td>
                                <?php if ($table['has_sync_columns']): ?>
                                    <span style="color: green;">✓ Có đủ cột sync</span>
                                    <em style="color: #666;"> (Khuyến nghị dùng incremental sync)</em>
                                <?php else: ?>
                                    <span style="color: orange;">⚠️ Thiếu: <?php echo implode(', ', $table['missing_columns']); ?></span>
                                    <em style="color: #666;"> (Chỉ có thể dùng full sync)</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h2 style="margin-top: 30px;">Chọn bảng LOCAL cần pull full</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="check-all-local" />
                            </th>
                            <th>Tên bảng</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($available_tables['local'] ?? array() as $table): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="local_tables[]" value="<?php echo esc_attr($table['name']); ?>" />
                            </td>
                            <td><code><?php echo esc_html($table['name']); ?></code></td>
                            <td>
                                <?php if ($table['has_sync_columns']): ?>
                                    <span style="color: green;">✓ Có đủ cột sync</span>
                                    <em style="color: #666;"> (Khuyến nghị dùng incremental sync)</em>
                                <?php else: ?>
                                    <span style="color: orange;">⚠️ Thiếu: <?php echo implode(', ', $table['missing_columns']); ?></span>
                                    <em style="color: #666;"> (Chỉ có thể dùng full sync)</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" name="tgs_pull_full_sync" class="button button-primary button-large"
                            onclick="return confirm('⚠️ XÓA TOÀN BỘ data cũ và pull lại từ Hub?\n\nHành động này không thể hoàn tác!');">
                        🔄 Pull Full Sync
                    </button>
                </p>
            </form>
            <?php else: ?>
            <div class="notice notice-info">
                <p>Đang tải danh sách bảng từ Hub...</p>
            </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#check-all-global').on('change', function() {
                $('input[name="global_tables[]"]').prop('checked', this.checked);
            });
            $('#check-all-local').on('change', function() {
                $('input[name="local_tables[]"]').prop('checked', this.checked);
            });
        });
        </script>
        <?php
    }

    /**
     * Get available tables từ Hub config
     */
    private static function get_available_tables_from_hub() {
        // Pull schema info từ Hub (không có since = get metadata)
        $result = TGS_POS_HTTP_Client::pull_schema(null);

        if (!$result['success']) {
            return array();
        }

        $schema_data = $result['data'];
        $tables = array('global' => array(), 'local' => array());

        // Parse GLOBAL tables
        foreach ($schema_data['sql_statements']['global'] ?? array() as $stmt) {
            $tables['global'][] = array(
                'name' => $stmt['method'] ?? '',
                'table' => $stmt['table'] ?? '',
                'has_sync_columns' => self::check_sql_has_sync_columns($stmt['sql'] ?? ''),
                'missing_columns' => self::get_missing_columns($stmt['sql'] ?? ''),
            );
        }

        // Parse LOCAL tables
        foreach ($schema_data['sql_statements']['local'] ?? array() as $stmt) {
            $tables['local'][] = array(
                'name' => $stmt['method'] ?? '',
                'table' => $stmt['table'] ?? '',
                'has_sync_columns' => self::check_sql_has_sync_columns($stmt['sql'] ?? ''),
                'missing_columns' => self::get_missing_columns($stmt['sql'] ?? ''),
            );
        }

        return $tables;
    }

    /**
     * Check SQL có updated_at và deleted_at không
     */
    private static function check_sql_has_sync_columns($sql) {
        return (stripos($sql, 'updated_at') !== false && stripos($sql, 'deleted_at') !== false);
    }

    /**
     * Get missing columns từ SQL
     */
    private static function get_missing_columns($sql) {
        $missing = array();
        if (stripos($sql, 'updated_at') === false) {
            $missing[] = 'updated_at';
        }
        if (stripos($sql, 'deleted_at') === false) {
            $missing[] = 'deleted_at';
        }
        return $missing;
    }

    /**
     * Handle full sync request
     */
    private static function handle_full_sync($post_data) {
        global $wpdb;

        $global_tables = isset($post_data['global_tables']) ? $post_data['global_tables'] : array();
        $local_tables = isset($post_data['local_tables']) ? $post_data['local_tables'] : array();

        if (empty($global_tables) && empty($local_tables)) {
            return array('success' => false, 'message' => 'Chưa chọn bảng nào');
        }

        // Pull full schema (since = null)
        $result = TGS_POS_HTTP_Client::pull_schema(null);

        if (!$result['success']) {
            return array('success' => false, 'message' => 'Không thể kết nối Hub: ' . ($result['message'] ?? 'Unknown error'));
        }

        $schema_data = $result['data'];

        // Execute SQL statements
        if (!empty($schema_data['sql_statements'])) {
            TGS_POS_Database_Schema::execute_sql_statements($schema_data['sql_statements']);
        }

        // TRUNCATE tables được chọn
        foreach ($global_tables as $method_name) {
            $table_name = 'wp_' . str_replace('sql_', '', $method_name);
            $wpdb->query("TRUNCATE TABLE {$table_name}");
        }

        foreach ($local_tables as $method_name) {
            $table_name = $wpdb->prefix . str_replace('sql_', '', $method_name);
            $wpdb->query("TRUNCATE TABLE {$table_name}");
        }

        // INSERT full data
        $summary = TGS_POS_Schema_Manager::upsert_global_data_direct($schema_data['global_data']);

        // Update watermark
        $server_time = $schema_data['server_time'] ?? current_time('mysql', true);
        update_option('tgs_pos_last_pull_global_data_at', $server_time);

        return array(
            'success' => true,
            'message' => sprintf(
                'Pull full thành công! Categories: %d, Products: %d, Policies: %d, Lots: %d',
                $summary['categories'],
                $summary['products'],
                $summary['policies'],
                $summary['lots']
            ),
        );
    }
}
