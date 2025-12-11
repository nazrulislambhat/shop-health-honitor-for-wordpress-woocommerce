<p align="center">
  <img src="https://raw.githubusercontent.com/nazrulislambhat/shop-health-monitor-for-wordpress-woocommerce/main/assets/banner-1544x500.png" alt="Shop Health Monitor Banner" />
</p>

# 🛒 Shop Health Monitor for WooCommerce

### Automatic Product Visibility Monitoring • LiteSpeed Cache Auto-Flush • Instant Alerts

[![WordPress Plugin](https://img.shields.io/badge/WordPress-Plugin-blue.svg)](#)
[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![Status](https://img.shields.io/badge/Status-Active-brightgreen.svg)
![WooCommerce Compatible](https://img.shields.io/badge/WooCommerce-Compatible-purple.svg)

---

## 📌 Overview

**Shop Health Monitor for WooCommerce** is a lightweight but powerful monitoring tool designed to prevent the “0 products showing” / “blank shop” issue often caused by cache corruption or WooCommerce query failures.

The plugin continuously monitors your WooCommerce storefront, alerts you instantly when products disappear, automatically flushes LiteSpeed Cache, and even performs an **immediate recovery check** to verify if the problem resolves instantly.

Perfect for WooCommerce stores hosted on **LiteSpeed**, **Hostinger**, or any setup where product visibility issues occur.

---

## 🚀 Key Features

### 🔍 Product Visibility Monitoring

Continuously checks whether WooCommerce is returning published products.

### ⚠ Instant Failure Alerts

If products suddenly become 0, the plugin sends:

- Email alert
- Optional Slack alert

### ⚡ Automatic LiteSpeed Cache Flush

If a failure is detected, LSCache is purged instantly to fix cache corruption issues.

### 🔁 Immediate Post-Purge Recovery Check

After purging cache, the plugin waits ~5 seconds and checks again:

- If products reappear → sends **Instant Recovery Alert**
- If not → waits for next scheduled check

### 📊 Dashboard Widget

See key health metrics:

- Current status
- Last check time
- Last failure
- Last cache flush
- Manual check button

### 🕒 Scheduled Monitoring

Runs automatically every **15 minutes**.

---

## 🛠 Installation

### **From GitHub**

1. Download the ZIP from the Releases section.
2. Upload to WordPress:  
   `Plugins → Add New → Upload Plugin`
3. Activate the plugin.
4. Optional: Add your Slack webhook under  
   `Settings → Shop Health Monitor`.

### **From Source**

Clone this repository:

```bash
git clone https://github.com/nazrulislambhat/shop-health-monitor-for-wordpress-woocommerce.git

```
