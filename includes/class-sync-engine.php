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
     * Push to Hub (triggered by cron or manual)
     */
    public static function push_to_hub() {
        $result = TGS_POS_Push_Collector::push();

        // Log result
        error_log('[TGS POS Sync] Push: ' . json_encode($result));

        return $result;
    }

    /**
     * Pull from Hub (triggered by cron or manual)
     */
    public static function pull_from_hub() {
        $result = TGS_POS_Pull_Applier::pull();

        // Log result
        error_log('[TGS POS Sync] Pull: ' . json_encode($result));

        return $result;
    }

    /**
     * Full sync (push + pull)
     */
    public static function full_sync() {
        $push_result = self::push_to_hub();
        $pull_result = self::pull_from_hub();

        return array(
            'push' => $push_result,
            'pull' => $pull_result,
        );
    }

    /**
     * Manual trigger push (AJAX)
     */
    public static function ajax_manual_push() {
        check_ajax_referer('tgs_pos_manual_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $result = self::push_to_hub();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Manual trigger pull (AJAX)
     */
    public static function ajax_manual_pull() {
        check_ajax_referer('tgs_pos_manual_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $result = self::pull_from_hub();

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

        $result = self::full_sync();

        wp_send_json_success($result);
    }
}

// Register AJAX handlers
add_action('wp_ajax_tgs_pos_manual_push', array('TGS_POS_Sync_Engine', 'ajax_manual_push'));
add_action('wp_ajax_tgs_pos_manual_pull', array('TGS_POS_Sync_Engine', 'ajax_manual_pull'));
add_action('wp_ajax_tgs_pos_manual_full_sync', array('TGS_POS_Sync_Engine', 'ajax_manual_full_sync'));
