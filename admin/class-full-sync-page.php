<?php
/**
 * Full Sync Page
 * Cho phép pull full data từ Hub (không incremental)
 * Dùng AJAX để pull từng batch tránh timeout
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
            <form id="full-sync-form">
                <?php wp_nonce_field('tgs_full_sync', 'tgs_full_sync_nonce'); ?>

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
                    <button type="button" id="btn-full-sync" class="button button-primary button-large">
                        🔄 Pull Full Sync
                    </button>
                </p>
            </form>

            <!-- Progress Display -->
            <div id="sync-progress" style="display: none; margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                <h3 style="margin-top: 0;">
                    <span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>
                    Đang đồng bộ...
                </h3>
                <div id="sync-status" style="font-family: monospace; white-space: pre-wrap;"></div>
                <div style="margin-top: 15px;">
                    <strong>Tiến độ:</strong>
                    <div style="background: #fff; border: 1px solid #ccc; height: 30px; margin-top: 5px; position: relative;">
                        <div id="progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
                        <span id="progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold;"></span>
                    </div>
                </div>
            </div>

            <div id="sync-result" style="display: none; margin-top: 20px;"></div>

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

            $('#btn-full-sync').on('click', function() {
                if (!confirm('⚠️ XÓA TOÀN BỘ data cũ và pull lại từ Hub?\n\nHành động này không thể hoàn tác!')) {
                    return;
                }

                // Get selected tables
                var globalTables = [];
                $('input[name="global_tables[]"]:checked').each(function() {
                    globalTables.push($(this).val());
                });

                var localTables = [];
                $('input[name="local_tables[]"]:checked').each(function() {
                    localTables.push($(this).val());
                });

                if (globalTables.length === 0 && localTables.length === 0) {
                    alert('Chưa chọn bảng nào!');
                    return;
                }

                // Start sync
                startFullSync(globalTables, localTables);
            });

            function startFullSync(globalTables, localTables) {
                // Hide form, show progress
                $('#full-sync-form').hide();
                $('#sync-progress').show();
                $('#sync-result').hide();

                var batchCount = 0;
                var totalRecords = {categories: 0, products: 0, policies: 0, lots: 0, local: 0};
                var cursors = {categories: 0, products: 0, policies: 0, lots: 0};

                // Step 1: Truncate tables
                updateStatus('Bước 1: Xóa data cũ...');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'tgs_full_sync_truncate',
                        nonce: $('#tgs_full_sync_nonce').val(),
                        global_tables: globalTables,
                        local_tables: localTables
                    },
                    success: function(response) {
                        if (response.success) {
                            updateStatus('✓ Đã xóa data cũ\n');
                            // Step 2: Pull batches
                            pullNextBatch();
                        } else {
                            showError('Lỗi khi xóa data: ' + response.data);
                        }
                    },
                    error: function() {
                        showError('Lỗi kết nối khi xóa data');
                    }
                });

                function pullNextBatch() {
                    batchCount++;
                    updateStatus('Batch #' + batchCount + ': Đang pull từ Hub...');
                    updateProgress(batchCount * 10, 'Batch ' + batchCount);

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'tgs_full_sync_batch',
                            nonce: $('#tgs_full_sync_nonce').val(),
                            cursors: cursors,
                            batch_count: batchCount,
                            selected_global_tables: globalTables,
                            selected_local_tables: localTables
                        },
                        success: function(response) {
                            if (response.success) {
                                var data = response.data;

                                // Update totals
                                totalRecords.categories += data.batch_summary.categories || 0;
                                totalRecords.products += data.batch_summary.products || 0;
                                totalRecords.policies += data.batch_summary.policies || 0;
                                totalRecords.lots += data.batch_summary.lots || 0;
                                totalRecords.local += data.batch_summary.local_records || 0;

                                var statusMsg = '✓ Batch #' + batchCount + ': ' +
                                    'Cat: ' + (data.batch_summary.categories || 0) + ', ' +
                                    'Prod: ' + (data.batch_summary.products || 0) + ', ' +
                                    'Policy: ' + (data.batch_summary.policies || 0) + ', ' +
                                    'Lot: ' + (data.batch_summary.lots || 0);

                                if (data.batch_summary.local_records) {
                                    statusMsg += ', Local: ' + data.batch_summary.local_records;
                                }

                                updateStatus(statusMsg + '\n');

                                // Check has more
                                if (data.has_more) {
                                    cursors = data.cursors;
                                    pullNextBatch();
                                } else {
                                    showSuccess();
                                }
                            } else {
                                showError('Lỗi batch #' + batchCount + ': ' + response.data);
                            }
                        },
                        error: function(xhr) {
                            showError('Lỗi kết nối batch #' + batchCount + ': ' + xhr.statusText);
                        }
                    });
                }

                function updateStatus(message) {
                    $('#sync-status').append(message + '\n');
                    $('#sync-status').scrollTop($('#sync-status')[0].scrollHeight);
                }

                function updateProgress(percent, text) {
                    $('#progress-bar').css('width', Math.min(percent, 100) + '%');
                    $('#progress-text').text(text);
                }

                function showSuccess() {
                    $('#sync-progress').hide();
                    var resultHtml = '<div class="notice notice-success"><p><strong>✓ Pull full thành công!</strong></p>' +
                        '<ul>' +
                        '<li>Tổng batches: ' + batchCount + '</li>' +
                        '<li>Categories: ' + totalRecords.categories + '</li>' +
                        '<li>Products: ' + totalRecords.products + '</li>' +
                        '<li>Policies: ' + totalRecords.policies + '</li>' +
                        '<li>Lots: ' + totalRecords.lots + '</li>';

                    if (totalRecords.local > 0) {
                        resultHtml += '<li>Local records: ' + totalRecords.local + '</li>';
                    }

                    resultHtml += '</ul></div>';

                    $('#sync-result').html(resultHtml).show();
                    $('#full-sync-form').show();
                }

                function showError(message) {
                    $('#sync-progress').hide();
                    $('#sync-result').html(
                        '<div class="notice notice-error"><p><strong>✗ Lỗi:</strong> ' + message + '</p></div>'
                    ).show();
                    $('#full-sync-form').show();
                }
            }
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
}
