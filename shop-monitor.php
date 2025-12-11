<?php
/*
Plugin Name: Shop Health Monitor for WooCommerce
Plugin URI: https://nazrulislam.dev/products/shop-health-monitor-woocommerce
Description: Monitors WooCommerce shop health, auto-detects failures, auto-flushes LiteSpeed cache once per incident, sends email & Slack alerts, includes dashboard widget, runs every 15 minutes.
Version: 1.4
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
        $this->check_interval = (int) get_option('woo_shop_check_interval', 15);
        if ($this->check_interval < 1) $this->check_interval = 15;

        add_filter('cron_schedules', [$this, 'add_custom_cron']);
        add_action('init',           [$this, 'schedule_checker']);
        add_action('woo_shop_monitor_event', [$this, 'run_monitor']);
        add_action('wp_dashboard_setup',     [$this, 'add_dashboard_widget']);
        add_action('admin_init',             [$this, 'handle_manual_check']);
        add_action('admin_menu',             [$this, 'add_settings_page']);
        
        // Handle schedule change
        add_action('update_option_woo_shop_check_interval', [$this, 'reschedule_event'], 10, 2);
    }

    // 1. Add Configurable cron schedule
    public function add_custom_cron($schedules) {
        $msg = sprintf(__('Every %d Minutes'), $this->check_interval);
        $schedules['woo_monitor_interval'] = [
            'interval' => $this->check_interval * 60,
            'display'  => $msg
        ];
        return $schedules;
    }

    // 2. Schedule monitor event
    public function schedule_checker() {
        if (!wp_next_scheduled('woo_shop_monitor_event')) {
            wp_schedule_event(time(), 'woo_monitor_interval', 'woo_shop_monitor_event');
        }
    }

    // Reschedule if interval changes
    public function reschedule_event($old_value, $new_value) {
        if ($old_value != $new_value) {
            wp_clear_scheduled_hook('woo_shop_monitor_event');
            wp_schedule_event(time(), 'woo_monitor_interval', 'woo_shop_monitor_event');
        }
    }

    // -------------------------
    // 3. MAIN MONITOR LOGIC
    // -------------------------
    public function run_monitor() {

        if (!class_exists('WooCommerce')) return;

        $products = wc_get_products([
            'status' => 'publish',
            'limit'  => 1,
        ]);

        $current_status  = empty($products) ? 'empty' : 'ok';
        $previous_status = get_option('woo_shop_status', 'unknown');

        update_option('woo_shop_last_check', wp_date('Y-m-d H:i:s'));
        update_option('woo_shop_status', $current_status);

        // ----------------------------------------------------------
        // FAILURE: OK → EMPTY
        // ----------------------------------------------------------
        if ($previous_status !== 'empty' && $current_status === 'empty') {

            update_option('woo_shop_last_fail', wp_date('Y-m-d H:i:s'));
            $this->log_incident('failure', 'Zero products detected.');

            // Flush Cache (Multi-support)
            $this->flush_shop_cache();
            update_option('woo_shop_last_flush', wp_date('Y-m-d H:i:s'));

            // Failure Email
            wp_mail(
                get_option('admin_email'),
                '⚠ WooCommerce Shop Failure – Cache Auto-Flushed',
                "Zero products detected.\nCache reset.\n\nTime: " . wp_date('Y-m-d H:i:s') . "\nSite: " . home_url(),
                ['Content-Type: text/plain; charset=UTF-8']
            );

            // Slack Failure Alert
            $this->send_slack_alert(
                "⚠ *WooCommerce Failure Detected*\nZero products returned.\n*Cache auto-flushed.*\nSite: " . home_url()
            );

            // ----------------------------------------------------------
            // 🔥 NEW FEATURE: IMMEDIATE RECOVERY CHECK
            // ----------------------------------------------------------

            sleep(5); // allow cache a moment to refresh

            $products_after = wc_get_products([
                'status' => 'publish',
                'limit'  => 1,
            ]);

            if (!empty($products_after)) {
                // Products recovered immediately after cache flush

                update_option('woo_shop_status', 'ok');
                $this->log_incident('recovery', 'Immediate recovery after flush.');

                wp_mail(
                    get_option('admin_email'),
                    '✅ WooCommerce Shop Recovered Immediately',
                    "Products became visible immediately after cache purge.\nTime: " . wp_date('Y-m-d H:i:s'),
                    ['Content-Type: text/plain; charset=UTF-8']
                );

                // Slack recovery
                $this->send_slack_alert(
                    "✅ *Immediate Recovery Detected*\nProducts are visible again right after cache purge."
                );

                return;
            }

            // If not recovered yet → keep status empty and wait for next cron
            update_option('woo_shop_status', 'empty');
            return;
        }

        // ----------------------------------------------------------
        // RECOVERY: EMPTY → OK (normal recovery via cron)
        // ----------------------------------------------------------
        if ($previous_status === 'empty' && $current_status === 'ok') {

            $this->log_incident('recovery', 'Recovered on next scheduled check.');

            wp_mail(
                get_option('admin_email'),
                '✅ WooCommerce Shop Recovered',
                "Products are visible again.\nRecovery time: " . wp_date('Y-m-d H:i:s'),
                ['Content-Type: text/plain; charset=UTF-8']
            );

            // Slack recovery alert
            $this->send_slack_alert(
                "✅ *WooCommerce Shop Recovered*\nProducts are visible again on: " . home_url()
            );

            return;
        }
    }

    // -------------------------
    // 3.5 Cache Flushing (Multi-Plugin)
    // -------------------------
    private function flush_shop_cache() {
        $flushed = [];

        // 1. LiteSpeed Cache
        if (function_exists('litespeed_purge_all')) {
            litespeed_purge_all();
            $flushed[] = 'LiteSpeed';
        } elseif (class_exists('LiteSpeed\Purge')) {
            do_action('litespeed_purge_all');
            $flushed[] = 'LiteSpeed (Action)';
        }

        // 2. WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $flushed[] = 'WP Rocket';
        }

        // 3. W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $flushed[] = 'W3 Total Cache';
        }

        // 4. Autoptimize
        if (class_exists('autoptimizeCache')) {
            \autoptimizeCache::clearall();
            $flushed[] = 'Autoptimize';
        }

        // 5. WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $flushed[] = 'WP Super Cache';
        }

        if (empty($flushed)) {
             // Fallback standard WP Object Cache
             wp_cache_flush();
             $flushed[] = 'WP Object Cache';
        }
        
        $this->log_incident('info', 'Cache flushed: ' . implode(', ', $flushed));
    }

    // -------------------------
    // 3.6 Incident Logging
    // -------------------------
    private function log_incident($type, $message) {
        $log = get_option('woo_shop_incident_log', []);
        
        // Add new entry
        array_unshift($log, [
            'time'    => wp_date('Y-m-d H:i:s'),
            'type'    => $type,
            'message' => $message
        ]);

        // Keep last 20
        $log = array_slice($log, 0, 20);
        update_option('woo_shop_incident_log', $log);
    }

    // -------------------------
    // 4. Slack Notification
    // -------------------------
    private function send_slack_alert($message) {
        if (!$this->slack_webhook) return;

        wp_remote_post($this->slack_webhook, [
            'headers' => ['Content-Type: application/json'],
            'body'    => json_encode(["text" => $message])
        ]);
    }

    // -------------------------
    // 5. Dashboard Widget
    // -------------------------
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
        $last_fail  = get_option('woo_shop_last_fail', 'Never');
        $interval   = $this->check_interval;

        $status_label = match ($status) {
            'ok'    => '<span style="color: green; font-weight: bold;">🟢 OK — Products Found</span>',
            'empty' => '<span style="color: red; font-weight: bold;">🔴 EMPTY — No Products</span>',
            default => '<span style="color:#666;">⚪ No Data</span>',
        };

        echo "<p><strong>Status:</strong> $status_label</p>";
        echo "<p><strong>Check Interval:</strong> Every $interval Minutes</p>";
        echo "<p><strong>Last Check:</strong> $last_check</p>";
        
        // Mini Log
        $log = get_option('woo_shop_incident_log', []);
        if (!empty($log)) {
            echo '<div style="margin-top:10px; border-top:1px solid #eee; padding-top:5px;"><strong>Recent Events:</strong><ul style="font-size:11px; color:#666; margin-left: 15px; list-style-type: disc;">';
            foreach (array_slice($log, 0, 3) as $entry) {
                echo "<li>[{$entry['time']}] <strong>" . ucfirst($entry['type']) . ":</strong> {$entry['message']}</li>";
            }
            echo '</ul></div>';
        }

        echo '<p style="margin-top:10px;">
            <a href="' . admin_url('?woo_manual_shop_check=1') . '" class="button button-primary" style="margin-right:5px;">🧪 Run Check</a>
            <a href="' . admin_url('?woo_manual_test_alerts=1') . '" class="button" style="margin-right:5px; border-color: #d63638; color: #d63638;box-shadow:none;">⚡ Test Alerts</a>
            <a href="' . admin_url('options-general.php?page=woo-shop-monitor') . '" class="button">Settings</a>
        </p>';
    }

    // -------------------------
    // 6. Manual Check Button
    // -------------------------
    // -------------------------
    // 6. Manual Check & Test Button
    // -------------------------
    public function handle_manual_check() {
        if (!current_user_can('manage_options')) return;

        // 6a. Standard Manual Check (Check Only)
        if (isset($_GET['woo_manual_shop_check'])) {
            $products = wc_get_products([
                'status' => 'publish',
                'limit'  => 1
            ]);

            $status = empty($products) ? 'empty' : 'ok';
            $prev_status = get_option('woo_shop_status');

            update_option('woo_shop_status', $status);
            update_option('woo_shop_last_check', wp_date('Y-m-d H:i:s'));
            
            $msg = "Manual check completed. Status: <strong>$status</strong>.";
            if ($status === 'empty' && $prev_status !== 'empty') {
                 $this->log_incident('failure', 'Manual check detected zero products.');
                 $this->flush_shop_cache();
                 $msg .= " Cache flushed.";
            }

            wp_die("$msg<br><br><a href='" . admin_url() . "'>Return to Dashboard</a>");
        }

        // 6b. Test Alerts (Force Flush + Notifications)
        if (isset($_GET['woo_manual_test_alerts'])) {
            
            // 1. Log
            $this->log_incident('test', 'User manually triggered a test alert.');

            // 2. Flush
            $this->flush_shop_cache();
            update_option('woo_shop_last_flush', wp_date('Y-m-d H:i:s'));

            // 3. Email
            wp_mail(
                get_option('admin_email'),
                '[TEST] Shop Monitor Failure Alert',
                "This is a TEST alert triggered manually.\n\nCache was flushed.\nTime: " . wp_date('Y-m-d H:i:s'),
                ['Content-Type: text/plain; charset=UTF-8']
            );

            // 4. Slack
            $this->send_slack_alert(
                "🧪 *TEST ALERT: Shop Monitor*\nThis is a manual test of the notification system.\nCache flushed on: " . home_url()
            );

            wp_die("✅ <strong>Test Completed Successfully!</strong><br>
            - Incident Logged<br>
            - Cache Flushed<br>
            - Email Sent to " . get_option('admin_email') . "<br>
            - Slack Alert Sent (if configured)<br><br>
            <a href='" . admin_url() . "'>Return to Dashboard</a>");
        }
    }

    // -------------------------
    // 7. Settings Page
    // -------------------------
    public function add_settings_page() {
        add_options_page(
            'Woo Shop Monitor Settings',
            'Woo Shop Monitor',
            'manage_options',
            'woo-shop-monitor',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {

        if (isset($_POST['woo_shop_save_settings'])) {
            check_admin_referer('woo_shop_monitor_save');
            
            // Save Webhook
            if (isset($_POST['woo_shop_slack_webhook'])) {
                update_option('woo_shop_slack_webhook', sanitize_text_field($_POST['woo_shop_slack_webhook']));
            }

            // Save Interval
            if (isset($_POST['woo_shop_check_interval'])) {
                $new_interval = (int) $_POST['woo_shop_check_interval'];
                if ($new_interval < 1) $new_interval = 15;
                update_option('woo_shop_check_interval', $new_interval);
                $this->check_interval = $new_interval; // update local instance
            }

            echo '<div class="updated"><p>Settings saved!</p></div>';
        }

        $webhook  = get_option('woo_shop_slack_webhook');
        $interval = get_option('woo_shop_check_interval', 15);
        $log      = get_option('woo_shop_incident_log', []);

        echo '<div class="wrap"><h1>Woo Shop Health Monitor Settings</h1>';
        
        echo '<form method="post">
            ' . wp_nonce_field('woo_shop_monitor_save', '_wpnonce', true, false) . '
            
            <table class="form-table">
                <tr>
                    <th scope="row">Check Interval (Minutes)</th>
                    <td>
                        <input type="number" name="woo_shop_check_interval" value="' . esc_attr($interval) . '" min="1" style="width: 80px;">
                        <p class="description">How often to check if products exists (Default: 15).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Slack Webhook URL</th>
                    <td>
                        <input type="text" name="woo_shop_slack_webhook" value="' . esc_attr($webhook) . '" class="regular-text">
                        <p class="description">Optional: Enter Slack Incoming Webhook URL to receive alerts.</p>
                    </td>
                </tr>
            </table>

            <p><button name="woo_shop_save_settings" class="button button-primary">Save Settings</button></p>
        </form>';

        // Incident History
        echo '<hr>';
        echo '<h2>Incident History</h2>';
        if (empty($log)) {
            echo '<p>No incidents recorded yet.</p>';
        } else {
            echo '<table class="widefat fixed striped" style="max-width: 800px;">';
            echo '<thead><tr><th>Time</th><th>Type</th><th>Message</th></tr></thead>';
            echo '<tbody>';
            foreach ($log as $entry) {
                $color = match($entry['type']) {
                    'failure' => 'red',
                    'recovery' => 'green',
                    default => '#666'
                };
                echo "<tr>
                    <td>{$entry['time']}</td>
                    <td style='color:$color; font-weight:bold;'>" . ucfirst($entry['type']) . "</td>
                    <td>{$entry['message']}</td>
                </tr>";
            }
            echo '</tbody></table>';
        }
        
        echo '</div>';
    }
}

new Woo_Shop_Health_Monitor();
