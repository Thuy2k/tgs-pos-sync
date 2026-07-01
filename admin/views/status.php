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
                <button type="button" class="button button-primary" id="tgs-full-sync-btn">
                    <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                    <?php _e('Kéo về & Đẩy lên (LOCAL)', 'tgs-pos-sync'); ?>
                </button>
                <button type="button" class="button button-primary" id="tgs-pull-btn">
                    <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                    <?php _e('Pull từ Hub (GLOBAL)', 'tgs-pos-sync'); ?>
                </button>
            </p>
            <div id="tgs-sync-result"></div>
        </div>

        <!-- Sync Stats -->
        <h2><?php _e('Thống kê Sync', 'tgs-pos-sync'); ?></h2>

        <div style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
            <!-- Outbox -->
            <div style="flex: 1; min-width: 300px; background: #fff; padding: 20px; border-left: 4px solid #2271b1;">
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

            <!-- Data GLOBAL từ Hub -->
            <div style="flex: 1; min-width: 300px; background: #fff; padding: 20px; border-left: 4px solid #00a32a;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-download"></span>
                    <?php _e('Data từ Hub (GLOBAL)', 'tgs-pos-sync'); ?>
                </h3>
                <table class="widefat">
                    <tr>
                        <th><?php _e('Categories:', 'tgs-pos-sync'); ?></th>
                        <td><strong><?php echo number_format($pull_stats['categories']); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php _e('Products:', 'tgs-pos-sync'); ?></th>
                        <td><strong><?php echo number_format($pull_stats['products']); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php _e('Policies:', 'tgs-pos-sync'); ?></th>
                        <td><strong><?php echo number_format($pull_stats['policies']); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php _e('Lots:', 'tgs-pos-sync'); ?></th>
                        <td><strong><?php echo number_format($pull_stats['lots']); ?></strong></td>
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

            <!-- Data LOCAL của Shop -->
            <div style="flex: 1; min-width: 300px; background: #fff; padding: 20px; border-left: 4px solid #d63638;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-store"></span>
                    <?php _e('Data Shop', 'tgs-pos-sync'); ?>
                </h3>
                <table class="widefat">
                    <tr>
                        <th><?php _e('Khách hàng:', 'tgs-pos-sync'); ?></th>
                        <td><strong><?php echo number_format($local_stats['customers']); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php _e('Đơn hàng:', 'tgs-pos-sync'); ?></th>
                        <td><strong><?php echo number_format($local_stats['orders']); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php _e('Chi tiết items:', 'tgs-pos-sync'); ?></th>
                        <td><strong><?php echo number_format($local_stats['order_items']); ?></strong></td>
                    </tr>
                </table>
                <p style="margin-top: 15px; color: #646970; font-size: 13px;">
                    <em><?php _e('Kể cả offline và chính thức online', 'tgs-pos-sync'); ?></em>
                </p>
            </div>
        </div>

        <!-- Cron Schedule Info -->
        <div style="background: #f6f7f7; padding: 15px; border-left: 4px solid #646970; margin: 20px 0;">
            <h3 style="margin-top: 0;"><?php _e('Lịch tự động (Cron)', 'tgs-pos-sync'); ?></h3>
            <ul>
                <li><?php _e('Kéo về & Đẩy lên LOCAL: Mỗi 5 phút (Pull local tables → Push events)', 'tgs-pos-sync'); ?></li>
                <li><?php _e('Pull GLOBAL từ Hub: Mỗi 10 phút (Categories, Products, Policies, Lots)', 'tgs-pos-sync'); ?></li>
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

    // Pull GLOBAL data
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
                    var data = response.data.data || response.data;
                    var upserted = data.global_data_upserted || {};

                    var total = 0;
                    if (upserted.categories) total += (upserted.categories.inserted || 0) + (upserted.categories.updated || 0);
                    if (upserted.products) total += (upserted.products.inserted || 0) + (upserted.products.updated || 0);
                    if (upserted.policies) total += (upserted.policies.inserted || 0) + (upserted.policies.updated || 0);
                    if (upserted.lots) total += (upserted.lots.inserted || 0) + (upserted.lots.updated || 0);

                    var batches = data.batch_count || 0;

                    if (total > 0) {
                        showResult('Pull GLOBAL thành công! ' + total + ' records trong ' + batches + ' batch(es)', 'success');
                    } else {
                        showResult('Pull GLOBAL thành công! Không có thay đổi mới từ Hub.', 'success');
                    }
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    showResult('Lỗi: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-download" style="vertical-align: middle;"></span> Pull từ Hub (GLOBAL)');
            }
        });
    });

    // Kéo về & Đẩy lên (LOCAL) - Pull local tables + Push
    $('#tgs-full-sync-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite; vertical-align: middle;"></span> Đang sync LOCAL...');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'tgs_pos_manual_push', // Push action already includes pull local
                nonce: '<?php echo wp_create_nonce('tgs_pos_manual_sync'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var pulled = data.pulled || 0;
                    var conflicts = data.conflicts_resolved || 0;
                    var pushed = data.pushed || 0;
                    var accepted = (data.applied && data.applied.length) || 0;

                    var msg = 'Sync LOCAL thành công! ';
                    if (pulled > 0) msg += 'Kéo về: ' + pulled + ' records. ';
                    if (conflicts > 0) msg += 'Giải quyết: ' + conflicts + ' conflicts. ';
                    msg += 'Đẩy lên: ' + pushed + ' events, Accepted: ' + accepted + '.';

                    showResult(msg, 'success');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    showResult('Lỗi: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> Kéo về & Đẩy lên (LOCAL)');
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
