<?php
/**
 * Status View
 * Giao diện trạng thái sync
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php _e('Trạng thái Sync', 'tgs-pos-sync'); ?></h1>

    <?php if (!$is_registered): ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('Chưa kết nối với Hub.', 'tgs-pos-sync'); ?></strong>
                <a href="<?php echo admin_url('admin.php?page=tgs-pos-sync'); ?>"><?php _e('Đăng ký ngay', 'tgs-pos-sync'); ?></a>
            </p>
        </div>
    <?php else: ?>

        <!-- Sync Actions -->
        <div style="background: #fff; padding: 20px; margin: 20px 0; border-left: 4px solid #2271b1;">
            <h2><?php _e('Đồng bộ thủ công', 'tgs-pos-sync'); ?></h2>
            <p><?php _e('Sync tự động chạy mỗi 5-10 phút. Bạn có thể trigger thủ công bên dưới.', 'tgs-pos-sync'); ?></p>
            <p>
                <button type="button" class="button button-primary" id="tgs-push-btn">
                    <span class="dashicons dashicons-upload" style="vertical-align: middle;"></span>
                    <?php _e('Push lên Hub', 'tgs-pos-sync'); ?>
                </button>
                <button type="button" class="button button-primary" id="tgs-pull-btn">
                    <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                    <?php _e('Pull từ Hub', 'tgs-pos-sync'); ?>
                </button>
                <button type="button" class="button button-secondary" id="tgs-full-sync-btn">
                    <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                    <?php _e('Full Sync', 'tgs-pos-sync'); ?>
                </button>
            </p>
            <div id="tgs-sync-result"></div>
        </div>

        <!-- Sync Stats -->
        <h2><?php _e('Thống kê Sync', 'tgs-pos-sync'); ?></h2>

        <div style="display: flex; gap: 20px; margin: 20px 0;">
            <div style="flex: 1; background: #fff; padding: 20px; border-left: 4px solid #2271b1;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-upload"></span>
                    <?php _e('Outbox (Local→Hub)', 'tgs-pos-sync'); ?>
                </h3>
                <table class="widefat">
                    <tr>
                        <th><?php _e('Tổng:', 'tgs-pos-sync'); ?></th>
                        <td><strong><?php echo number_format($outbox_stats['total']); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php _e('Chờ gửi:', 'tgs-pos-sync'); ?></th>
                        <td><span style="color: #dba617;"><?php echo number_format($outbox_stats['pending']); ?></span></td>
                    </tr>
                    <tr>
                        <th><?php _e('Đã gửi:', 'tgs-pos-sync'); ?></th>
                        <td><span style="color: #00a32a;"><?php echo number_format($outbox_stats['sent']); ?></span></td>
                    </tr>
                    <tr>
                        <th><?php _e('Đã ACK:', 'tgs-pos-sync'); ?></th>
                        <td><span style="color: #00a32a;"><?php echo number_format($outbox_stats['acked']); ?></span></td>
                    </tr>
                    <tr>
                        <th><?php _e('Lỗi:', 'tgs-pos-sync'); ?></th>
                        <td><span style="color: #d63638;"><?php echo number_format($outbox_stats['errors']); ?></span></td>
                    </tr>
                </table>
                <p style="margin-top: 15px; color: #646970; font-size: 13px;">
                    <?php _e('Lần push cuối:', 'tgs-pos-sync'); ?>
                    <strong>
                        <?php
                        if ($last_push) {
                            echo human_time_diff(strtotime($last_push), current_time('timestamp')) . ' ' . __('trước', 'tgs-pos-sync');
                        } else {
                            echo __('Chưa có', 'tgs-pos-sync');
                        }
                        ?>
                    </strong>
                </p>
            </div>

            <div style="flex: 1; background: #fff; padding: 20px; border-left: 4px solid #00a32a;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-download"></span>
                    <?php _e('Inbox (Hub→Local)', 'tgs-pos-sync'); ?>
                </h3>
                <table class="widefat">
                    <tr>
                        <th><?php _e('Tổng:', 'tgs-pos-sync'); ?></th>
                        <td><strong><?php echo number_format($inbox_stats['total']); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php _e('Chờ apply:', 'tgs-pos-sync'); ?></th>
                        <td><span style="color: #dba617;"><?php echo number_format($inbox_stats['pending']); ?></span></td>
                    </tr>
                    <tr>
                        <th><?php _e('Đã apply:', 'tgs-pos-sync'); ?></th>
                        <td><span style="color: #00a32a;"><?php echo number_format($inbox_stats['applied']); ?></span></td>
                    </tr>
                    <tr>
                        <th><?php _e('Lỗi:', 'tgs-pos-sync'); ?></th>
                        <td><span style="color: #d63638;"><?php echo number_format($inbox_stats['errors']); ?></span></td>
                    </tr>
                </table>
                <p style="margin-top: 15px; color: #646970; font-size: 13px;">
                    <?php _e('Lần pull cuối:', 'tgs-pos-sync'); ?>
                    <strong>
                        <?php
                        if ($last_pull) {
                            echo human_time_diff(strtotime($last_pull), current_time('timestamp')) . ' ' . __('trước', 'tgs-pos-sync');
                        } else {
                            echo __('Chưa có', 'tgs-pos-sync');
                        }
                        ?>
                    </strong>
                </p>
            </div>
        </div>

        <!-- Cron Schedule Info -->
        <div style="background: #f6f7f7; padding: 15px; border-left: 4px solid #646970; margin: 20px 0;">
            <h3 style="margin-top: 0;"><?php _e('Lịch tự động (Cron)', 'tgs-pos-sync'); ?></h3>
            <ul>
                <li><?php _e('Push lên Hub: Mỗi 5 phút', 'tgs-pos-sync'); ?></li>
                <li><?php _e('Pull từ Hub: Mỗi 10 phút', 'tgs-pos-sync'); ?></li>
            </ul>
        </div>

    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    function showResult(message, type) {
        var className = type === 'success' ? 'notice-success' : 'notice-error';
        $('#tgs-sync-result').html('<div class="notice ' + className + ' is-dismissible"><p>' + message + '</p></div>');
    }

    // Push
    $('#tgs-push-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite; vertical-align: middle;"></span> Đang push...');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'tgs_pos_manual_push',
                nonce: '<?php echo wp_create_nonce('tgs_pos_manual_sync'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    showResult('Push thành công! Pushed: ' + data.pushed + ', Applied: ' + data.applied + ', Failed: ' + data.failed, 'success');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    showResult('Lỗi: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-upload" style="vertical-align: middle;"></span> Push lên Hub');
            }
        });
    });

    // Pull
    $('#tgs-pull-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite; vertical-align: middle;"></span> Đang pull...');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'tgs_pos_manual_pull',
                nonce: '<?php echo wp_create_nonce('tgs_pos_manual_sync'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    showResult('Pull thành công! Pulled: ' + data.pulled + ', Applied: ' + data.applied + ', Failed: ' + data.failed, 'success');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    showResult('Lỗi: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-download" style="vertical-align: middle;"></span> Pull từ Hub');
            }
        });
    });

    // Full Sync
    $('#tgs-full-sync-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite; vertical-align: middle;"></span> Đang sync...');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'tgs_pos_manual_full_sync',
                nonce: '<?php echo wp_create_nonce('tgs_pos_manual_sync'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    var push = response.data.push;
                    var pull = response.data.pull;
                    showResult('Full sync thành công!<br>Push: ' + push.pushed + ' events<br>Pull: ' + pull.pulled + ' changes', 'success');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    showResult('Lỗi khi full sync', 'error');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> Full Sync');
            }
        });
    });
});
</script>

<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
