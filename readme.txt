=== Shop Health Monitor for WooCommerce ===
Contributors: Nazrul Islam
Tags: woocommerce, cache, litespeed, visibility, uptime, monitoring, email alerts, slack alerts
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==

A lightweight yet powerful WooCommerce monitoring plugin that continuously checks your store’s product visibility and cache health.  
If your shop suddenly displays **zero products**, this plugin can instantly:

⚠ Send *Failure Alerts* (Email + optional Slack)  
⚡ Auto-Flush Cache (LiteSpeed, WP Rocket, W3TC, Autoptimize, WP Super Cache)  
🧠 Immediately Recheck the Store  
✅ Send *Instant Recovery Alerts* if products return  
📊 Log incident history (failures, recoveries, cache flushes)  
🛠 Add a dedicated WooCommerce Health widget in Dashboard  

Perfect for preventing the infamous “blank shop / no products” issue caused by cache corruption or host-side glitches.

== Features ==

* Detects when WooCommerce returns 0 products
* **Auto-flushes Cache** on failure (Supports LiteSpeed, WP Rocket, W3TC, Autoptimize, WP Super Cache)
* **Incident History Log**: View recent failures and recoveries
* **Configurable Schedule**: Set check interval (default 15 mins)
* Sends email alerts instantly on failure
* Sends Slack alerts (optional)
* Immediate recovery check after purge
* Sends recovery email & Slack alert instantly when products reappear
* Dashboard widget showing:
  - Current store health & last check time
  - Recent incident log
* **Test Alerts Button**: Manually trigger flush & alerts to verify system
* Manual check button
* Zero configuration required (but customizable)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via WordPress plugin installer.
2. Activate the plugin.
3. (Optional) Go to **Settings → Shop Health Monitor** to configure check interval and Slack Webhook.
4. That’s it — monitoring begins automatically.

== Frequently Asked Questions ==

= How do I test if alerts are working? =
Use the **"⚡ Test Alerts"** button in the Dashboard Widget. This will:
1. Force a cache flush
2. Send a test email to the admin
3. Send a test notification to Slack
4. Log a test entry in the history

= Which cache plugins are supported? =
We currently support auto-flushing for:
- LiteSpeed Cache
- WP Rocket
- W3 Total Cache
- Autoptimize
- WP Super Cache
- Default WP Object Cache (fallback)

= Will it spam me? =
No. Alerts are only sent when:
- **Products disappear** (failure)
- **Products reappear** (recovery)
Never during stable conditions.

= Is this plugin heavy? =
Not at all. It performs a very lightweight WooCommerce query at your configured interval (default 15m).

== Screenshots ==

1. Dashboard widget with Incident Log
2. Settings page (Interval & Slack)
3. Example email alert

== Changelog ==

= 1.4 =
* Added "Test Alerts" button to Settings Page for manual verification (Flush + Email + Slack)

= 1.3 =
* Added support for multiple cache plugins (WP Rocket, W3TC, Autoptimize, WP Super Cache)
* Added Configurable Check Interval
* Added Incident History Logging
* Updated Dashboard Widget to show recent events
* Updated Settings Page

= 1.2 =
* Added Immediate Recovery Check (instantly checks product visibility after cache purge)
* Improved failure detection logic
* Enhanced stability when WooCommerce is disabled

= 1.1 =
* Initial public release

== Upgrade Notice ==
Major update: Now supports multiple cache plugins, configurable schedules, and incident logging.
