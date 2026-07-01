<?php
/**
 * Sync Status Page
 * Trang theo dõi trạng thái sync
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Sync_Status {

    /**
     * Render status page
     */
    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Bạn không có quyền truy cập trang này.', 'tgs-pos-sync'));
        }

        $is_registered = TGS_POS_Config::is_registered();
        $outbox_stats = TGS_POS_Event_Logger::get_stats();
        // Inbox không dùng nữa - pull trực tiếp UPSERT
        $inbox_stats = array('total' => 0, 'pending' => 0, 'applied' => 0, 'errors' => 0);
        $last_push = TGS_POS_Config::get('last_push_at');
        $last_pull = TGS_POS_Config::get('last_pull_at');

        include TGS_POS_SYNC_PLUGIN_DIR . 'admin/views/status.php';
    }
}
