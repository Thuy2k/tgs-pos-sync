<?php
/**
 * Settings Page
 * Trang cài đặt kết nối Hub
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Settings_Page {

    /**
     * Render settings page
     */
    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Bạn không có quyền truy cập trang này.', 'tgs-pos-sync'));
        }

        $is_registered = TGS_POS_Config::is_registered();
        $hub_url = TGS_POS_Config::get_hub_url();
        $store_id = TGS_POS_Config::get_store_id();
        $blog_id = TGS_POS_Config::get_blog_id();

        include TGS_POS_SYNC_PLUGIN_DIR . 'admin/views/settings.php';
    }
}
