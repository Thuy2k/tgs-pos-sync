<?php
/**
 * Sync Engine
 * Điều phối Push và Pull
 *
 * @package TGS_POS_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_POS_Sync_Engine {

    /**
     * Push events to Hub and sync LOCAL data back
     * Push events → Hub accepts → Delete local → Pull LOCAL from Hub
     * (triggered by cron or manual button "Đẩy lên & Kéo về LOCAL")
     */
    public static function push_and_sync_local() {
        // Push events to Hub
        $push_result = TGS_POS_Push_Collector::push();

        // Pull local ledger data back from Hub (not schema)
        $pull_result = TGS_POS_Pull_Handler::pull_local_tables();

        // Log result
        error_log('[TGS POS Sync] Push + Pull LOCAL: ' . json_encode(array(
            'push' => $push_result,
            'pull' => $pull_result,
        )));

        return array(
            'push' => $push_result,
            'pull' => $pull_result,
        );
    }

    /**
     * Pull GLOBAL data from Hub (categories, products, policies, lots)
     * (triggered by cron or manual button "Pull từ Hub GLOBAL")
     */
    public static function pull_global_data() {
        $result = TGS_POS_Schema_Manager::pull_and_apply();

        // Log result
        error_log('[TGS POS Sync] Pull GLOBAL: ' . json_encode($result));

        return $result;
    }

    /**
     * DEPRECATED: Use push_and_sync_local() instead
     */
    public static function push_to_hub() {
        return self::push_and_sync_local();
    }

    /**
     * DEPRECATED: Use pull_global_data() instead
     */
    public static function pull_from_hub() {
        return self::pull_global_data();
    }

    /**
     * DEPRECATED: Use push_and_sync_local() instead
     */
    public static function full_sync() {
        return self::push_and_sync_local();
    }

    /**
     * Manual trigger push + sync LOCAL (AJAX)
     */
    public static function ajax_manual_push() {
        check_ajax_referer('tgs_pos_manual_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $result = self::push_and_sync_local();

        if ($result['push']['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['push']['message']);
        }
    }

    /**
     * Manual trigger pull GLOBAL (AJAX)
     */
    public static function ajax_manual_pull() {
        check_ajax_referer('tgs_pos_manual_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $result = self::pull_global_data();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Manual trigger full sync (AJAX)
     */
    public static function ajax_manual_full_sync() {
        check_ajax_referer('tgs_pos_manual_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $result = self::push_and_sync_local();

        wp_send_json_success($result);
    }
}

// Register AJAX handlers
add_action('wp_ajax_tgs_pos_manual_push', array('TGS_POS_Sync_Engine', 'ajax_manual_push'));
add_action('wp_ajax_tgs_pos_manual_pull', array('TGS_POS_Sync_Engine', 'ajax_manual_pull'));
add_action('wp_ajax_tgs_pos_manual_full_sync', array('TGS_POS_Sync_Engine', 'ajax_manual_full_sync'));
