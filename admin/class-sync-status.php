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
        $last_push = TGS_POS_Config::get('last_push_at');
        $last_pull = TGS_POS_Config::get('last_pull_global_data_at');

        // Pull stats - đếm records trong bảng GLOBAL
        global $wpdb;
        $pull_stats = array(
            'categories' => $wpdb->get_var("SELECT COUNT(*) FROM wp_global_product_cat WHERE deleted_at IS NULL"),
            'products' => $wpdb->get_var("SELECT COUNT(*) FROM wp_global_product_name WHERE deleted_at IS NULL"),
            'policies' => $wpdb->get_var("SELECT COUNT(*) FROM wp_global_selling_policy WHERE deleted_at IS NULL"),
            'lots' => $wpdb->get_var("SELECT COUNT(*) FROM wp_global_product_lots WHERE deleted_at IS NULL"),
        );

        // Local stats - đếm data của shop này
        $local_stats = array(
            'customers' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}local_ledger_person WHERE deleted_at IS NULL"),
            'orders' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}local_ledger WHERE deleted_at IS NULL"),
            'order_items' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}local_ledger_item"),
        );

        include TGS_POS_SYNC_PLUGIN_DIR . 'admin/views/status.php';
    }
}
