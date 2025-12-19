<?php
/*
Plugin Name: Shop Health Monitor for WooCommerce
Plugin URI: https://nazrulislam.dev/products/shop-health-monitor-woocommerce
Description: Monitors WooCommerce shop health every minute. Performs lightweight checks normally and reacts only when products disappear.
Version: 1.5.1
Author: Nazrul Islam
Author URI: https://nazrulislam.dev/
License: GPLv2 or later
*/

if (!defined('ABSPATH')) exit;

class Woo_Shop_Health_Monitor {

    private $slack_webhook;
    private $check_interval;

    public function __construct() {

        $this->slack_webhook  = get_option('woo_shop_slack_webhook');
        $this->check_interval = 1; // HARD ENFORCED: every minute

        /* CRON */
        add_action('plugins_loaded', [$this, 'register_cron']);
        add_action('woo_shop_monitor_event', [$this, 'run_monitor']);
        add_action('woo_shop_monitor_recovery_event', [$this, 'run_recovery_check']);

        /* Admin / UI */
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        add_action('admin_init',         [$this, 'handle_manual_actions']);
        add_action('admin_menu',         [$this, 'add_settings_page']);
    }

    /* ----------------------------------------------------
     * CRON REGISTRATION (EVERY MINUTE)
     * ---------------------------------------------------- */
    public function register_cron() {

        add_filter('cron_schedules', function ($schedules) {

            $schedules['woo_monitor_every_minute'] = [
                'interval' => 60,
                'display'  => __('Every Minute (Shop Monitor)', 'shop-health-monitor'),
            ];

            return $schedules;
        });

        if (!wp_next_scheduled('woo_shop_monitor_event')) {
            wp_schedule_event(time(), 'woo_monitor_every_minute', 'woo_shop_monitor_event');
        }
    }

    /* ----------------------------------------------------
     * MAIN MONITOR (LIGHTWEIGHT)
     * ---------------------------------------------------- */
    public function run_monitor() {

        if (!class_exists('WooCommerce')) return;

        // Ultra-lightweight check
        $products = wc_get_products([
            'status' => 'publish',
            'limit'  => 1,
        ]);

        $current_status  = empty($products) ? 'empty' : 'ok';
        $previous_status = get_option('woo_shop_status', 'unknown');

        update_option('woo_shop_last_check', wp_date('Y-m-d H:i:s'));
        update_option('woo_shop_status', $current_status);

        /* ----------------------------------
         * FAILURE: OK → EMPTY
         * ---------------------------------- */
        if ($previous_status !== 'empty' && $current_status === 'empty') {

            update_option('woo_shop_last_fail', wp_date('Y-m-d H:i:s'));

            // 🔔 OPTION A: Notify immediately (even if auto-fix works)
            $this->log_incident('failure', 'Zero products detected. Auto-recovery started.');

            wp_mail(
                get_option('admin_email'),
                '⚠ WooCommerce Issue Detected (Auto-Fix Started)',
                "Zero products detected.\nAuto-recovery (cache flush) initiated.\n\nTime: " . wp_date('Y-m-d H:i:s'),
                ['Content-Type: text/plain; charset=UTF-8']
            );

            $this->send_slack_alert(
                "⚠ *WooCommerce Issue Detected*\nProducts missing.\nAuto-recovery started.\n" . home_url()
            );

            // 🧹 Flush cache
            $this->flush_shop_cache();
            update_option('woo_shop_last_flush', wp_date('Y-m-d H:i:s'));

            // 🔥 Schedule NON-BLOCKING recovery check
            if (!wp_next_scheduled('woo_shop_monitor_recovery_event')) {
                wp_schedule_single_event(
                    time() + 10,
                    'woo_shop_monitor_recovery_event'
                );
            }

            return;
        }

        /* ----------------------------------
         * NORMAL RECOVERY (EMPTY → OK)
         * ---------------------------------- */
        if ($previous_status === 'empty' && $current_status === 'ok') {

            $this->log_incident('recovery', 'Recovered on next scheduled check.');

            wp_mail(
                get_option('admin_email'),
                '✅ WooCommerce Shop Recovered',
                "Products are visible again.\nTime: " . wp_date('Y-m-d H:i:s'),
                ['Content-Type: text/plain; charset=UTF-8']
            );

            $this->send_slack_alert(
                "✅ *WooCommerce Shop Recovered*\nProducts are visible again."
            );
        }
    }

    /* ----------------------------------------------------
     * RECOVERY CHECK (RUNS ONLY ON FAILURE)
     * ---------------------------------------------------- */
    public function run_recovery_check() {

        if (!class_exists('WooCommerce')) return;

        if (get_option('woo_shop_status') !== 'empty') {
            return;
        }

        $products = wc_get_products([
            'status' => 'publish',
            'limit'  => 1,
        ]);

        if (!empty($products)) {

            update_option('woo_shop_status', 'ok');
            $this->log_incident('recovery', 'Immediate recovery after cache flush.');

            wp_mail(
                get_option('admin_email'),
                '✅ WooCommerce Shop Recovered Immediately',
                "Products recovered immediately after cache flush.",
                ['Content-Type: text/plain; charset=UTF-8']
            );

            $this->send_slack_alert(
                "✅ *Immediate Recovery*\nProducts visible again after cache purge."
            );
        }
    }

