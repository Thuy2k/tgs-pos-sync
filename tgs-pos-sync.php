<?php
/**
 * Plugin Name: TGS POS Sync
 * Plugin URI: https://tgsworld.vn
 * Description: Plugin đồng bộ POS Local-First. Cài trên máy Local Docker, sync với Hub trung tâm.
 * Version: 1.0.0
 * Author: TGS World
 * Author URI: https://tgsworld.vn
 * Text Domain: tgs-pos-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TGS_POS_SYNC_VERSION', '1.0.0');
define('TGS_POS_SYNC_PLUGIN_FILE', __FILE__);
define('TGS_POS_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_POS_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Sync tables
define('TGS_POS_TABLE_OUTBOX', 'tgs_sync_outbox');
define('TGS_POS_TABLE_INBOX', 'tgs_sync_inbox');
define('TGS_POS_TABLE_STATE', 'tgs_sync_state');
define('TGS_POS_TABLE_ID_MAP', 'tgs_id_map');

/**
 * Main plugin class
 */
class TGS_POS_Sync {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once TGS_POS_SYNC_PLUGIN_DIR . 'includes/class-database.php';
        require_once TGS_POS_SYNC_PLUGIN_DIR . 'includes/class-config.php';
        require_once TGS_POS_SYNC_PLUGIN_DIR . 'includes/class-http-client.php';
        require_once TGS_POS_SYNC_PLUGIN_DIR . 'includes/class-qr-scanner.php';
        require_once TGS_POS_SYNC_PLUGIN_DIR . 'includes/class-sync-engine.php';
        require_once TGS_POS_SYNC_PLUGIN_DIR . 'includes/class-push-collector.php';
        require_once TGS_POS_SYNC_PLUGIN_DIR . 'includes/class-pull-applier.php';
        require_once TGS_POS_SYNC_PLUGIN_DIR . 'includes/class-event-logger.php';
        require_once TGS_POS_SYNC_PLUGIN_DIR . 'includes/class-id-mapper.php';

        // Admin classes
        if (is_admin()) {
            require_once TGS_POS_SYNC_PLUGIN_DIR . 'admin/class-settings-page.php';
            require_once TGS_POS_SYNC_PLUGIN_DIR . 'admin/class-sync-status.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Admin menu
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
        }

        // Sync triggers
        add_action('tgs_pos_sync_push', array('TGS_POS_Sync_Engine', 'push_to_hub'));
        add_action('tgs_pos_sync_pull', array('TGS_POS_Sync_Engine', 'pull_from_hub'));

        // Cron schedule
        if (!wp_next_scheduled('tgs_pos_sync_push')) {
            wp_schedule_event(time(), 'every_5_minutes', 'tgs_pos_sync_push');
        }
        if (!wp_next_scheduled('tgs_pos_sync_pull')) {
            wp_schedule_event(time(), 'every_10_minutes', 'tgs_pos_sync_pull');
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        TGS_POS_Database::create_tables();

        // Add custom cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook('tgs_pos_sync_push');
        wp_clear_scheduled_hook('tgs_pos_sync_pull');
        flush_rewrite_rules();
    }

    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_5_minutes'] = array(
            'interval' => 300,
            'display' => __('Every 5 Minutes', 'tgs-pos-sync'),
        );
        $schedules['every_10_minutes'] = array(
            'interval' => 600,
            'display' => __('Every 10 Minutes', 'tgs-pos-sync'),
        );
        return $schedules;
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'tgs-pos-sync',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('POS Sync', 'tgs-pos-sync'),
            __('POS Sync', 'tgs-pos-sync'),
            'manage_options',
            'tgs-pos-sync',
            array('TGS_POS_Settings_Page', 'render'),
            'dashicons-update',
            30
        );

        add_submenu_page(
            'tgs-pos-sync',
            __('Cài đặt', 'tgs-pos-sync'),
            __('Cài đặt', 'tgs-pos-sync'),
            'manage_options',
            'tgs-pos-sync',
            array('TGS_POS_Settings_Page', 'render')
        );

        add_submenu_page(
            'tgs-pos-sync',
            __('Trạng thái Sync', 'tgs-pos-sync'),
            __('Trạng thái Sync', 'tgs-pos-sync'),
            'manage_options',
            'tgs-pos-sync-status',
            array('TGS_POS_Sync_Status', 'render')
        );
    }
}

/**
 * Initialize plugin
 */
function tgs_pos_sync() {
    return TGS_POS_Sync::instance();
}

// Start the plugin
tgs_pos_sync();
