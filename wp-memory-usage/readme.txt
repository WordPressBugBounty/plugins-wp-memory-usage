=== WP-Memory-Usage ===
Contributors: berkux
Tags: memory, usage, server, php, admin
Requires at least: 5.3
Requires PHP: 7.0
Tested up to: 6.6
Stable tag: 1.2.10
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Show up the PHP version, memory limit and current memory usage in the dashboard and admin footer

== Description ==

Multiple Memory Measurement for checking the memory multiple times 
Show up the PHP version, memory limit and current memory usage in the dashboard and admin footer. You can now simple measure the requirements of your plugins and language files.

WP-Memory-Usage is a powerful WordPress plugin designed to monitor and display memory usage in your WordPress site.
This tool is essential for site administrators and developers who need to keep an eye on the memory consumption of their WordPress installation, helping to identify potential issues and optimize performance.

== PluginCheckPlugin Status ==
Plugin is ok with PluginCheckPlugin Version 1.0.1, except "trademarked_term": 
"WP-Memory-Usage" and "wp-memory-usage" are today "restricted terms". 
This Plugin entered the WordPress repositoryx in 2009, then those terms were ok.

== Features ==
- **Real-Time Memory Usage Display:** Easily view the current memory usage of your WordPress site directly from your dashboard.
- **Multiple Measurements:** Conduct multiple measurements consecutively to track memory usage over time and during different operations.
- **User-Friendly Interface:** Intuitive and easy-to-use interface that integrates seamlessly with your WordPress dashboard.

== Why Use WP-Memory-Usage? ==
Monitoring memory usage is crucial for maintaining a healthy and efficient WordPress site. Excessive memory usage can lead to slower performance, site crashes, and a poor user experience. WP-Memory-Usage provides the insights you need to keep your site running smoothly, ensuring that you can address issues before they escalate.

== What can I do with this plugin? ==
This plugin displays in the admin footer:
* the total and percentage of the WordPress Memory 
* the total memory and percentage of the PHP Memory 
* the IP address and 
* the PHP version.

In the dashboard the plugin gives an "Memory Overview" with 
* PHP Version
* Operating system
* WordPress- and PHP Memory limits
* Current Memory usage
* Multiple Memory Measurement: Reload page and measure memory

== Multiple Memory Measurement: Reload the page and measure memory ==
Set the number of times memory should be measured and the interval between each measurement. 
Clicking "Start Measurement" will begin the measurement process and display the data. 
This allows you to check memory usage multiple times. You can switch plugins on and off during the measurements to identify which plugins consume the most memory.

== Credits ==
Copyright 2009-2013 by Alex Rabe, 2022- Bernhard Kux

== Screenshots ==

1. Screenshot Dashboard
2. Screenshot Dashboard: Running Multiple Memory Measurement
3. Screenshot Dashboard: Finished Multiple Memory Measurement
4. Screenshot Admin footer

== Changelog ==
= 1.2.10 =
* Ok with WordPress 6.5.4
* PluginCheckPlugin Version 1.0.1 ok, except "trademarked_term": "WP-Memory-Usage" and "wp-memory-usage" are today "restricted terms". This Plugin entered the WordPress repositoryx in 2009, then those terms were ok.
* Changes to pass PluginCheckPlugin: Escaping output, gmdate() instead of date()

= 1.2.9 =
* BUGFIX: Fixed calculating "Average MB", which could cause "PHP Deprecated" warning. Thank you @dimalifragis.
* Ok with WordPress 6.4.3 and 6.5-RC

= 1.2.8 =
* Fixed situation when WP_MEMORY_LIMIT or WP_MAX_MEMORY_LIMIT is not set, which gives a PHP-Warning when using PHP 8.X. Thank you @PowerMan 
* Improve I18N Issues. Thank you @alexclassroom

= 1.2.7 =
* New feature "Multiple Memory Measurement": Measure the memory multiple times by reloading the page. This gives us measuring points and an average value. With this you can better measure different settings, e. g. switch some plugins off to see the difference.
* If the PHP/WP-Memorylimit is defined as "1G" instead of "1024M" this gives valid output.
* Plugin ok with PHP 8.1
* Plugin ok with Worpdress 6.1.1

= 1.2.6 =
* Fixed translation bug
* New screenshots
* Plugin ok with Worpdress 5.9.3

= 1.2.5 =
* Plugin prepared for Translation, PO-File and de_DE-MO-File added
* Wordpress-Multisite-Installations: Plugin now also in Network-Dashboard
* Plugin ok with Worpdress 5.8.3

= 1.2.4 =
* Reengineered: Used newer functions to measure memory etc.
* consider different Wordpress- and PHP-Memorylimits
* New: German Translation
* New: Added display of IP-Adress and PHP-max-exec-time

= 1.2.3 =
* Plugin ok with PHP 7.2 and WordPress 5.8.2 (fixed some issues) 

== Upgrade Notice ==
= 1.2.10 =
* Ok with WordPress 6.5.4
* PluginCheckPlugin Version 1.0.1 ok, except "trademarked_term": "WP-Memory-Usage" and "wp-memory-usage" are today "restricted terms". This Plugin entered the WordPress repositoryx in 2009, then those terms were ok.
* Changes to pass PluginCheckPlugin: Escaping output, gmdate() instead of date()