    /* ----------------------------------------------------
     * CACHE FLUSH
     * ---------------------------------------------------- */
    private function flush_shop_cache() {

        $flushed = [];

        if (function_exists('litespeed_purge_all')) {
            litespeed_purge_all();
            $flushed[] = 'LiteSpeed';
        } elseif (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $flushed[] = 'WP Rocket';
        } elseif (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $flushed[] = 'W3 Total Cache';
        } elseif (class_exists('autoptimizeCache')) {
            \autoptimizeCache::clearall();
            $flushed[] = 'Autoptimize';
        } elseif (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $flushed[] = 'WP Super Cache';
        } else {
            wp_cache_flush();
            $flushed[] = 'WP Object Cache';
        }

        $this->log_incident('info', 'Cache flushed: ' . implode(', ', $flushed));
    }

    /* ----------------------------------------------------
     * INCIDENT LOG
     * ---------------------------------------------------- */
    private function log_incident($type, $message) {

        $log = get_option('woo_shop_incident_log', []);

        array_unshift($log, [
            'time'    => wp_date('Y-m-d H:i:s'),
            'type'    => $type,
            'message' => $message
        ]);

        update_option('woo_shop_incident_log', array_slice($log, 0, 20));
    }

    /* ----------------------------------------------------
     * SLACK
     * ---------------------------------------------------- */
    private function send_slack_alert($message) {

        if (!$this->slack_webhook) return;

        wp_remote_post($this->slack_webhook, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['text' => $message]),
        ]);
    }

    /* ----------------------------------------------------
     * DASHBOARD WIDGET
     * ---------------------------------------------------- */
    public function add_dashboard_widget() {

        wp_add_dashboard_widget(
            'woo_shop_monitor_widget',
            '🛒 Woo Shop Health Monitor',
            [$this, 'render_dashboard_widget']
        );
    }

    public function render_dashboard_widget() {

        $status     = get_option('woo_shop_status', 'unknown');
        $last_check = get_option('woo_shop_last_check', 'Never');
        $log        = get_option('woo_shop_incident_log', []);

        echo "<p><strong>Status:</strong> {$status}</p>";
        echo "<p><strong>Check Interval:</strong> Every minute</p>";
        echo "<p><strong>Last Check:</strong> {$last_check}</p>";

        if (!empty($log)) {
            echo '<hr><strong>Recent Events:</strong><ul>';
            foreach (array_slice($log, 0, 3) as $entry) {
                echo "<li>[{$entry['time']}] <strong>{$entry['type']}:</strong> {$entry['message']}</li>";
            }
            echo '</ul>';
        }

        echo '<p>
            <a class="button button-primary" href="' . admin_url('?woo_manual_shop_check=1') . '">🧪 Run Check</a>
            <a class="button" style="margin-left:5px;color:#d63638;" href="' . admin_url('?woo_manual_test_alerts=1') . '">⚡ Test Alerts</a>
        </p>';
    }

    /* ----------------------------------------------------
     * MANUAL ACTIONS
     * ---------------------------------------------------- */
    public function handle_manual_actions() {

        if (!current_user_can('manage_options')) return;

        if (isset($_GET['woo_manual_shop_check'])) {
            do_action('woo_shop_monitor_event');
            wp_die('Manual check completed.<br><a href="' . admin_url() . '">Back</a>');
        }

        if (isset($_GET['woo_manual_test_alerts'])) {

            $this->log_incident('test', 'Manual test alert triggered.');
            $this->flush_shop_cache();

            wp_mail(
                get_option('admin_email'),
                '[TEST] Shop Monitor Alert',
                'This is a manual test alert.',
                ['Content-Type: text/plain; charset=UTF-8']
            );

            $this->send_slack_alert(
                "🧪 *TEST ALERT*\nManual test alert triggered."
            );

            wp_die('Test alert sent.<br><a href="' . admin_url() . '">Back</a>');
        }
    }

    /* ----------------------------------------------------
     * SETTINGS PAGE
     * ---------------------------------------------------- */
    public function add_settings_page() {

        add_options_page(
            'Woo Shop Monitor',
            'Woo Shop Monitor',
            'manage_options',
            'woo-shop-monitor',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {

        if (isset($_POST['save'])) {

            check_admin_referer('woo_shop_monitor');
            update_option('woo_shop_slack_webhook', sanitize_text_field($_POST['webhook']));

            echo '<div class="updated"><p>Settings saved</p></div>';
        }

        $webhook = get_option('woo_shop_slack_webhook', '');
        $log     = get_option('woo_shop_incident_log', []);

        echo '<div class="wrap"><h1>Woo Shop Health Monitor</h1>
        <form method="post">';
        wp_nonce_field('woo_shop_monitor');
        echo '
        <table class="form-table">
            <tr>
                <th>Slack Webhook</th>
                <td><input type="text" name="webhook" value="' . esc_attr($webhook) . '" class="large-text"></td>
            </tr>
        </table>
        <p><button name="save" class="button-primary">Save</button></p>
        </form>';

        echo '<hr><h2>Incident History</h2>';

        if (empty($log)) {
            echo '<p>No incidents recorded.</p>';
        } else {
            echo '<table class="widefat striped">
                <thead><tr><th>Time</th><th>Type</th><th>Message</th></tr></thead><tbody>';
            foreach ($log as $entry) {
                echo "<tr>
                    <td>{$entry['time']}</td>
                    <td>{$entry['type']}</td>
                    <td>{$entry['message']}</td>
                </tr>";
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}

new Woo_Shop_Health_Monitor();
