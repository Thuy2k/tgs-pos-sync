<?php
/**
 * Settings View
 * Giao diện cài đặt kết nối Hub
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php _e('Cài đặt POS Sync', 'tgs-pos-sync'); ?></h1>

    <?php if ($is_registered): ?>
        <!-- Registered -->
        <div class="notice notice-success" style="padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;">
                <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                <?php _e('Đã kết nối với Hub', 'tgs-pos-sync'); ?>
            </h2>
            <table class="form-table">
                <tr>
                    <th><?php _e('Hub URL:', 'tgs-pos-sync'); ?></th>
                    <td><code><?php echo esc_html($hub_url); ?></code></td>
                </tr>
                <tr>
                    <th><?php _e('Store ID:', 'tgs-pos-sync'); ?></th>
                    <td><strong><?php echo esc_html($store_id); ?></strong></td>
                </tr>
                <tr>
                    <th><?php _e('Blog ID:', 'tgs-pos-sync'); ?></th>
                    <td><strong><?php echo esc_html($blog_id); ?></strong></td>
                </tr>
            </table>

            <p>
                <button type="button" class="button button-secondary" id="tgs-unregister-btn">
                    <?php _e('Ngắt kết nối', 'tgs-pos-sync'); ?>
                </button>
            </p>
        </div>

    <?php else: ?>
        <!-- Not Registered -->
        <div class="notice notice-warning" style="padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0;">
                <span class="dashicons dashicons-warning" style="color: #dba617;"></span>
                <?php _e('Chưa kết nối với Hub', 'tgs-pos-sync'); ?>
            </h2>
            <p><?php _e('Vui lòng quét QR Code từ Hub để đăng ký kết nối.', 'tgs-pos-sync'); ?></p>
        </div>

        <div style="background: #fff; padding: 30px; border: 1px solid #ddd; border-radius: 8px; max-width: 600px;">
            <h2><?php _e('Đăng ký bằng QR Code', 'tgs-pos-sync'); ?></h2>
            <p><?php _e('Nhập dữ liệu JSON từ QR Code hoặc dán trực tiếp từ Hub Admin.', 'tgs-pos-sync'); ?></p>

            <form id="tgs-register-form">
                <table class="form-table">
                    <tr>
                        <th>
                            <label for="qr_data"><?php _e('QR Data (JSON):', 'tgs-pos-sync'); ?></label>
                        </th>
                        <td>
                            <textarea id="qr_data" name="qr_data" rows="8" class="large-text code" placeholder='{"hub_url":"https://hub.tgsworld.vn","setup_token":"abc123...","blog_id":5,"store_id":"PT001"}'></textarea>
                            <p class="description">
                                <?php _e('Dán dữ liệu JSON từ QR Code vào đây.', 'tgs-pos-sync'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button button-primary">
                        <?php _e('Đăng ký', 'tgs-pos-sync'); ?>
                    </button>
                </p>
            </form>

            <div id="tgs-register-result"></div>
        </div>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // Register
    $('#tgs-register-form').on('submit', function(e) {
        e.preventDefault();

        var qrData = $('#qr_data').val().trim();
        var $btn = $(this).find('button[type="submit"]');
        var $result = $('#tgs-register-result');

        if (!qrData) {
            $result.html('<div class="notice notice-error"><p>Vui lòng nhập dữ liệu QR Code</p></div>');
            return;
        }

        $btn.prop('disabled', true).text('Đang đăng ký...');
        $result.html('');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'tgs_pos_qr_register',
                nonce: '<?php echo wp_create_nonce('tgs_pos_qr_register'); ?>',
                qr_data: qrData
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p><strong>Thành công!</strong> Đang tải lại trang...</p></div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $result.html('<div class="notice notice-error"><p><strong>Lỗi:</strong> ' + (response.data || 'Unknown error') + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p><strong>Lỗi:</strong> Không thể kết nối đến Hub</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Đăng ký');
            }
        });
    });

    // Unregister
    $('#tgs-unregister-btn').on('click', function() {
        if (!confirm('Bạn có chắc muốn ngắt kết nối với Hub?')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Đang xử lý...');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'tgs_pos_qr_unregister',
                nonce: '<?php echo wp_create_nonce('tgs_pos_qr_unregister'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert('Đã ngắt kết nối thành công!');
                    location.reload();
                } else {
                    alert('Lỗi: ' + (response.data || 'Unknown error'));
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Ngắt kết nối');
            }
        });
    });
});
</script>
