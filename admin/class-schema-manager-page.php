<?php
/**
 * Schema Manager Page
 * Quản lý cấu trúc database (tables, columns)
 * Không pull data - chỉ tạo/sửa schema
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Schema_Manager_Page {

    /**
     * Render schema manager page
     */
    public static function render() {
        // Check registered
        if (!TGS_POS_Config::is_registered()) {
            echo '<div class="wrap"><h1>Quản lý Schema</h1>';
            echo '<div class="notice notice-error"><p>Chưa kết nối với Hub. Vui lòng quét QR Code trước.</p></div>';
            echo '</div>';
            return;
        }

        // Get schema info từ Hub
        $schema_info = self::get_schema_info_from_hub();

        ?>
        <div class="wrap">
            <h1>🗂️ Cấu trúc Database (Schema)</h1>
            <p>Kiểm tra và đồng bộ cấu trúc bảng từ Hub. Tạo bảng mới hoặc thêm cột còn thiếu.</p>

            <?php if (!empty($schema_info)): ?>
                <h2>📊 Trạng thái Schema & Dữ liệu</h2>

                <!-- Bảng LOCAL -->
                <h3 style="margin-top: 20px;">Bảng LOCAL (riêng từng shop)</h3>
                <p style="color: #666;">Shop chỉ PULL về, không được PUSH lên</p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Loại</th>
                            <th>Tên bảng</th>
                            <th>Trạng thái</th>
                            <th>Chi tiết</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schema_info['local'] as $table): ?>
                        <tr>
                            <td><span class="dashicons dashicons-database"></span> Bảng LOCAL</td>
                            <td><code><?php echo esc_html($table['table']); ?></code></td>
                            <td>
                                <?php if (!$table['exists']): ?>
                                    <span style="color: orange;">⚠ Chưa tạo</span>
                                <?php elseif (isset($table['has_all_columns']) && !$table['has_all_columns']): ?>
                                    <span style="color: red;">⚠ Thiếu cột: <?php echo esc_html(implode(', ', $table['missing_columns'])); ?></span>
                                <?php else: ?>
                                    <span style="color: green;">✓ Đầy đủ</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($table['description']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Bảng GLOBAL -->
                <h3 style="margin-top: 30px;">Bảng GLOBAL (dùng chung toàn hệ thống)</h3>
                <p style="color: #666;">Shop chỉ PULL về, không được PUSH lên</p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Loại</th>
                            <th>Tên bảng</th>
                            <th>Trạng thái</th>
                            <th>Chi tiết</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schema_info['global'] as $table): ?>
                        <tr>
                            <td><span class="dashicons dashicons-database"></span> Bảng GLOBAL</td>
                            <td><code><?php echo esc_html($table['table']); ?></code></td>
                            <td>
                                <?php if (!$table['exists']): ?>
                                    <span style="color: orange;">⚠ Chưa tạo</span>
                                <?php elseif (isset($table['has_all_columns']) && !$table['has_all_columns']): ?>
                                    <span style="color: red;">⚠ Thiếu cột: <?php echo esc_html(implode(', ', $table['missing_columns'])); ?></span>
                                <?php else: ?>
                                    <span style="color: green;">✓ Đầy đủ</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($table['description']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Check status -->
                <?php
                $all_exists = true;
                $has_missing_columns = false;
                foreach (array_merge($schema_info['global'], $schema_info['local']) as $t) {
                    if (!$t['exists']) {
                        $all_exists = false;
                        break;
                    }
                    if (isset($t['has_all_columns']) && !$t['has_all_columns']) {
                        $has_missing_columns = true;
                    }
                }
                ?>

                <?php if ($all_exists && !$has_missing_columns): ?>
                    <div class="notice notice-success" style="margin-top: 20px;">
                        <p><strong>✓ Hệ thống đã sẵn sàng bán hàng</strong></p>
                        <ul>
                            <li>Tất cả bảng đã được tạo</li>
                            <li>Có thể Pull dữ liệu từ Hub</li>
                            <li>Có thể Push giao dịch lên Hub</li>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="notice notice-warning" style="margin-top: 20px;">
                        <p><strong>⚠️ Cần đồng bộ schema</strong></p>
                        <?php if (!$all_exists): ?>
                            <p>Một số bảng chưa được tạo. Click nút bên dưới để tạo.</p>
                        <?php endif; ?>
                        <?php if ($has_missing_columns): ?>
                            <p style="color: red;">Một số bảng thiếu cột đồng bộ. Click nút bên dưới để thêm cột.</p>
                        <?php endif; ?>
                    </div>

                    <p class="submit">
                        <button type="button" id="btn-sync-schema" class="button button-primary button-large">
                            🔧 Kiểm tra & Tạo bảng/Thêm cột
                        </button>
                    </p>
                <?php endif; ?>

                <div id="sync-result" style="margin-top: 20px;"></div>

            <?php else: ?>
                <div class="notice notice-info">
                    <p>Đang tải thông tin schema từ Hub...</p>
                </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#btn-sync-schema').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span> Đang tạo bảng...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'tgs_sync_schema',
                        nonce: '<?php echo wp_create_nonce('tgs_sync_schema'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var html = '<div class="notice notice-success"><p><strong>✓ Đồng bộ schema thành công!</strong></p><ul>';

                            if (data.global_created && data.global_created.length > 0) {
                                html += '<li>Tạo ' + data.global_created.length + ' bảng GLOBAL: ' + data.global_created.join(', ') + '</li>';
                            }
                            if (data.local_created && data.local_created.length > 0) {
                                html += '<li>Tạo ' + data.local_created.length + ' bảng LOCAL: ' + data.local_created.join(', ') + '</li>';
                            }
                            if (data.global_created.length === 0 && data.local_created.length === 0) {
                                html += '<li>Tất cả bảng đã tồn tại</li>';
                            }

                            html += '</ul></div>';
                            $('#sync-result').html(html);

                            setTimeout(function() { location.reload(); }, 2000);
                        } else {
                            $('#sync-result').html('<div class="notice notice-error"><p><strong>✗ Lỗi:</strong> ' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#sync-result').html('<div class="notice notice-error"><p><strong>✗ Lỗi kết nối</strong></p></div>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html('🔧 Kiểm tra & Tạo bảng');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get schema info từ Hub
     */
    private static function get_schema_info_from_hub() {
        global $wpdb;

        // Pull schema từ Hub
        $result = TGS_POS_HTTP_Client::pull_schema(null);

        if (!$result['success']) {
            return array();
        }

        $schema_data = $result['data'];
        $info = array('global' => array(), 'local' => array());

        // Check GLOBAL tables
        foreach ($schema_data['sql_statements']['global'] ?? array() as $stmt) {
            $table_name = $stmt['table'] ?? '';
            $exists = ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name);

            // Check missing columns nếu bảng đã tồn tại
            $missing_columns = array();
            $has_all_columns = true;
            if ($exists) {
                $check = self::check_table_columns($table_name, $stmt['sql'] ?? '');
                $missing_columns = $check['missing'];
                $has_all_columns = $check['has_all'];
            }

            $info['global'][] = array(
                'method' => $stmt['method'] ?? '',
                'table' => $table_name,
                'exists' => $exists,
                'has_all_columns' => $has_all_columns,
                'missing_columns' => $missing_columns,
                'description' => self::get_table_description($table_name),
            );
        }

        // Check LOCAL tables
        foreach ($schema_data['sql_statements']['local'] ?? array() as $stmt) {
            // LOCAL tables có prefix
            $table_name = $wpdb->prefix . str_replace('{{prefix}}', '', str_replace('wp_', '', $stmt['table'] ?? ''));
            $exists = ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name);

            // Check missing columns nếu bảng đã tồn tại
            $missing_columns = array();
            $has_all_columns = true;
            if ($exists) {
                $check = self::check_table_columns($table_name, $stmt['sql'] ?? '');
                $missing_columns = $check['missing'];
                $has_all_columns = $check['has_all'];
            }

            $info['local'][] = array(
                'method' => $stmt['method'] ?? '',
                'table' => $table_name,
                'exists' => $exists,
                'has_all_columns' => $has_all_columns,
                'missing_columns' => $missing_columns,
                'description' => self::get_table_description($table_name),
            );
        }

        return $info;
    }

    /**
     * Check if table has all required columns from CREATE TABLE SQL
     *
     * @param string $table_name Full table name
     * @param string $sql CREATE TABLE statement
     * @return array ['has_all' => bool, 'missing' => array]
     */
    private static function check_table_columns($table_name, $sql) {
        global $wpdb;

        // Parse required columns from SQL
        $required_columns = self::parse_columns_from_sql($sql);

        // Get actual columns in database
        $db_columns = $wpdb->get_col("DESCRIBE {$table_name}", 0);

        // Check which sync columns are missing
        $sync_columns = array('updated_at', 'deleted_at', 'created_at', 'is_deleted', 'user_id');
        $missing = array();

        foreach ($sync_columns as $col) {
            if (in_array($col, $required_columns) && !in_array($col, $db_columns)) {
                $missing[] = $col;
            }
        }

        return array(
            'has_all' => empty($missing),
            'missing' => $missing,
        );
    }

    /**
     * Parse column names from CREATE TABLE SQL
     */
    private static function parse_columns_from_sql($sql) {
        $columns = array();

        // Tách phần columns (trước PRIMARY KEY)
        $primary_pos = stripos($sql, 'PRIMARY KEY');
        if ($primary_pos !== false) {
            $columns_part = substr($sql, 0, $primary_pos);
        } else {
            $columns_part = $sql;
        }

        // Tìm phần định nghĩa cột
        if (preg_match('/CREATE TABLE[^(]+\((.*)/is', $columns_part, $matches)) {
            $lines = explode("\n", $matches[1]);

            foreach ($lines as $line) {
                $line = trim($line);
                $line = rtrim($line, ',');

                // Bỏ qua dòng rỗng, comment
                if (empty($line) ||
                    strpos($line, '//') === 0 ||
                    strpos($line, '--') === 0) {
                    continue;
                }

                // Extract column name (có thể có backticks)
                if (preg_match('/^`?([a-z_][a-z0-9_]*)`?/i', $line, $col_match)) {
                    $columns[] = $col_match[1];
                }
            }
        }

        return $columns;
    }

    /**
     * Get table description
     */
    private static function get_table_description($table_name) {
        $descriptions = array(
            'wp_global_product_name' => 'Catalog sản phẩm toàn hệ thống',
            'wp_global_product_cat' => 'Danh mục sản phẩm',
            'wp_global_product_lots' => 'Lô hàng và HSD',
            'wp_global_selling_policy' => 'Chính sách bán hàng',
            'wp_global_selling_policy_items' => 'Chi tiết chính sách bán hàng',
            'wp_global_supplier' => 'Danh sách nhà cung cấp',
        );

        if (strpos($table_name, 'local_ledger_person') !== false) {
            return 'Khách hàng và nhà cung cấp';
        }
        if (strpos($table_name, 'local_ledger_item') !== false) {
            return 'Chi tiết items trong phiếu';
        }
        if (strpos($table_name, 'local_ledger_meta') !== false) {
            return 'Metadata cho ledger';
        }
        if (strpos($table_name, 'local_ledger') !== false) {
            return 'Phiếu chứng từ (đơn hàng, phiếu nhập, xuất)';
        }

        return $descriptions[$table_name] ?? 'Bảng dữ liệu';
    }
}
