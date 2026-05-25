=== WP-Memory-Usage ===
Contributors: berkux
Tags: memory, usage, server, php, admin
Requires at least: 5.3
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 2.3.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Monitor PHP memory usage, set alert thresholds, and diagnose your server configuration — directly inside WordPress admin.

== Description ==

WP-Memory-Usage is a lightweight but powerful WordPress plugin that monitors and displays memory usage directly in your WordPress admin area.
It is essential for site administrators and developers who need to keep an eye on memory consumption, identify bottlenecks, and act before users are affected.

**What the plugin shows:**

In the **admin footer** (every admin page):
* Current memory usage vs. WordPress limit (with percentage)
* Current memory usage vs. PHP limit (with percentage)
* Server IP address and server name
* PHP version

In the **Dashboard widget** ("Memory Overview"):
* PHP version, architecture (32/64 bit), max execution time
* WordPress memory limit (WP_MEMORY_LIMIT), WordPress admin limit (WP_MAX_MEMORY_LIMIT), PHP memory limit
* Current peak memory usage with a visual progress bar (colour-coded: green / orange / red)
* Latest digest status summary (warn / danger / critical badges)
* Link to the Threshold Alerts settings page

**Settings & Monitor — Tabs (since 2.0.0):**

* **Settings** — thresholds, logging options, email alerts, log rotation
* **History** — latest logged requests with full context (URL, type, user, memory)
* **Digest** — aggregated summaries of past log intervals; browse, merge, and delete digest files
* **️Actions** — practical guidance on what to do when you receive a memory alert
* **Diagnose** *(new in 2.1.0)* — full PHP/WordPress configuration snapshot with a ready-to-paste AI prompt for analysis
* **Memory Thresholds** — current limits, threshold assessment, and recommendations
* **Check Installation** — verifies that the plugin can run correctly on your server

== Frequently Asked Questions ==

= Where is the memory usage displayed? =
In three places: the WordPress dashboard widget "Memory Overview", the admin footer on every backend page, and in detail across the plugin's settings tabs.

= What information does the plugin display? =
PHP version, operating system architecture, WordPress and PHP memory limits, current peak memory usage (in MB and as a percentage), server IP address, and PHP max execution time.

= How can I find out which request consumes the most memory? =
Enable logging in the Settings tab. The History tab shows individual requests with their memory usage and context. The Digest tab aggregates this data and highlights the top memory-consuming URLs. Temporarily deactivating plugins is a common next step to isolate the cause.

= What is the Diagnose tab? =
The Diagnose tab (new in 2.1.0) collects a comprehensive snapshot of your PHP and WordPress memory configuration — OPcache status, garbage collector, loaded extensions, php.ini settings, and more. It generates a ready-made prompt you can copy and paste directly into an AI assistant (ChatGPT, Claude, etc.) for instant analysis.

= Does the plugin work with WordPress Multisite? =
Yes.

= What happens when I delete the plugin? =
A clean uninstall removes all stored options, scheduled cron jobs, database-tables, and log files. Nothing is left behind.

== Why Use WP-Memory-Usage? ==

Excessive memory usage leads to slower sites, HTTP 500 errors, and failed background jobs (cron, imports, backups). WP-Memory-Usage gives you the information you need to act before users are affected — without overwhelming you with notifications.

== Features ==

* **Real-time memory display** in the dashboard widget and every admin page footer
* **Colour-coded progress bar** (green / orange / red) for instant status recognition
* **Three alert levels:** Warning, Danger, Critical — each configurable as a percentage of the effective memory limit
* **Flexible logging:** Ajax, REST, Admin, Cron, favicon requests — log only what matters
* **Email alerts** with configurable recipient
* **History tab:** recent requests with full context (URL, type, admin screen, REST route, AJAX action, user)
* **Digest tab:** aggregated interval reports — browse, merge, and delete digest files
* **Actions tab:** plain-language guidance on resolving memory alerts, no developer skills required
* **Diagnose tab** *(new in 2.1.0)*: full configuration snapshot + one-click AI prompt (English, copy & paste ready)
* **Memory Thresholds tab:** shows effective limits, threshold gaps, and concrete recommendations
* **Check Installation tab:** verifies log directory, WP-Cron, PHP functions, disk space, and email setup
* **Admin bar indicator:** quick status badge visible on every admin page
* **Multisite compatible**
* **Clean uninstall:** removes all options, cron jobs, database-tables and log files on deletion

== PluginCheck-Plugin Status ==

Plugin is compatible with PluginCheck-Plugin. Note regarding "trademarked_term": "WP-Memory-Usage" and "wp-memory-usage" are today considered restricted terms. This plugin entered the WordPress repository in 2009, when those terms were permitted.

== Screenshots ==

1. Dashboard widget – Memory Overview with progress bar and latest digest status badges (warn / danger / critical)
2. Settings tab – thresholds, logging options, email alert configuration
3. History tab – recent requests with context (URL, type, memory usage)
4. Digest tab – aggregated interval summaries
5. Diagnose tab – PHP/WordPress configuration snapshot with AI prompt (new in 2.1.0)
6. Memory Thresholds tab – effective limits, assessment and recommendations
7. Check Installation tab – server compatibility checks
8. Admin footer – memory usage, PHP version, and IP address on every admin page

== Changelog ==
= 2.3.0 =
WordPress-Repository does not update to 2.2.1, tried it with 2.3.0

= 2.2.1 =
Improvement: Under “Diagnostics,” more data is now collected for the AI prompt to help suggest appropriate measures.

= 2.2.0 =
New: You can now choose whether settings and log files are stored in files (recommended default) or in the WordPress database. If saving to files does not work, you can try the database option.

= 2.1.1 =
Improved: Show a warning on save if the log path is not readable and writable, and optimized the loading of translations

= 2.1.0 =
* New: Diagnose tab generates a ready-made AI prompt (copy & paste into ChatGPT, Claude, etc.) for instant analysis
* New: Diagnose tab — full PHP/WordPress configuration snapshot (OPcache, GC, extensions, php.ini settings and more)

= 2.0.2 =
* Improved: Replaced PHP 8.0 syntax (match expressions) with PHP 7.4 compatible code — broadens hosting compatibility

= 2.0.1 =
* Fix: Skip log file rotation when no log file has been created yet

= 2.0.0 =
* New: Admin backend tabs — Settings, History, Digest, Actions, Memory Thresholds, Check Installation
* Improved: Dashboard widget shows latest digest status badges (warn / danger / critical)
* New: Plugin logo
* Removed: Manual "Multiple Memory Measurement" feature

= 1.2.12 =
* New: Expose data for server monitoring via API
* Compatible with PluginCheck Version 1.8.0

= 1.2.11 =
* Tested with WordPress 6.9
* Compatible with PluginCheck Version 1.7.0

= 1.2.10 =
* Tested with WordPress 6.5.4
* PluginCheck 1.0.1 compatible (except trademarked_term)
* Escaping output, using gmdate() instead of date()

= 1.2.9 =
* Bugfix: Fixed "Average MB" calculation that could trigger a PHP Deprecated warning. Thank you @dimalifragis.
* Tested with WordPress 6.4.3 and 6.5-RC

== Upgrade Notice ==

= 2.3.0 =
WordPress-Repository does not update to 2.2.1, tried it with 2.3.0

== Credits ==

Copyright 2009–2013 by Alex Rabe, 2022– Bernhard Kux
