<?php
/**
 * Threshold alerts for WP-Memory-Usage
 * - This does NOT attribute memory usage to a specific plugin. It records the request context where the peak happened.
 */
if ( ! defined( 'ABSPATH' ) ) {	exit; }
if ( ! class_exists( 'WPMU_Threshold_Alerts' ) ) :
final class WPMU_Threshold_Alerts {
	const OPTION_KEY = 'wpmu_threshold_alerts';
	const CAP        = 'manage_options';
	const DIGEST_HOOK = 'wpmu_daily_digest';
	#private static $nolog_admin = FALSE;
	private static $event_anz_display = 10;
	private static $event_anz_store = 10;
	const SETTING_COLOR_BACK = "#e7f3ff";
	const SETTING_COLOR_FONT = "#0c5d9e";
	const SETTING_COLOR_BORDER = "#3498db";
	
	
	const WPMU_LOG_FILE = "wpmu-log.cgi";
	const WPMU_LOG_PATH = 	ABSPATH. '../logs/wpmu/';
	private static $log_path = "";
	
	
	public static function init($event_anz_display, $event_anz_store, $log_path) {
		if (is_admin()) {
			add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_node' ), 100 );
			self::$event_anz_display = $event_anz_display;
		}
		self::$log_path = $log_path;
		self::$event_anz_store = $event_anz_store;
		add_action('plugins_loaded', function () {
			register_shutdown_function(array( __CLASS__, 'check_thresholds_on_shutdown' ));
		});		
		# add_action( self::DIGEST_HOOK, array( __CLASS__, 'send_daily_digest' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_intervals' ) );
		add_action( 'wpmu_cleanup_hook', array( __CLASS__, 'cron_cleanup' ) );
		// Cron planen – Intervall kommt aus Settings
		self::reschedule_cron();		
		
		register_activation_hook( dirname( __FILE__ ) . '/../wp-memory-usage.php', array( __CLASS__, 'activate' ) );
		register_deactivation_hook( dirname( __FILE__ ) . '/../wp-memory-usage.php', array( __CLASS__, 'deactivate' ) );
	}

	public static function reschedule_cron() {
		$opts         = self::get_settings();
		$interval_min = isset( $opts['rotate_log'] ) ? (int) $opts['rotate_log'] : 60;
		#$interval_sec = $interval_min * 60;
		$hook         = 'wpmu_cleanup_hook';
		$next     = wp_next_scheduled( $hook );
		$schedule = wp_get_schedule( $hook );
		$expected = 'wpmu_every_' . $interval_min . '_min';
		if ( ! $next || $schedule !== $expected ) {
			if ( $next ) {
				wp_unschedule_event( $next, $hook );
			}
			wp_schedule_event( time(), $expected, $hook );
		}
	}

	public static function add_cron_intervals( $schedules ) {
		$opts         = self::get_settings();
		$interval_min = isset( $opts['rotate_log'] ) ? (int) $opts['rotate_log'] : 60;
		$schedules[ 'wpmu_every_' . $interval_min . '_min' ] = array(
			'interval' => $interval_min * 60,
			#'display'  => sprintf( 'Every %d minutes (WPMU)', $interval_min ),
			/* translators: %d: number of minutes */
			'display' => sprintf( __( 'Every %d minutes (WPMU)', 'wp-memory-usage' ), $interval_min ),
		);
		return $schedules;
	}

	public static function activate() {}

	public static function deactivate() {
		$ts = wp_next_scheduled( 'wpmu_cleanup_hook' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'wpmu_cleanup_hook' );
		}
	}

	public static function cron_cleanup() {
		$dir = self::WPMU_LOG_PATH;
		$file = $dir . self::WPMU_LOG_FILE;

		$ts = wp_date("Y-m-d-H-i-s");
		$backup = $dir . 'wpmu-log_' . $ts . '.cgibak';
		
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		$wp_filesystem->move( $file, $backup, true ); // true = overwrite falls Ziel existiert
		#rename( $file, $backup ); # first rename, close this file

		#error_log("cron_cleanup: $file");
		#if ( file_exists( $file )) { 
		if ( file_exists( $backup )) { 
			$opts = self::get_complete_file( basename( $backup ) );
			
			$aggregation = array(
				'status'   => array( 'ok' => 0, 'warn' => 0, 'danger' => 0, 'critical' => 0 ),
				'type'     => array(),
				'uri'      => array(),
				'interval' => array(
					'first'      => PHP_INT_MAX,
					'last'       => 0,
					'per_minute' => array(),
				),
			);

			foreach ( $opts as $e1 ) {
				$s    = (string) ( $e1['s'] ?? 'ok' );
				$t    = (int)    ( $e1['t'] ?? 0 );
				$u    = (int)    ( $e1['u'] ?? 0 );
				$c    = is_array( $e1['c'] ) ? $e1['c'] : array();
				$type = (string) ( $c['type'] ?? '' );
				$uri  = (string) ( $c['uri']  ?? '' );

				// 1) Status-Häufigkeit
				$aggregation['status'][ $s ] = ( $aggregation['status'][ $s ] ?? 0 ) + 1;

				// 2) Request-Typ
				if ( $type ) {
					$aggregation['type'][ $type ] = ( $aggregation['type'][ $type ] ?? 0 ) + 1;
				}

				// 3) URI-Häufigkeit + avg/max
				if ( $uri ) {
					if ( ! isset( $aggregation['uri'][ $uri ] ) ) {
						$aggregation['uri'][ $uri ] = array(
							'total'     => 0,
							'warn'      => 0,
							'danger'    => 0,
							'critical'  => 0,
							'sum_usage' => 0,
							'max_usage' => 0,
						);
					}
					$aggregation['uri'][ $uri ]['total']++;
					$aggregation['uri'][ $uri ][ $s ] = ( $aggregation['uri'][ $uri ][ $s ] ?? 0 ) + 1;
					$aggregation['uri'][ $uri ]['sum_usage'] += $u;
					if ( $u > $aggregation['uri'][ $uri ]['max_usage'] ) {
						$aggregation['uri'][ $uri ]['max_usage'] = $u;
					}
				}

				// 4) Zeitintervall
				if ( $t < $aggregation['interval']['first'] ) { $aggregation['interval']['first'] = $t; }
				if ( $t > $aggregation['interval']['last']  ) { $aggregation['interval']['last']  = $t; }

				// 5) Ereignisse pro Minute
				$minute_key = wp_date( get_option('date_format') . ', ' . get_option('time_format'), (int) $t );  #date( 'Y-m-d H:i', $t );
				$aggregation['interval']['per_minute'][ $minute_key ] = 
					( $aggregation['interval']['per_minute'][ $minute_key ] ?? 0 ) + 1;
			}

			// Zeitspanne berechnen
			$aggregation['interval']['duration_sec'] = 
				$aggregation['interval']['last'] - $aggregation['interval']['first'];

			// Mittelwert pro URI berechnen, sum_usage entfernen
			foreach ( $aggregation['uri'] as $uri_key => &$udata ) {
				$udata['avg_usage'] = $udata['total'] > 0
					? (int) round( $udata['sum_usage'] / $udata['total'] )
					: 0;
				unset( $udata['sum_usage'] );
			}
			unset( $udata );

			$filenamedigest = 'digest_' . $ts . '.cgibak';
			#error_log("filenamedigest $filenamedigest");
			self::update_file( $aggregation, $filenamedigest );
		
			// ── Digest-E-Mail senden, wenn send_email aktiv und Alerts vorhanden ──
			$settings_for_mail = self::get_settings();
			if (
				! empty( $settings_for_mail['send_email'] ) &&
				is_email( $settings_for_mail['email_to'] ) &&
				(
					( $aggregation['status']['warn']     ?? 0 ) > 0 ||
					( $aggregation['status']['danger']   ?? 0 ) > 0 ||
					( $aggregation['status']['critical'] ?? 0 ) > 0
				)
			) {
				self::send_digest_email( $settings_for_mail['email_to'], $aggregation, $filenamedigest );
			}

		} // end if ( file_exists( $backup ) )

		// Alte Backups löschen
		$opts    = self::get_settings();
		$del_min = isset( $opts['del_frequency_logfiles'] ) ? (int) $opts['del_frequency_logfiles'] : 1440;
		#error_log("del_min: $del_min");
		
		if ( $del_min > 0 ) {
			#error_log("do del");
			$max_age_sec = $del_min * 60;
			$now         = time();
			foreach ( glob( $dir . '*.cgibak' ) as $bak ) {
				// Nur rotierte Log-Backups (wpmu-log_*.cgibak) löschen, keine Digest-Dateien
				#error_log("del: ".$bak);
				if ( strpos( basename( $bak ), 'wpmu-log_' ) === 0 ) {
					if ( filemtime( $bak ) < ( $now - $max_age_sec ) ) {
						#error_log("do del: ".$bak);
						wp_delete_file( $bak );
					}
				}
			}
		}
	}

	public static function add_admin_menu() { 
		add_options_page(
			esc_html__( 'Memory Threshold Alerts', 'wp-memory-usage' ),
			esc_html__( 'Memory Alerts', 'wp-memory-usage' ),
			self::CAP,
			'wpmu-memory-alerts',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	#### LOGGING: SAVE and LOAD : BEGIN ####
	private static function get_from_file($filename) {
		if (empty($filename)) { return NULL; }
		$dir = self::WPMU_LOG_PATH;
		
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0755 );
		}		
		#if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
		
		$file = $dir . $filename;
		if (!file_exists($file)) {
			return [];
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$fh = @fopen($file, 'rb');
		if (!$fh) return [];
		if (!flock($fh, LOCK_SH)) { fclose($fh); return []; }
		$content = fgets($fh);
		$content = trim($content);
		flock($fh, LOCK_UN);
		fclose($fh);
		// phpcs:enable WordPress.WP.AlternativeFunctions						
		return json_decode($content, TRUE);	
	}

	private static function get_complete_file($filename) {
		if (empty($filename)) { return NULL; }
		$dir = self::WPMU_LOG_PATH;

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0755 );
		}		
		#if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
		
		$file = $dir . $filename;
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$fh = @fopen($file, 'rb');
		if (!$fh) return [];
		if (!flock($fh, LOCK_SH)) { fclose($fh); return []; }
		$content = stream_get_contents($fh);
		flock($fh, LOCK_UN);
		fclose($fh);
		// phpcs:enable WordPress.WP.AlternativeFunctions						
		$lines = array_filter(explode("\n", trim($content)));
		$result = [];
		foreach ($lines as $line) {
			$decoded = json_decode(trim($line), true);
			if (is_array($decoded)) {
				$result[] = $decoded;
			}
		}
		return $result;
	}

	private static function get_log() {
		return self::get_complete_file(self::WPMU_LOG_FILE);
	}

	private static function add_log($opts) {
		return self::append_file( $opts, self::WPMU_LOG_FILE);
	}

	private static function get_options_file() {
		$opts = self::get_log();
		if ( ! is_array( $opts ) ) { $opts = array(); }
		$merged = array_merge( self::defaults(), $opts );
		if ( isset( $merged['last_context'] ) && ! is_array( $merged['last_context'] ) ) {
			$merged['last_context'] = array( 'summary' => (string) $merged['last_context'] );
		}
		if ( ! isset( $merged['ev'] ) || ! is_array( $merged['ev'] ) ) {
			$merged['ev'] = array();
		}
		if ( ! isset( $merged['fingerprint_sent'] ) || ! is_array( $merged['fingerprint_sent'] ) ) {
			$merged['fingerprint_sent'] = array();
		}
		return $merged;
	}

	private static function append_file( $opts, $filename ) {
		if (empty($filename)) { return NULL; }
		$dir =  self::WPMU_LOG_PATH;

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0755 );
		}		
		#if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
		$file = $dir . $filename;
		$optsStr = json_encode($opts) . "\n";
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$fh = fopen($file, 'ab');
		if ( $fh ) {
			flock($fh, LOCK_EX);
			fwrite($fh, $optsStr);
			fflush($fh);
			flock($fh, LOCK_UN);
			fclose($fh);
		}
		#file_put_contents($file, $optsStr, FILE_APPEND | LOCK_EX);
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}

	private static function update_file( $opts, $filename ) {
		if (empty($filename)) { return NULL; }
		$dir = self::WPMU_LOG_PATH;
		#error_log("DIR: ".$dir);
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0755 );
		}		
		#if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
		$file = $dir . $filename;
		$optsStr = json_encode($opts);
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$fh = fopen($file, 'c+');
		if (!$fh) return;
		flock($fh, LOCK_EX);
		ftruncate($fh, 0);
		rewind($fh);
		fwrite($fh, $optsStr);
		fflush($fh);
		flock($fh, LOCK_UN);
		fclose($fh);
		// phpcs:enable WordPress.WP.AlternativeFunctions						
	}

	private static function update_options_file( $opts ) {
		self::update_file($opts, self::WPMU_LOG_FILE);
	}

	private static function get_settings() {
		$settings = self::get_from_file("settings.cgi");
		#error_log("settings: ".print_r($settings, true));
		if ($settings === []) {
			#error_log("settings: DEF");
			$settings = self::defaults();
		}
		#error_log("Settings: ".print_r($settings, true));
		return $settings;
	}

	private static function set_settings($opts) {
		return self::update_file($opts, "settings.cgi");
	}
	#### LOGGING: SAVE and LOAD : END ####

	###############################################################
	private static function defaults() {
		return array(
			'warn_pct'      => 70,
			'danger_pct'    => 85,
			'critical_pct'  => 95,
			'logop_warn_pct'      => 0,
			'logop_danger_pct'    => 0,
			'logop_critical_pct'  => 0,
			'email_to'      => get_option( 'admin_email' ),
			'send_email'    => 0,
			'track_peak'    => 1,
			'log_ajax'      => 1,
			'log_cron'      => 1,
			'log_rest'      => 1,
			'log_admin'     => 1,
			'log_favicon'   => 1,
			'log_ok'        => 0,
			'digest_enabled'      => 1,
			'rotate_log'         => 30,
			'del_frequency_logfiles' => 1440, # 1day=24*60
		);
	}

	/** ---------------- Settings UI ---------------- */
	#public static function error_logging($errortxt) {
	#	error_log($errortxt);
	#	return TRUE;
	#}

	public static function section_intro_measure() {
		echo esc_html__( 'Choose how memory usage should be measured. Peak memory catches short spikes during the request.', 'wp-memory-usage' );
	}
	public static function section_intro_log() {
		echo esc_html__( 'Configure which request types and memory states should be logged.', 'wp-memory-usage' );
	}
	public static function section_intro_howalert() {
		echo esc_html__( 'Configure when and how email alerts are sent.', 'wp-memory-usage' );
	}
	public static function section_intro_thresholds() {
		echo esc_html__('Thresholds are evaluated as percentage of the effective memory limit (min of PHP and WP limits where applicable).', 'wp-memory-usage' );
	}

	public static function sanitize_options_file( $input ) {
		$opts_settings = self::get_settings();
		$in   = is_array( $input ) ? $input : array();
		$opts_settings['warn_pct']     = self::clamp_int( isset( $in['warn_pct'] ) ? $in['warn_pct'] : $opts_settings['warn_pct'], 1, 99 );
		$opts_settings['danger_pct']   = self::clamp_int( isset( $in['danger_pct'] ) ? $in['danger_pct'] : $opts_settings['danger_pct'], 1, 100 );
		$opts_settings['critical_pct'] = self::clamp_int( isset( $in['critical_pct'] ) ? $in['critical_pct'] : $opts_settings['critical_pct'], 1, 100 );
		$opts_settings['logop_warn_pct']     = ! empty( $in['logop_warn_pct'] ) ? 1 : 0;
		$opts_settings['logop_danger_pct']   = ! empty( $in['logop_danger_pct'] ) ? 1 : 0;
		$opts_settings['logop_critical_pct'] = ! empty( $in['logop_critical_pct'] ) ? 1 : 0;
		if ( $opts_settings['danger_pct'] <= $opts_settings['warn_pct'] ) {
			$opts_settings['danger_pct'] = min( 100, $opts_settings['warn_pct'] + 5 );
		}
		if ( $opts_settings['critical_pct'] <= $opts_settings['danger_pct'] ) {
			$opts_settings['critical_pct'] = min( 100, $opts_settings['danger_pct'] + 5 );
		}
		$opts_settings['email_to']   = sanitize_email( isset( $in['email_to'] ) ? $in['email_to'] : $opts_settings['email_to'] );
		$opts_settings['send_email'] = ! empty( $in['send_email'] ) ? 1 : 0;
		$opts_settings['track_peak'] = ! empty( $in['track_peak'] ) ? 1 : 0;
		$opts_settings['del_frequency_logfiles'] = self::clamp_int( isset( $in['del_frequency_logfiles'] ) ? $in['del_frequency_logfiles'] : $opts_settings['del_frequency_logfiles'], 0, 43200 );
		$opts_settings['digest_enabled'] = ! empty( $in['digest_enabled'] ) ? 1 : 0;
		$opts_settings['rotate_log']     = self::clamp_int( isset( $in['rotate_log'] ) ? $in['rotate_log'] : $opts_settings['rotate_log'], 1, 600 );
		$opts_settings['log_admin']   = ! empty( $in['log_admin'] ) ? 1 : 0;
		$opts_settings['log_ajax']    = ! empty( $in['log_ajax'] ) ? 1 : 0;
		$opts_settings['log_rest']    = ! empty( $in['log_rest'] ) ? 1 : 0;
		$opts_settings['log_cron']    = ! empty( $in['log_cron'] ) ? 1 : 0;
		$opts_settings['log_favicon'] = ! empty( $in['log_favicon'] ) ? 1 : 0;
		$opts_settings['log_ok']      = ! empty( $in['log_ok'] ) ? 1 : 0;
		self::set_settings( $opts_settings );
		self::reschedule_cron();
		return $opts_settings;
	}

	private static function clamp_int( $value, $min, $max ) {
		$v = (int) $value;
		if ( $v < (int) $min ) { $v = (int) $min; }
		if ( $v > (int) $max ) { $v = (int) $max; }
		return $v;
	}

	public static function render_field( $args ) {
		$opts = self::get_settings();
		$key  = isset( $args['key'] ) ? (string) $args['key'] : '';
		$val  = isset( $opts[ $key ] ) ? $opts[ $key ] : '';
		$type = 'number';
		if ( 'email_to' === $key ) { $type = 'email'; }
		$style = '';
		if ( 'number' === $key ) { $style = ' style="width: 200px;"'; }
		$attrs = '';
		if ( 'number' === $type ) {
			$attrs = 'step="1"';
			if ( in_array( $key, array( 'warn_pct', 'danger_pct', 'critical_pct' ), true ) ) {
				$attrs .= ' min="1" max="100" size="4"';
			} elseif ( in_array( $key, array( 'del_frequency_logfiles' ), true ) ) {
				$attrs .= ' min="0" max="43200"'; #max 30 days
			} elseif ( in_array( $key, array( 'rotate_log' ), true ) ) {
				$attrs .= ' min="1" max="600"';
			}
		}
		printf(
			'<input type="%s" name="%s[%s]" value="%s" %s %s />',
			esc_attr( $type ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( (string) $val ),
			esc_attr( (string) $style ),
			wp_kses_data( $attrs )
		);
		$desc = '';
		switch ( $key ) {
			case 'email_to':
				$desc = __( 'Email address that should receive alerts. Tip: use a shared mailbox or a ticket system address so alerts don\'t get lost. You can also use your agency\'s address if they maintain the site.', 'wp-memory-usage' );
				break;
			case 'warn_pct':
				$desc = __( 'Warning threshold (in percent). Use this as an early heads‑up. Example: 70 means "warn me when a request uses 70% of the available memory". This is usually not an emergency, but it tells you where to look.', 'wp-memory-usage' );
				break;
			case 'danger_pct':
				$desc = __( 'Danger threshold (in percent). Use this for "take action soon". Example: 85 means "this request consumed 85% of the available memory". Repeated hits here often lead to errors on heavy pages or imports.', 'wp-memory-usage' );
				break;
			case 'critical_pct':
				$desc = __( 'Critical threshold (in percent). This is "high risk of out-of-memory errors". Example: 95 means "the request almost hit the limit". If you see this on the frontend, visitors can get 500 errors.', 'wp-memory-usage' );
				break;
			case 'del_frequency_logfiles':
				$desc = __( 'Delete rotated Logfiles older than X Minutes', 'wp-memory-usage' );
				break;
			case 'rotate_log':
				$desc = __( 'Rotate every X Minutes the logfile and create a digest', 'wp-memory-usage' );
				break;
			default:
				break;
		}
		if ( $desc ) {
			echo '<br>' . esc_html( $desc ) . '';
		}
	}

	public static function render_checkbox( $args ) {
		$opts = self::get_settings();
		$key  = isset( $args['key'] ) ? (string) $args['key'] : '';
		$val  = ! empty( $opts[ $key ] ) ? 1 : 0;
		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1" %s /></label>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			checked( 1, $val, false )
		);
		$desc = '';
		switch ( $key ) {
			case 'send_email':
				$desc = __( 'Enable email alerts. If disabled, the plugin still shows the status in the admin bar and stores results, but it will not send any emails.', 'wp-memory-usage' );
				break;
			case 'track_peak':
				$desc = __( 'Use peak memory (recommended). The plugin measures the highest memory usage during the request. This catches short spikes (for example during image processing) that might be missed when only looking at the current value.', 'wp-memory-usage' );
				break;
			#case 'digest_enabled':
			#	$desc = __( 'Enable daily digest (Smart mode). You will receive one summary email per day with the most important alerts and where they happened. Useful if you don\'t want immediate emails for every event.', 'wp-memory-usage' );
			#	break;
			default:
				break;
		}
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	public static function render_select( $args ) {
		$opts = self::get_settings();
		$key  = isset( $args['key'] ) ? (string) $args['key'] : '';
		$val  = isset( $opts[ $key ] ) ? (string) $opts[ $key ] : '';
		$options = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
		printf( '<select name="%s[%s]">', esc_attr( self::OPTION_KEY ), esc_attr( $key ) );
		foreach ( $options as $k => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $k ),
				selected( $val, (string) $k, false ),
				esc_html( (string) $label )
			);
		}
		echo '</select>';
	}

	/** ---------------- Admin Bar ---------------- */
	public static function admin_bar_node( $bar ) {
		if ( ! is_user_logged_in() || ! current_user_can( self::CAP ) ) { return; }
		$opts  = self::get_settings();
		#error_log("admin_bar_node: ".print_r($opts, true));
		$limit = self::get_effective_memory_limit_bytes();
		$usage = self::get_current_memory_bytes( ! empty( $opts['track_peak'] ) );
		$state = self::state_for_usage( $usage, $limit, $opts );
		$label = sprintf(
			'%s %s / %s',
			strtoupper( $state ),
			self::format_bytes( $usage ),
			$limit > 0 ? self::format_bytes( $limit ) : __( 'unlimited', 'wp-memory-usage' )
		);
		$bar->add_node( array(
			'id'    => 'wpmu_memory_alerts',
			'title' => esc_html( $label ),
			'href'  => admin_url( 'options-general.php?page=wpmu-memory-alerts' ),
			'meta'  => array( 'title' => 'WP Memory Threshold Alerts' ),
		) );
	}

	/** ---------------- Core logic ---------------- */
	public static function check_thresholds_on_shutdown() {
		$opts_settings = self::get_settings();
		$opts  = [];
		$limit = self::get_effective_memory_limit_bytes();
		$usage = self::get_current_memory_bytes( ! empty( $opts_settings['track_peak'] ) );
		$context = self::collect_context();
		$now     = time();
		$state = self::state_for_usage( $usage, $limit, $opts_settings );
		$opts['st'] = $state;
		$opts['pe'] = (int) $usage;
		$opts['li'] = (int) $limit;
		self::log_event( $opts, $opts_settings, $context );
	}

	#private static function is_favicon_wpjson_call() {
	#	$request_uri = $_SERVER['REQUEST_URI'];
	#	return !(strpos($request_uri, '/wp-json/') !== false && strpos($request_uri, 'favicon.ico') !== false);
	#}

	private static function send_email_alert( $to, $state, $usage, $limit, $context ) {
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$subject = sprintf(
			'[%s] Memory %s (%s)',
			$site ? $site : 'WordPress',
			strtoupper( $state ),
			self::format_pct( $usage, $limit )
		);
		$lines   = array();
		$lines[] = __('WP Memory Threshold Alert', 'wp-memory-usage' );
		$lines[] = '';
		$lines[] = __('State: ', 'wp-memory-usage' ) . strtoupper( $state );
		$lines[] = __('Usage: ', 'wp-memory-usage' ) . self::format_bytes( $usage );
		$lines[] = __('Limit: ', 'wp-memory-usage' ) . ( $limit > 0 ? self::format_bytes( $limit ) : __('unlimited', 'wp-memory-usage' ) );
		$lines[] = __('Ratio: ', 'wp-memory-usage' ) . self::format_pct( $usage, $limit );
		$lines[] = '';
		$lines[] = __('Context:', 'wp-memory-usage' );
		$lines[] = __('- Type:', 'wp-memory-usage' ) .' '  . (string) ( isset( $context['type'] )   ? $context['type']   : '' );
		$lines[] = __('- Method:', 'wp-memory-usage' ).' ' . (string) ( isset( $context['method'] ) ? $context['method'] : '' );
		$lines[] = __('- URI:', 'wp-memory-usage' ).' '. (string) ( isset( $context['uri'] )    ? $context['uri']    : '' );
		if ( ! empty( $context['admin_screen'] ) ) { $lines[] = __('- Admin screen:', 'wp-memory-usage' ).' '. (string) $context['admin_screen']; }
		if ( ! empty( $context['post_id'] ) )      { $lines[] = __('- Post ID:', 'wp-memory-usage' )  .' '  . (string) $context['post_id']; }
		if ( ! empty( $context['ajax_action'] ) )  { $lines[] = __('- AJAX action:', 'wp-memory-usage' ) .' ' . (string) $context['ajax_action']; }
		if ( ! empty( $context['rest_route'] ) )   { $lines[] = __('- REST route:', 'wp-memory-usage' ).' '   . (string) $context['rest_route']; }
		if ( ! empty( $context['user'] ) )         { $lines[] = __('- User:', 'wp-memory-usage' ) .' '  . (string) $context['user']; }
		$lines[] = '';
		$lines[] = __('Suggested actions:', 'wp-memory-usage' );
		$lines[] = __('- Reproduce this URL/action and temporarily disable recently added/updated plugins', 'wp-memory-usage' );
		$lines[] = __('- If it is an import/editor screen, try with fewer plugins active', 'wp-memory-usage' );
		$lines[] = __('- Consider raising PHP memory_limit / WP_MEMORY_LIMIT if appropriate', 'wp-memory-usage' );
		$lines[] = '';
		$lines[] = __('Settings: ', 'wp-memory-usage' ) . admin_url( 'options-general.php?page=wpmu-memory-alerts' );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		return (bool) wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
	}

	/** ---------------- Digest email ---------------- */
	public static function send_digest_email( $to, $aggregation, $filename ) {
		$site    = wp_parse_url( home_url(), PHP_URL_HOST );
		$site    = $site ? $site : 'WordPress';
		$warn     = (int) ( $aggregation['status']['warn']     ?? 0 );
		$danger   = (int) ( $aggregation['status']['danger']   ?? 0 );
		$critical = (int) ( $aggregation['status']['critical'] ?? 0 );
		$total    = $warn + $danger + $critical + (int) ( $aggregation['status']['ok'] ?? 0 );

		// Subject: höchste Severity hervorheben
		$severity = $critical > 0 ? __('CRITICAL','wp-memory-usage') : ( $danger > 0 ? __('DANGER','wp-memory-usage') : __('WARN','wp-memory-usage') );
		$subject  = sprintf( '[%s] Memory Digest %s – warn:%d danger:%d critical:%d', $site, $severity, $warn, $danger, $critical );

		$lines   = array();
		$lines[] = __('WP Memory Usage – Digest Report', 'wp-memory-usage' );
		$lines[] = str_repeat( '-', 50 );
		$lines[] = __('File   : ', 'wp-memory-usage' ) . $filename;
		$lines[] = __('Site   : ', 'wp-memory-usage' ) . home_url();
		$lines[] = '';
		$lines[] = __('STATUS SUMMARY', 'wp-memory-usage' );
		$lines[] = '  '.__('OK       : ', 'wp-memory-usage' ) . (int) ( $aggregation['status']['ok']       ?? 0 );
		$lines[] = '  '.__('WARN     : ', 'wp-memory-usage' ) . $warn;
		$lines[] = '  '.__('DANGER   : ', 'wp-memory-usage' ) . $danger;
		$lines[] = '  '.__('CRITICAL : ', 'wp-memory-usage' ) . $critical;
		$lines[] = '  '.__('Total    : ', 'wp-memory-usage' ) . $total;
		$lines[] = '';

		// Zeitintervall
		$first = $aggregation['interval']['first'] ?? 0;
		$last  = $aggregation['interval']['last']  ?? 0;
		if ( $first && $last ) {
			$lines[] = __('TIME RANGE', 'wp-memory-usage' );
			$lines[] = '  '.__('From : ', 'wp-memory-usage' ) . wp_date( 'Y-m-d H:i:s', $first );
			$lines[] = '  '.__('To   : ', 'wp-memory-usage' ) . wp_date( 'Y-m-d H:i:s', $last );
			$dur     = $last - $first;
			$lines[] = '  '.__('Dur  : ', 'wp-memory-usage' ) . floor( $dur / 60 ) . ' min ' . ( $dur % 60 ) . ' sec';
			$lines[] = '';
		}

		// Request-Typen
		if ( ! empty( $aggregation['type'] ) ) {
			$lines[] = __('REQUEST TYPES', 'wp-memory-usage' );
			foreach ( $aggregation['type'] as $type => $cnt ) {
				$lines[] = sprintf( '  %-12s: %d', strtoupper( $type ), $cnt );
			}
			$lines[] = '';
		}

		// Top-URIs mit Alerts (max. 20, sortiert nach warn+danger+critical)
		if ( ! empty( $aggregation['uri'] ) ) {
			$uris_with_alerts = array_filter( $aggregation['uri'], function ( $u ) {
				return ( ( $u['warn'] ?? 0 ) + ( $u['danger'] ?? 0 ) + ( $u['critical'] ?? 0 ) ) > 0;
			} );
			uasort( $uris_with_alerts, function ( $a, $b ) {
				$sa = ( $a['warn'] ?? 0 ) + ( $a['danger'] ?? 0 ) * 2 + ( $a['critical'] ?? 0 ) * 3;
				$sb = ( $b['warn'] ?? 0 ) + ( $b['danger'] ?? 0 ) * 2 + ( $b['critical'] ?? 0 ) * 3;
				return $sb <=> $sa;
			} );
			$lines[] = __('TOP URIs WITH ALERTS (warn/danger/critical | avg | max)', 'wp-memory-usage' );
			$count = 0;
			foreach ( $uris_with_alerts as $uri => $ud ) {
				if ( ++$count > 20 ) { break; }
				$lines[] = sprintf(
					'  %s',
					$uri
				);
				$lines[] = sprintf(
					'    W:%d D:%d C:%d | total:%d | avg:%s | max:%s',
					$ud['warn']     ?? 0,
					$ud['danger']   ?? 0,
					$ud['critical'] ?? 0,
					$ud['total']    ?? 0,
					self::format_bytes( $ud['avg_usage'] ?? 0 ),
					self::format_bytes( $ud['max_usage'] ?? 0 )
				);
			}
			$lines[] = '';
		}

		$lines[] =  __( 'Settings:', 'wp-memory-usage' ) . admin_url( 'options-general.php?page=wpmu-memory-alerts&tab=digest' );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		return (bool) wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
	}

	/** ---------------- Digest ---------------- */
	private static function ensure_digest_schedule( $hour ) {
		$hour = (int) $hour;
		if ( $hour < 0 || $hour > 23 ) { $hour = 8; }
		if ( wp_next_scheduled( self::DIGEST_HOOK ) ) { return; }
		$now = current_time( 'timestamp' );
		$run = strtotime( gmdate( 'Y-m-d', $now ) . sprintf( ' %02d:00:00', $hour ) );
		if ( $run <= $now ) { $run = strtotime( '+1 day', $run ); }
		wp_schedule_event( $run, 'daily', self::DIGEST_HOOK );
	}

	/** ---------------- Context capture ---------------- */
	private static function collect_context() {
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		#$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';		
		$is_ajax  = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		$is_cron  = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		$is_rest  = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( '' !== $uri && false !== strpos( $uri, '/wp-json/' ) );
		$is_admin = is_admin();
		$type = $is_cron ? 'cron' : ( $is_ajax ? 'ajax' : ( $is_rest ? 'rest' : ( $is_admin ? 'admin' : 'front' ) ) );
		$ajax_action = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( $is_ajax && isset( $_REQUEST['action'] ) ) {
			$ajax_action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended		
		$rest_route = '';
		if ( $is_rest ) {
			if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
				$rest_route = (string) $GLOBALS['wp']->query_vars['rest_route'];
			} else {
				$rest_route = $uri;
			}
		}
		$admin_screen = '';
		if ( $is_admin && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && ! empty( $screen->id ) ) {
				$admin_screen = (string) $screen->id;
			}
		}
		global $post;
		$post_id  = isset( $post->ID ) ? (string) $post->ID : 'unknown';
		$user_str = '';
		$user = wp_get_current_user();
		if ( $user && $user->exists() ) {
			$roles    = is_array( $user->roles ) ? implode( ',', $user->roles ) : '';
			$user_str = sprintf( '%s (#%d)%s', $user->user_login, (int) $user->ID, $roles ? ' roles:' . $roles : '' );
		}
		return array(
			'type'         => $type,
			'method'       => $method,
			'uri'          => $uri,
			'admin_screen' => $admin_screen,
			'post_id'      => $post_id ? $post_id : '',
			'ajax_action'  => $ajax_action,
			'rest_route'   => $rest_route,
			'user'         => $user_str,
			'is_admin'     => $is_admin ? 1 : 0,
			'is_ajax'      => $is_ajax ? 1 : 0,
			'is_cron'      => $is_cron ? 1 : 0,
			'is_rest'      => $is_rest ? 1 : 0,
		);
	}

	/** ---------------- set limits ---------------- */
	private static function log_event( &$opts, $opts_settings, $ctx ) {
		if ( ( ! $opts_settings["log_admin"] ) && ( $ctx['is_admin'] ) ) {
			#error_log("NO LOG admin"); 
			return NULL;
		}
		if ( ( ! $opts_settings["log_ok"] ) && ( $opts['st'] === "ok" ) ) {
			return NULL;
		}
		if ( ( ! $opts_settings["logop_warn_pct"] ) && ( $opts['st'] === "warn" ) ) {
			#error_log("NO LOG warn"); 
			return NULL;
		}
		if ( ( ! $opts_settings["logop_danger_pct"] ) && ( $opts['st'] === "danger" ) ) {
			#error_log("NO LOG danger"); 
			return NULL;
		}
		if ( ( ! $opts_settings["logop_critical_pct"] ) && ( $opts['st'] === "critical" ) ) {
			#error_log("NO LOG critical"); 
			return NULL;
		}
		if ( ( ! $opts_settings["log_rest"] ) && ( $ctx['is_rest'] ) ) { return NULL; }
		if ( ( ! $opts_settings["log_ajax"] ) && ( $ctx['is_ajax'] ) ) { return NULL; }
		if ( ( ! $opts_settings["log_cron"] ) && ( $ctx['is_cron'] ) ) { return NULL; }
		if ( preg_match("/favicon.ico/", $ctx['uri']) ) {
			#error_log("NO LOG favicon"); 
			return TRUE;
		}
		$optsout = array(
			't' => time(),
			's' => (string) $opts['st'],
			'u' => (int) $opts['pe'],
			'l' => (int) $opts['li'],
			'c' => array(
				'type'         => isset( $ctx['type'] )         ? (string) $ctx['type']         : '',
				'uri'          => isset( $ctx['uri'] )          ? (string) $ctx['uri']          : '',
				'admin_screen' => isset( $ctx['admin_screen'] ) ? (string) $ctx['admin_screen'] : '',
				'ajax_action'  => isset( $ctx['ajax_action'] )  ? (string) $ctx['ajax_action']  : '',
				'rest_route'   => isset( $ctx['rest_route'] )   ? (string) $ctx['rest_route']   : '',
				'user'         => isset( $ctx['user'] )         ? (string) $ctx['user']         : '',
				'is_admin'     => ! empty( $ctx['is_admin'] )   ? 1 : 0,
			),
		);
		self::add_log( $optsout );
	}

	#############################################################
	# DONE
	#############################################################

	### UI
	public static function register_settings() {
		register_setting(
			'wpmu_threshold_settings',
			self::OPTION_KEY,
			array( __CLASS__, 'sanitize_options_file' )
		);
		// Thresholds
		add_settings_section(
			'wpmu_main',
			'<h2 style="background-color: '.self::SETTING_COLOR_BACK.'; padding: 10px; color: '.self::SETTING_COLOR_FONT.'; border: 2px solid '.self::SETTING_COLOR_BORDER.';">'
			. esc_html__( 'Thresholds', 'wp-memory-usage' ) . "</h2>",
			array( __CLASS__, 'section_intro_thresholds' ),
			'wpmu-memory-alerts'
		);
		#$fields = array(
		#	'warn_pct'     => esc_html__( 'Warning threshold', 'wp-memory-usage' ),
		#	'danger_pct'   => esc_html__( 'Danger threshold', 'wp-memory-usage' ),
		#	'critical_pct' => esc_html__( 'Critical threshold', 'wp-memory-usage' ),
		#);
		$fields = array(
			'warn_pct'     => array(
				'label'     => esc_html__( 'Warning threshold', 'wp-memory-usage' ),
				'log_label' => esc_html__( 'Log Warning', 'wp-memory-usage' ),
			),
			'danger_pct'   => array(
				'label'     => esc_html__( 'Danger threshold', 'wp-memory-usage' ),
				'log_label' => esc_html__( 'Log Danger', 'wp-memory-usage' ),
			),
			'critical_pct' => array(
				'label'     => esc_html__( 'Critical threshold', 'wp-memory-usage' ),
				'log_label' => esc_html__( 'Log Critical', 'wp-memory-usage' ),
			),
		);		
		#foreach ( $fields as $key => $label ) {
		foreach ( $fields as $key => $field ) {			
			add_settings_field(
				$key,
				#esc_html( $label . " %" ),
				esc_html( $field['label'] . ' %' ),
				array( __CLASS__, 'render_field' ),
				'wpmu-memory-alerts',
				'wpmu_main',
				array( 'key' => $key )
			);
			add_settings_field(
				( "logop_" . $key ),
				$field['log_label'],      
				#esc_html__( 'Log ' . $fields[ $key ], 'wp-memory-usage' ),
				array( __CLASS__, 'render_checkbox' ),
				'wpmu-memory-alerts',
				'wpmu_main',
				array( 'key' => ( "logop_" . $key ) )
			);
		}
		// How to measure
		add_settings_section(
			'wpmu_measure',
			'<h2 style="background-color: '.self::SETTING_COLOR_BACK.'; padding: 10px; color: '.self::SETTING_COLOR_FONT.'; border: 2px solid '.self::SETTING_COLOR_BORDER.';">'
			. esc_html__( 'How to measure', 'wp-memory-usage' ) . "</h2>",
			array( __CLASS__, 'section_intro_measure' ),
			'wpmu-memory-alerts'
		);
		add_settings_field(
			'track_peak',
			esc_html__( 'Use peak memory (recommended)', 'wp-memory-usage' ),
			array( __CLASS__, 'render_checkbox' ),
			'wpmu-memory-alerts',
			'wpmu_measure',
			array( 'key' => 'track_peak' )
		);
		// Logging
		add_settings_section(
			'wpmu_logging',
			'<h2 style="background-color: '.self::SETTING_COLOR_BACK.'; padding: 10px; color: '.self::SETTING_COLOR_FONT.'; border: 2px solid '.self::SETTING_COLOR_BORDER.';">'
			. esc_html__( 'Logging', 'wp-memory-usage' ) . "</h2>",
			array( __CLASS__, 'section_intro_log' ),
			'wpmu-memory-alerts'
		);
		
		
		$log_fields = array(
			'log_ajax'    => esc_html__( 'Log Ajax',        'wp-memory-usage' ),
			'log_rest'    => esc_html__( 'Log Rest',        'wp-memory-usage' ),
			'log_admin'   => esc_html__( 'Log Admin',       'wp-memory-usage' ),
			'log_cron'    => esc_html__( 'Log Cron',        'wp-memory-usage' ),
			'log_favicon' => esc_html__( 'Log favicon.ico', 'wp-memory-usage' ),
			'log_ok'      => esc_html__( 'Log OK',          'wp-memory-usage' ),
		);

		foreach ( $log_fields as $logkey => $label ) {


	
		#foreach ( array( 'log_ajax', 'log_rest', 'log_admin', 'log_cron', 'log_favicon', 'log_ok' ) as $logkey ) {
			#$labels = array(
			#	'log_ajax'    => 'Log Ajax',
			#	'log_rest'    => 'Log Rest',
			#	'log_admin'   => 'Log Admin',
			#	'log_cron'    => 'Log Cron',
			#	'log_favicon' => 'Log favicon.ico',
			#	'log_ok'      => 'Log OK',
			#);
			add_settings_field(
				$logkey,
				#esc_html__( $labels[ $logkey ], 'wp-memory-usage' ),
				$label,
				array( __CLASS__, 'render_checkbox' ),
				'wpmu-memory-alerts',
				'wpmu_logging',
				array( 'key' => $logkey )
			);
		}
		// Alert settings
		add_settings_section(
			'wpmu_alertsettings',
			'<h2 style="background-color: '.self::SETTING_COLOR_BACK.'; padding: 10px; color: '.self::SETTING_COLOR_FONT.'; border: 2px solid '.self::SETTING_COLOR_BORDER.';">'
			. esc_html__( 'How to alert', 'wp-memory-usage' ) . "</h2>",
			array( __CLASS__, 'section_intro_howalert' ),
			'wpmu-memory-alerts'
		);
		add_settings_field( 'email_to',                esc_html__('Alert email recipient','wp-memory-usage' ),              array( __CLASS__, 'render_field' ),    'wpmu-memory-alerts', 'wpmu_alertsettings', array( 'key' => 'email_to' ) );
		add_settings_field( 'send_email',              esc_html__('Send email alerts','wp-memory-usage' ),                  array( __CLASS__, 'render_checkbox' ), 'wpmu-memory-alerts', 'wpmu_alertsettings', array( 'key' => 'send_email' ) );
		add_settings_field( 'rotate_log',              esc_html__('Digest interval','wp-memory-usage' ),                    array( __CLASS__, 'render_field' ),    'wpmu-memory-alerts', 'wpmu_alertsettings', array( 'key' => 'rotate_log' ) );
		add_settings_field( 'del_frequency_logfiles',  esc_html__('Delete rotated Logfiles','wp-memory-usage' ), array( __CLASS__, 'render_field' ), 'wpmu-memory-alerts', 'wpmu_alertsettings', array( 'key' => 'del_frequency_logfiles' ) );
	}

	public static function render_settings_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-memory-usage' ) );
		}
		$opts = self::get_settings();
		$tab = 'history';
		if ( isset( $_GET['tab'], $_GET['_wpmu_tab_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpmu_tab_nonce'] ) ), 'wpmu_tab_nav' ) ) {
			$tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
		}
		if ( ! in_array( $tab, array( 'settings', 'current', 'actions', 'digest', 'history', 'check_installation' ), true ) ) {
			$tab = 'history';
		}
		$page_url  = admin_url( 'options-general.php?page=wpmu-memory-alerts' );
		$tab_nonce = wp_create_nonce( 'wpmu_tab_nav' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Memory Usage', 'wp-memory-usage' ); ?> – <?php echo esc_html__( 'Threshold Alerts', 'wp-memory-usage' ); ?></h1>
			<h2 class="nav-tab-wrapper" style="margin-top: 12px;">
				<?php
				$tabs = array(
					'settings'           => esc_html__( '⚙️ Settings',           'wp-memory-usage' ),
					'history'            => esc_html__( '📋 History',             'wp-memory-usage' ),
					'digest'             => esc_html__( '📊 Digest',              'wp-memory-usage' ),
					'actions'            => esc_html__( '🛠️ Actions',            'wp-memory-usage' ),
					'current'            => esc_html__( '📏 Memory Thresholds',   'wp-memory-usage' ),
					'check_installation' => esc_html__( '🔍 Check Installation',  'wp-memory-usage' ),
				);
				#				);
				foreach ( $tabs as $t => $label ) :
				?>
				<a href="<?php echo esc_url( add_query_arg( array( 'tab' => $t, '_wpmu_tab_nonce' => $tab_nonce ), $page_url ) ); ?>"
				   class="nav-tab <?php echo ( $t === $tab ) ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html($label); ?>
				</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'settings' === $tab ) : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'wpmu_threshold_settings' );
					do_settings_sections( 'wpmu-memory-alerts' );
					submit_button();
					?>
				</form>

			<?php elseif ( 'current' === $tab ) : ?>
				<?php
				$php_limit_raw = ini_get( 'memory_limit' );
				$wp_limit_raw  = defined( 'WP_MEMORY_LIMIT' )     ? WP_MEMORY_LIMIT     : '';
				$wpmax_raw     = defined( 'WP_MAX_MEMORY_LIMIT' )  ? WP_MAX_MEMORY_LIMIT : '';
				$php_limit_b   = self::parse_size_to_bytes( is_string( $php_limit_raw ) ? $php_limit_raw : '' );
				$wp_limit_b    = self::parse_size_to_bytes( is_string( $wp_limit_raw )  ? $wp_limit_raw  : '' );
				$wpmax_b       = self::parse_size_to_bytes( is_string( $wpmax_raw )     ? $wpmax_raw     : '' );
				$effective_b   = self::get_effective_memory_limit_bytes();
				$effective_mb  = $effective_b > 0 ? (int) round( $effective_b / 1048576 ) : 0;

				// Threshold settings
				$s_opts        = self::get_settings();
				$warn_pct      = (int) ( $s_opts['warn_pct']     ?? 70 );
				$danger_pct    = (int) ( $s_opts['danger_pct']   ?? 85 );
				$critical_pct  = (int) ( $s_opts['critical_pct'] ?? 95 );
				$warn_mb       = $effective_mb > 0 ? round( $effective_mb * $warn_pct     / 100, 1 ) : null;
				$danger_mb     = $effective_mb > 0 ? round( $effective_mb * $danger_pct   / 100, 1 ) : null;
				$critical_mb   = $effective_mb > 0 ? round( $effective_mb * $critical_pct / 100, 1 ) : null;

				// ── Bewertungen ────────────────────────────────────────────────
				$recs = array(); // ['icon','color','bg','text']

				// Effektives Limit
				if ( $effective_mb <= 0 ) {
					$recs[] = array( 'icon' => '🆘', 'color' => '#8B0000', 'bg' => '#fdf2f2',
						'text' => __('Could not determine the effective memory limit. Check that WP_MEMORY_LIMIT or PHP memory_limit are set.', 'wp-memory-usage') );
				} elseif ( $effective_mb < 64 ) {
					$recs[] = array( 'icon' => '🆘', 'color' => '#8B0000', 'bg' => '#fdf2f2',
						'text' => __('Effective limit is very low:', 'wp-memory-usage').$effective_mb.' MB.'.__('Most WordPress sites need at least 128 MB; WooCommerce, page builders or heavy plugins often need 256 MB or more. You will likely see out-of-memory errors.', 'wp-memory-usage') );
				} elseif ( $effective_mb < 128 ) {
					$recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec',
						'text' => __('Effective limit is', 'wp-memory-usage').' {'.($effective_mb).' MB.'.__('Tight for a typical WordPress site. Consider raising to at least 128 MB (256 MB recommended). Add to wp-config.php: define(\'WP_MEMORY_LIMIT\', \'256M\');', 'wp-memory-usage') );
				} elseif ( $effective_mb < 256 ) {
					$recs[] = array( 'icon' => '✅', 'color' => '#2d6a2d', 'bg' => '#f2faf2',
						'text' => __('Effective limit is', 'wp-memory-usage').' {'.($effective_mb).' MB.'.__('Acceptable for most sites. For WooCommerce, LMS or heavy builders, 256 MB+ is better.', 'wp-memory-usage') );
				} else {
					$recs[] = array( 'icon' => '✅', 'color' => '#2d6a2d', 'bg' => '#f2faf2',
						'text' => __('Effective limit is', 'wp-memory-usage').' ('.($effective_mb).' MB.'.__('Good.', 'wp-memory-usage') );
				}

				// PHP vs WP Limit-Verhältnis
				if ( $php_limit_b > 0 && $wp_limit_b > 0 && $wp_limit_b > $php_limit_b ) {
					$recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec',
						'text' => 'WP_MEMORY_LIMIT (' . ( (string) $wp_limit_raw ) . ') '.__('is higher than PHP memory_limit', 'wp-memory-usage').' (' . ( (string) $php_limit_raw ) . '). '.__('WordPress cannot exceed the PHP ceiling – the PHP limit wins. Raise memory_limit in php.ini / .htaccess / php_value.', 'wp-memory-usage') );
				}
				if ( $wp_limit_b > 0 && $wpmax_b > 0 && $wp_limit_b > $wpmax_b ) {
					$recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec',
						'text' => 'WP_MEMORY_LIMIT (' . ( (string) $wp_limit_raw ) . ') '.__('is higher than WP_MAX_MEMORY_LIMIT', 'wp-memory-usage').' (' . ( (string) $wpmax_raw ) . '). '.__('WP_MAX_MEMORY_LIMIT should be ≥ WP_MEMORY_LIMIT.', 'wp-memory-usage') );
				}

				// Threshold-Abstände
				$gap_warn_danger   = $danger_pct  - $warn_pct;
				$gap_danger_crit   = $critical_pct - $danger_pct;
				if ( $gap_warn_danger < 5 ) {
					$recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec',
						'text' => __( 'Warning ({', 'wp-memory-usage').($warn_pct).'}%) '.__('and Danger', 'wp-memory-usage').' ({'.($danger_pct).'}%) '.__('thresholds are very close together (gap:', 'wp-memory-usage').' {'.($gap_warn_danger).'}%). '.__('Consider a gap of at least 10% so you have time to react between levels.', 'wp-memory-usage') );
				}
				if ( $gap_danger_crit < 5 ) {
					$recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec',
						'text' => __('Danger', 'wp-memory-usage').' ({'.($danger_pct).'}%) '.__('and Critical', 'wp-memory-usage').' ({'.($critical_pct).'}%) '.__('thresholds are very close together (gap', 'wp-memory-usage' ).': {'.($gap_danger_crit).'}%). '.__('Consider a gap of at least 5%.', 'wp-memory-usage' ));
				}
				if ( $warn_pct < 50 ) {
					$recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec',
						'text' => __('Warning threshold is very low', 'wp-memory-usage').' ({'.($warn_pct).'}%). '.__('You will receive many false-positive alerts on normal pages. A value of 65–75% is typical.', 'wp-memory-usage') );
				}
				if ( $critical_pct < 90 ) {
					$recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec',
						'text' => __('Critical threshold is set to', 'wp-memory-usage').' {'.($critical_pct).'}% – '.__('that leaves little headroom before actual out-of-memory errors. Consider raising to 92–95%.', 'wp-memory-usage') );
				}
				if ( $critical_pct > 98 ) {
					$recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec',
						'text' => __('Critical threshold is very high', 'wp-memory-usage').' ({'.($critical_pct).'}%). '.__('At this level you may already be getting OOM errors before the alert fires. 95% is a safer upper bound.', 'wp-memory-usage') );
				}
				if ( count( $recs ) === 1 && $recs[0]['icon'] === '✅' ) {
					$recs[] = array( 'icon' => '✅', 'color' => '#2d6a2d', 'bg' => '#f2faf2',
						'text' => __('Threshold settings look good: Warn', 'wp-memory-usage').' {'.($warn_pct).'}% / '.__('Danger', 'wp-memory-usage').' {'.($danger_pct).'}% / '.__('Critical', 'wp-memory-usage').' {'.($critical_pct).'}%.' );
				}
				?>
				<p><?php echo esc_html__( 'This tab shows the memory limits that actually apply to your site. Memory issues are often caused by a limit that is lower than expected.', 'wp-memory-usage' ); ?></p>
				<table class="widefat striped" style="max-width: 1000px;">
					<thead><tr>
						<th><?php echo esc_html__( 'Setting', 'wp-memory-usage' ); ?></th>
						<th><?php echo esc_html__( 'Value', 'wp-memory-usage' ); ?></th>
						<th><?php echo esc_html__( 'Meaning', 'wp-memory-usage' ); ?></th>
					</tr></thead>
					<tbody>
						<tr>
							<td><code>WP_MEMORY_LIMIT</code></td>
							<td><?php echo $wp_limit_raw ? esc_html( (string) $wp_limit_raw ) : '<em>' . esc_html__( 'not defined', 'wp-memory-usage' ) . '</em>'; ?></td>
							<td><?php echo esc_html__( 'Memory limit for the regular (frontend) WordPress runtime. WordPress may increase the PHP limit up to this value if possible.', 'wp-memory-usage' ); ?></td>
						</tr>
						<tr>
							<td><code>WP_MAX_MEMORY_LIMIT</code></td>
							<td><?php echo $wpmax_raw ? esc_html( (string) $wpmax_raw ) : '<em>' . esc_html__( 'not defined', 'wp-memory-usage' ) . '</em>'; ?></td>
							<td><?php echo esc_html__( 'Memory limit for admin-area tasks that can be heavier (updates, editor, imports). This is often higher than WP_MEMORY_LIMIT.', 'wp-memory-usage' ); ?></td>
						</tr>
						<tr>
							<td><code>PHP memory_limit</code></td>
							<td><?php echo $php_limit_raw ? esc_html( (string) $php_limit_raw ) : '<em>' . esc_html__( 'unknown', 'wp-memory-usage' ) . '</em>'; ?></td>
							<td><?php echo esc_html__( 'The PHP-level memory limit set by your server / hosting. This is the hard ceiling unless you can raise it in PHP configuration.', 'wp-memory-usage' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php echo esc_html__( 'Effective limit used for alerts', 'wp-memory-usage' ); ?></strong></td>
							<td><strong><?php echo $effective_b > 0 ? esc_html( self::format_bytes( (int) $effective_b ) ) : esc_html__( 'unlimited/unknown', 'wp-memory-usage' ); ?></strong></td>
							<td><?php echo esc_html__( 'For safety, this plugin uses the lower of the WordPress and PHP limits (when both are set). That reflects what will actually break first.', 'wp-memory-usage' ); ?></td>
						</tr>
					</tbody>
				</table>

				<?php if ( $effective_mb > 0 ) : ?>
				<h3 style="margin-top:20px;"><?php echo esc_html__( 'Alert thresholds in absolute values', 'wp-memory-usage' ); ?></h3>
				<table class="widefat striped" style="max-width:600px;">
					<thead><tr>
						<th><?php echo esc_html__( 'Level', 'wp-memory-usage' ); ?></th>
						<th><?php echo esc_html__( '%', 'wp-memory-usage' ); ?></th>
						<th><?php echo esc_html__( '≈ MB', 'wp-memory-usage' ); ?></th>
					</tr></thead>
					<tbody>
						<tr><td><strong style="color:#f0ad4e;">⚠ <?php echo esc_html__( 'Warn', 'wp-memory-usage' ); ?></strong></td>
							<td><?php echo esc_html($warn_pct); ?>%</td>
							<td><?php echo esc_html($warn_mb !== null ? $warn_mb . ' MB' : '–'); ?></td></tr>
						<tr><td><strong style="color:#d9534f;">🔴 <?php echo esc_html__( 'Danger', 'wp-memory-usage' ); ?></strong></td>
							<td><?php echo esc_html($danger_pct); ?>%</td>
							<td><?php echo esc_html($danger_mb !== null ? $danger_mb . ' MB' : '–'); ?></td></tr>
						<tr><td><strong style="color:#8B0000;">🆘 <?php echo esc_html__( 'Critical', 'wp-memory-usage' ); ?></strong></td>
							<td><?php echo esc_html($critical_pct); ?>%</td>
							<td><?php echo esc_html($critical_mb !== null ? $critical_mb . ' MB' : '–'); ?></td></tr>
					</tbody>
				</table>
				<?php endif; ?>

				<h3 style="margin-top:20px;"><?php echo esc_html__( 'Assessment & Recommendations', 'wp-memory-usage' ); ?></h3>
				<?php foreach ( $recs as $rec ) : ?>
				<div style="display:flex;align-items:flex-start;gap:10px;padding:10px 14px;margin-bottom:8px;border-radius:5px;border-left:4px solid <?php echo esc_attr( $rec['color'] ); ?>;background:<?php echo esc_attr( $rec['bg'] ); ?>;">
					<span style="font-size:18px;line-height:1.3;"><?php echo esc_html($rec['icon']); ?></span>
					<span style="color:<?php echo esc_attr( $rec['color'] ); ?>;font-size:13px;"><?php echo esc_html( $rec['text'] ); ?></span>
				</div>
				<?php endforeach; ?>

			<?php elseif ( 'actions' === $tab ) : ?>
				<h2><?php echo esc_html__( 'What you can do', 'wp-memory-usage' ); ?></h2>
				<p><?php echo esc_html__( 'This tab explains practical next steps when you receive a memory alert. You do not need to be a developer to apply most of these actions.', 'wp-memory-usage' ); ?></p>
				<h3><?php echo esc_html__( 'Step 1: Understand the severity', 'wp-memory-usage' ); ?></h3>
				<ul style="list-style: disc; padding-left: 20px;">
					<li><strong><?php echo esc_html__( 'Warning', 'wp-memory-usage' ); ?></strong>: <?php echo esc_html__( 'Close to the limit. Usually no immediate outage, but you should watch it.', 'wp-memory-usage' ); ?></li>
					<li><strong><?php echo esc_html__( 'Danger', 'wp-memory-usage' ); ?></strong>: <?php echo esc_html__( 'High risk. Actions like editing, imports, backups or WooCommerce tasks may fail.', 'wp-memory-usage' ); ?></li>
					<li><strong><?php echo esc_html__( 'Critical', 'wp-memory-usage' ); ?></strong>: <?php echo esc_html__( 'Very likely to trigger "Allowed memory size exhausted". Act now to avoid outages.', 'wp-memory-usage' ); ?></li>
				</ul>
				<h3><?php echo esc_html__( 'Step 2: Identify what triggered it', 'wp-memory-usage' ); ?></h3>
				<p><?php echo esc_html__( 'Open the "Digest" or "History" tab and look at the context (URL, admin screen, AJAX action, REST route, cron). This tells you which page is involved.', 'wp-memory-usage' ); ?></p>
				<h3><?php echo esc_html__( 'Common actions', 'wp-memory-usage' ); ?></h3>
				
				
				<ol style="list-style: decimal; padding-left: 20px;">
					<li><?php echo esc_html__( 'If it happens during imports/backups/crawlers: schedule these jobs at low traffic times.', 'wp-memory-usage' ); ?></li>
					<li>
						<?php echo esc_html__( 'Increase the PHP memory limit if your hosting allows it (often the quickest fix).', 'wp-memory-usage' ); ?><br>
						&bull; <?php echo esc_html__( 'In wp-config.php you can define WP_MEMORY_LIMIT and WP_MAX_MEMORY_LIMIT (example: define(\'WP_MEMORY_LIMIT\', "256M");). This affects WordPress, but cannot exceed the server PHP limit.', 'wp-memory-usage' ); ?>
						<br>
						&bull; <?php echo esc_html__( 'The PHP memory_limit is controlled by your hosting environment (php.ini, user.ini, .htaccess, or a hosting control panel). If it is lower than your WordPress settings, PHP wins.', 'wp-memory-usage' ); ?>
						<br>
						&bull; <?php echo esc_html__( 'After changing values, clear caches (object cache, page cache) and re-test the action that caused the peak.', 'wp-memory-usage' ); ?>
					</li>
					<li><?php echo esc_html__( 'Update WordPress core, themes and plugins. Memory leaks and inefficiencies are often fixed in updates.', 'wp-memory-usage' ); ?></li>
					<li><?php echo esc_html__( 'If it happens on frontend pages: check the page and its plugins (builder, gallery, search, related posts, cache). Disable suspects temporarily to confirm.', 'wp-memory-usage' ); ?></li>
				</ol>
				<h3><?php echo esc_html__( 'When to adjust thresholds', 'wp-memory-usage' ); ?></h3>
				<p><?php echo esc_html__( 'If you receive alerts during expected heavy tasks (e.g. backups), increase the Warning/Danger thresholds slightly. Keep Critical high (e. g. 95%) so you still get a real emergency signal.', 'wp-memory-usage' ); ?></p>

			<?php elseif ( 'digest' === $tab ) :
			// ── Digest-Datei löschen (POST-Action) ───────────────────────────
			$delete_notice = '';
			if (
				isset( $_POST['wpmu_digest_delete'], $_POST['wpmu_digest_delete_nonce'], $_POST['wpmu_digest_delete_file'] ) &&
				wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpmu_digest_delete_nonce'] ) ), 'wpmu_digest_delete' ) &&
				current_user_can( self::CAP )
			) {
				$del_fn = sanitize_file_name( wp_unslash( $_POST['wpmu_digest_delete_file'] ) );
				// Nur digest_*.cgibak erlaubt
				if ( preg_match( '/^digest_[\d\-]+\.cgibak$/', $del_fn ) ) {
					$del_path = self::WPMU_LOG_PATH . $del_fn;
					if ( file_exists( $del_path ) && wp_delete_file( $del_path ) ) {
						$delete_notice = '<div class="notice notice-success is-dismissible"><p>' .
							sprintf( 
							/* translators: %s: filename that was deleted */
							esc_html__( 'Deleted: %s', 'wp-memory-usage' ), 
							esc_html( $del_fn ) ) .
							'</p></div>';
					} else {
						$delete_notice = '<div class="notice notice-error is-dismissible"><p>' .
							sprintf( 
							/* translators: %s: filename that could not deleted */
							esc_html__( 'Could not delete: %s', 'wp-memory-usage' ), 
							esc_html( $del_fn ) ) .
							'</p></div>';
					}
				} elseif ("all"==$del_fn) {
					$del_path = self::WPMU_LOG_PATH . $del_fn;
					#error_log("del: ".$del_path);
					$del_ok = 0;
					$del_notok = 0;
					$del_no = 0;
					foreach ( glob( self::WPMU_LOG_PATH . 'digest_*.cgibak' ) as $bak ) {
						#error_log("do del: ".$bak);
						$del_no++;
						if ( file_exists( $bak ) && wp_delete_file( $bak ) ) {
							$del_ok++;
						} else {
							$del_notok++;
						}
					}
					if ( $del_ok==$del_no ) {
						$delete_notice = '<div class="notice notice-success is-dismissible"><p>' .
							sprintf( 
							/* translators: %s: filename that could not deleted */
							esc_html__( 'Deleted: %s files', 'wp-memory-usage' ), 
							esc_html( $del_ok ) ) .
							'</p></div>';
					} else {
						$delete_notice = '<div class="notice notice-error is-dismissible"><p>' .
							sprintf( 
							/* translators: %s: filename that could not deleted */
							esc_html__( 'Could not delete: %s files', 'wp-memory-usage' ), 
							esc_html( $del_notok ) ) .
							'</p></div>';
					}
				}
			}
			echo wp_kses_post( $delete_notice );

			// ── Alle Digest-Dateien einlesen ──────────────────────────────────
			$digest_files = array();
			foreach ( glob( self::WPMU_LOG_PATH . 'digest_*.cgibak' ) as $bak ) {
				$fn = basename( $bak );
				$raw = str_replace( array( 'digest_', '.cgibak' ), '', $fn );
				$dt  = DateTime::createFromFormat( 'Y-m-d-H-i-s', $raw );
				$label = $dt
					? wp_date( get_option('date_format') . ', ' . get_option('time_format'), $dt->getTimestamp() )
					: $fn; // Fallback: Rohdateiname, falls Format nicht passt				
				$digest_files[ $fn ] = $label;
			}
			krsort( $digest_files ); // neueste zuerst

			// ── Auswahl auslesen (Nonce-gesichert) ────────────────────────────
			$selected_file = '';
			$merge_all     = false;
			if (
				isset( $_GET['wpmu_digest_nonce'], $_GET['wpmu_digest_sel'] ) &&
				wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['wpmu_digest_nonce'] ) ), 'wpmu_digest_sel' )
			) {
				$raw = sanitize_text_field( wp_unslash( $_GET['wpmu_digest_sel'] ) );
				if ( $raw === '__all__' ) {
					$merge_all = true;
				} elseif ( isset( $digest_files[ $raw ] ) ) {
					$selected_file = $raw;
				}
			}

			// ── Daten laden ───────────────────────────────────────────────────
			$data = array();
			if ( $merge_all && ! empty( $digest_files ) ) {
				foreach ( array_keys( $digest_files ) as $fn ) {
					$d = self::get_from_file( $fn );
					if ( ! is_array( $d ) || empty( $d ) ) { continue; }
					if ( empty( $data ) ) {
						$data = $d;
						continue;
					}
					foreach ( $d['status'] as $s => $cnt ) {
						$data['status'][ $s ] = ( $data['status'][ $s ] ?? 0 ) + $cnt;
					}
					foreach ( ( $d['type'] ?? array() ) as $t => $cnt ) {
						$data['type'][ $t ] = ( $data['type'][ $t ] ?? 0 ) + $cnt;
					}
					foreach ( ( $d['uri'] ?? array() ) as $uri => $ud ) {
						if ( ! isset( $data['uri'][ $uri ] ) ) {
							$data['uri'][ $uri ] = array( 'total' => 0, 'warn' => 0, 'danger' => 0, 'critical' => 0, 'avg_usage' => 0, 'max_usage' => 0 );
						}
						$existing_total = $data['uri'][ $uri ]['total'];
						$new_total      = $ud['total'] ?? 0;
						$merged_total   = $existing_total + $new_total;
						$data['uri'][ $uri ]['avg_usage'] = $merged_total > 0
							? (int) round( ( $data['uri'][ $uri ]['avg_usage'] * $existing_total + ( $ud['avg_usage'] ?? 0 ) * $new_total ) / $merged_total )
							: 0;
						$data['uri'][ $uri ]['total']    = $merged_total;
						$data['uri'][ $uri ]['warn']     = ( $data['uri'][ $uri ]['warn']     ?? 0 ) + ( $ud['warn']     ?? 0 );
						$data['uri'][ $uri ]['danger']   = ( $data['uri'][ $uri ]['danger']   ?? 0 ) + ( $ud['danger']   ?? 0 );
						$data['uri'][ $uri ]['critical'] = ( $data['uri'][ $uri ]['critical'] ?? 0 ) + ( $ud['critical'] ?? 0 );
						if ( ( $ud['max_usage'] ?? 0 ) > $data['uri'][ $uri ]['max_usage'] ) {
							$data['uri'][ $uri ]['max_usage'] = $ud['max_usage'];
						}
					}
					if ( isset( $d['interval']['first'] ) && $d['interval']['first'] < $data['interval']['first'] ) {
						$data['interval']['first'] = $d['interval']['first'];
					}
					if ( isset( $d['interval']['last'] ) && $d['interval']['last'] > $data['interval']['last'] ) {
						$data['interval']['last'] = $d['interval']['last'];
					}
					foreach ( ( $d['interval']['per_minute'] ?? array() ) as $min_key => $cnt ) {
						$data['interval']['per_minute'][ $min_key ] = ( $data['interval']['per_minute'][ $min_key ] ?? 0 ) + $cnt;
					}
				}
				if ( isset( $data['interval']['first'], $data['interval']['last'] ) ) {
					$data['interval']['duration_sec'] = $data['interval']['last'] - $data['interval']['first'];
				}
			} elseif ( $selected_file ) {
				$data = self::get_from_file( $selected_file );
				if ( ! is_array( $data ) ) { $data = array(); }
			} elseif ( ! empty( $digest_files ) ) {
				$selected_file = array_key_first( $digest_files );
				$data = self::get_from_file( $selected_file );
				if ( ! is_array( $data ) ) { $data = array(); }
			}

			// ── Auswahl-Formular ──────────────────────────────────────────────
			$digest_nonce = wp_create_nonce( 'wpmu_digest_sel' );
			?>
			<div style="margin:16px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#f9f9f9;padding:12px 16px;border:1px solid #ddd;border-radius:6px;">
				<form method="get" style="display:contents;">
					<input type="hidden" name="page" value="wpmu-memory-alerts">
					<input type="hidden" name="tab" value="digest">
					<input type="hidden" name="_wpmu_tab_nonce" value="<?php echo esc_attr( $tab_nonce ); ?>">
					<input type="hidden" name="wpmu_digest_nonce" value="<?php echo esc_attr( $digest_nonce ); ?>">
					<label for="wpmu_digest_sel" style="font-weight:600;white-space:nowrap;">
						<?php echo esc_html__( 'Show Digest:', 'wp-memory-usage' ); ?>
					</label>
					<select name="wpmu_digest_sel" id="wpmu_digest_sel" onchange="this.form.submit()"
					        style="min-width:280px;padding:5px 8px;">
						<?php if ( empty( $digest_files ) ) : ?>
							<option value=""><?php echo esc_html__( '— no digest files found —', 'wp-memory-usage' ); ?></option>
						<?php else : ?>
							<?php if ( count( $digest_files ) > 1 ) : ?>
							<option value="__all__" <?php selected( $merge_all, true ); ?>>
								<?php printf( 
								/* translators: %d: number of digest files */
								esc_html__( '⊕ Merge all %d digest files', 'wp-memory-usage' ), count( $digest_files ) ); ?>
							</option>
							<option disabled>──────────────────────────────</option>
							<?php endif; ?>
							<?php foreach ( $digest_files as $fn => $label ) : ?>
							<option value="<?php echo esc_attr( $fn ); ?>"
							        <?php selected( ! $merge_all && $selected_file === $fn ); ?>>
								<?php 
								echo esc_html( $label ); ?>
							</option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
					<noscript><button type="submit" class="button"><?php echo esc_html__( 'Show', 'wp-memory-usage' ); ?></button></noscript>
				</form>
				<?php if ( $selected_file && ! $merge_all ) : ?>
				<form method="post" style="display:inline;"
				      onsubmit="return confirm('<?php echo esc_js( __( 'Delete this digest file? This cannot be undone.', 'wp-memory-usage' ) ); ?>');">
					<input type="hidden" name="page" value="wpmu-memory-alerts">
					<input type="hidden" name="tab"  value="digest">
					<input type="hidden" name="_wpmu_tab_nonce"         value="<?php echo esc_attr( $tab_nonce ); ?>">
					<input type="hidden" name="wpmu_digest_delete"      value="1">
					<input type="hidden" name="wpmu_digest_delete_file" value="<?php echo esc_attr( $selected_file ); ?>">
					<input type="hidden" name="wpmu_digest_delete_nonce"
					       value="<?php echo esc_attr( wp_create_nonce( 'wpmu_digest_delete' ) ); ?>">
					<button type="submit" class="button button-small"
					        style="border-color:#d9534f;color:#d9534f;background:#fff;">
						🗑 <?php echo esc_html__( 'Delete this file', 'wp-memory-usage' ); ?>
					</button>
				</form>
				<form method="post" style="display:inline;"
				      onsubmit="return confirm('<?php echo esc_js( __( 'Delete all digest files? This cannot be undone.', 'wp-memory-usage' ) ); ?>');">
					<input type="hidden" name="page" value="wpmu-memory-alerts">
					<input type="hidden" name="tab"  value="digest">
					<input type="hidden" name="_wpmu_tab_nonce"         value="<?php echo esc_attr( $tab_nonce ); ?>">
					<input type="hidden" name="wpmu_digest_delete"      value="1">
					<input type="hidden" name="wpmu_digest_delete_file" value="<?php echo esc_attr( "all" ); ?>">
					<input type="hidden" name="wpmu_digest_delete_nonce"
					       value="<?php echo esc_attr( wp_create_nonce( 'wpmu_digest_delete' ) ); ?>">
					<button type="submit" class="button button-small"
					        style="border-color:#d9534f;color:#d9534f;background:#fff;">
						🗑 <?php echo esc_html__( 'Delete all files', 'wp-memory-usage' ); ?>
					</button>
				</form>
				<?php endif; ?>
				<?php if ( $merge_all ) : ?>
					<span style="background:#e7f3ff;border:1px solid #3498db;border-radius:4px;padding:4px 10px;font-size:12px;color:#0c5d9e;font-weight:600;">
						<?php printf( 
						/* translators: %d: number of merged digest files */
						esc_html__( 'Merged: %d files', 'wp-memory-usage' ), count( $digest_files ) ); ?>
					</span>
				<?php endif; 
					$currenttime = wp_date( get_option('date_format') . ', ' . get_option('time_format') );
					echo esc_html__( 'Servertime', 'wp-memory-usage' ). ": ".esc_html($currenttime); 
				?>
			</div>
			<?php
			if (count($data)>0) {
			?>
				<table>
				<tr><td valign="top">
					<!-- STATUS -->
					<table class="widefat striped" style="max-width:400px;">
						<thead><tr><th colspan="2"><h3><?php echo esc_html__( 'Status Summary', 'wp-memory-usage' ); ?></h3></th></tr></thead>
						<thead><tr><th><?php echo esc_html__( 'Status', 'wp-memory-usage' ); ?></th><th><?php echo esc_html__( 'Occurrences', 'wp-memory-usage' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $data['status'] as $s => $count ) :
							$color = match( $s ) {
								'warn'     => '#f0ad4e',
								'danger'   => '#d9534f',
								'critical' => '#8B0000',
								default    => '#5cb85c',
							};
							$outtxt = match( $s ) {
								'warn'     => __('warn', 'wp-memory-usage' ),
								'danger'   => __('danger', 'wp-memory-usage' ),
								'critical' => __('critical', 'wp-memory-usage' ),
								default    => __('ok', 'wp-memory-usage' ),
							};
						?>
						<tr>
							<td><strong style="color:<?php echo esc_html($color); ?>"><?php echo esc_html(strtoupper( $outtxt )); ?></strong></td>
							<td><?php echo esc_html($count); ?></td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</td><td valign="top">
					<!-- TYP -->
					<table class="widefat striped" style="max-width:400px;">
						<thead><tr><th colspan="2"><h3><?php echo esc_html__( 'Request types', 'wp-memory-usage' ); ?></h3></th></tr></thead>
						<thead><tr><th><?php echo esc_html__( 'Type', 'wp-memory-usage' ); ?></th><th><?php echo esc_html__( 'Occurrences', 'wp-memory-usage' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $data['type'] as $type => $count ) : ?>
						<tr>
							<td><?php echo esc_html( $type ); ?></td>
							<td><?php echo esc_html($count); ?></td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</td><td valign="top">
					<!-- ZEITINTERVALL -->
					<?php
					$dur  = $data['interval']['duration_sec'];
					$mins = floor( $dur / 60 );
					$secs = $dur % 60;
					?>
					<!-- PRO INTERVALL -->
					<?php
					$interval_min = 60 * 3;
					$per_interval = array();
					foreach ( $data['interval']['per_minute'] as $minute => $count ) {
						$ts_slot  = strtotime( $minute );
						$slot_ts  = floor( $ts_slot / ( $interval_min * 60 ) ) * ( $interval_min * 60 );
						$slot_key = wp_date( get_option('date_format') . ', ' . get_option('time_format'), $slot_ts ); 
						$per_interval[ $slot_key ] = ( $per_interval[ $slot_key ] ?? 0 ) + $count;
					}
					$max_count = $per_interval ? max( $per_interval ) : 1;
					?>
					<table class="widefat striped" style="max-width:600px;">
					<thead><tr><th colspan="3"><h3><?php echo esc_html__( 'Events each', 'wp-memory-usage' ) . " ". esc_html($interval_min) . " " . esc_html__( 'Minutes', 'wp-memory-usage' ); ?></h3></th></tr></thead>
					<tr><th colspan="3">
						<?php echo esc_html__( 'From', 'wp-memory-usage' ); ?>: <strong><?php echo esc_html(wp_date( get_option('date_format') . ', ' . get_option('time_format'), $data['interval']['first'] )); ?></strong> &nbsp;|&nbsp;
						<?php echo esc_html__( 'To', 'wp-memory-usage' ); ?>: <strong><?php echo esc_html(wp_date( get_option('date_format') . ', ' . get_option('time_format'), $data['interval']['last']  )); ?></strong> &nbsp;|&nbsp;
						<?php echo esc_html__( 'Duration', 'wp-memory-usage' ); ?>: <strong><?php echo esc_html($mins) . " " . esc_html__( 'min.', 'wp-memory-usage' ) . " ". esc_html($secs) . " " . esc_html__( 'sec.', 'wp-memory-usage' ); ?> </strong>
					</th></tr>
						<thead><tr><th><?php echo esc_html__( 'Time window', 'wp-memory-usage' ); ?> </th><th><?php echo esc_html__( 'Occurrences', 'wp-memory-usage' ); ?> </th><th><?php echo esc_html__( 'Event Distribution', 'wp-memory-usage' ); ?> </th></tr></thead>
						<tbody>
						<?php foreach ( $per_interval as $slot => $count ) :
							$bar_width = round( ( $count / $max_count ) * 200 );
							$bar_color = $count >= 10 ? '#d9534f' : ( $count >= 5 ? '#f0ad4e' : '#5cb85c' );
						?>
						<tr>
							<td><?php echo esc_html( $slot ); ?> – <?php echo esc_html( 
								wp_date( 'H:i', strtotime( $slot ) + $interval_min * 60 ) 
								); 
								?></td>
							<td><strong><?php echo esc_html($count); ?></strong></td>
							<td><div style="width:<?php echo esc_attr($bar_width); ?>px;height:14px;background:<?php echo esc_attr($bar_color); ?>;border-radius:3px;"></div></td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</td></tr></table>

				<!-- ======================================================
				     URI-TABELLE: sortierbar, Spalten ausblendbar, avg + max
				     ====================================================== -->
				<h3>URIs</h3>
				<?php
				uasort( $data['uri'], fn( $a, $b ) => $b['total'] <=> $a['total'] );
				$uri_json = json_encode( $data['uri'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP );
				$home_url = json_encode( trailingslashit( home_url() ) );
				?>
				<style>
				.wpmu-uri-wrap { max-width: 1150px; margin-top: 8px; }
				.wpmu-uri-controls {
					display: flex; align-items: center; gap: 10px;
					flex-wrap: wrap; margin-bottom: 10px;
				}
				.wpmu-toggle-btn {
					display: inline-flex; align-items: center; gap: 4px;
					padding: 4px 11px; border-radius: 4px; border: 2px solid transparent;
					font-size: 12px; font-weight: 600; cursor: pointer;
					background: #fff; transition: opacity .15s, box-shadow .15s;
					user-select: none; line-height: 1.5;
				}
				.wpmu-toggle-btn[data-col="warn"]     { border-color: #f0ad4e; color: #7a5200; }
				.wpmu-toggle-btn[data-col="danger"]   { border-color: #d9534f; color: #a02020; }
				.wpmu-toggle-btn[data-col="critical"] { border-color: #8B0000; color: #8B0000; }
				.wpmu-toggle-btn.is-hidden { opacity: .35; }
				.wpmu-toggle-btn .btn-icon { font-size: 10px; }
				#wpmu-uri-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
				#wpmu-uri-tbl th {
					background: #f5f5f5; padding: 7px 10px; text-align: left;
					border-bottom: 2px solid #ddd; white-space: nowrap;
					cursor: pointer; user-select: none;
				}
				#wpmu-uri-tbl th:hover { background: #eaeaea; }
				#wpmu-uri-tbl th .si { font-size: 10px; margin-left: 3px; color: #aaa; }
				#wpmu-uri-tbl th.sa  .si::after { content: "▲"; color: #0073aa; }
				#wpmu-uri-tbl th.sd  .si::after { content: "▼"; color: #0073aa; }
				#wpmu-uri-tbl th:not(.sa):not(.sd) .si::after { content: "⇅"; }
				#wpmu-uri-tbl td { padding: 6px 10px; border-bottom: 1px solid #eee; vertical-align: middle; }
				#wpmu-uri-tbl tbody tr:hover td { background: #f9f9f9; }
				#wpmu-uri-tbl td.nr { text-align: right; font-variant-numeric: tabular-nums; }
				.wpmu-badge {
					display: inline-block; padding: 2px 7px; border-radius: 10px;
					font-size: 11px; font-weight: 700;
				}
				.wpmu-bw { background: #f0ad4e; color: #3d2b00; }
				.wpmu-bd { background: #d9534f; color: #fff; }
				.wpmu-bc { background: #8B0000; color: #fff; }
				.wpmu-avg { color: #666; font-size: 12px; }
				.wpmu-max { font-weight: 700; }
				</style>

				<div class="wpmu-uri-wrap">
					<div class="wpmu-uri-controls">
						<strong style="font-size:13px;"><?php echo esc_html__( 'Toggle Columns', 'wp-memory-usage' ); ?>:</strong>
						<button type="button" class="wpmu-toggle-btn" data-col="warn">
							<span class="btn-icon">✓</span> <?php echo esc_html__( '⚠ warn', 'wp-memory-usage' ); ?>
						</button>
						<button type="button" class="wpmu-toggle-btn" data-col="danger">
							<span class="btn-icon">✓</span> <?php echo esc_html__( '🔴 danger', 'wp-memory-usage' ); ?>
						</button>
						<button type="button" class="wpmu-toggle-btn" data-col="critical">
							<span class="btn-icon">✓</span> <?php echo esc_html__( '🆘 critical', 'wp-memory-usage' ); ?>
						</button>
					</div>
					<table id="wpmu-uri-tbl" class="widefat striped">
						<thead>
							<tr>
								<th data-col="uri">URI <span class="si"></span></th>
								<th data-col="total" class="nr sd"><?php echo esc_html__( 'total', 'wp-memory-usage' ); ?> <span class="si"></span></th>
								<th data-col="warn"     class="nr col-warn"    style="text-align:center;"><?php echo esc_html__( '⚠ warn', 'wp-memory-usage' ); ?><span class="si"></span></th>
								<th data-col="danger"   class="nr col-danger"  style="text-align:center;"><?php echo esc_html__( '🔴 danger', 'wp-memory-usage' ); ?><span class="si"></span></th>
								<th data-col="critical" class="nr col-critical" style="text-align:center;"><?php echo esc_html__( '🆘 critical', 'wp-memory-usage' ); ?><span class="si"></span></th>
								<th data-col="avg" class="nr"><?php echo esc_html__( 'Ø average', 'wp-memory-usage' ); ?><span class="si"></span></th>
								<th data-col="max" class="nr"><?php echo esc_html__( 'max', 'wp-memory-usage' ); ?> <span class="si"></span></th>
							</tr>
						</thead>
						<tbody id="wpmu-uri-tbody"></tbody>
					</table>
				</div>
				<?php
				$wpmu_no_data = __( 'No data', 'wp-memory-usage' );
				?>
				<script>
				var WPMU_NO_DATA = <?php echo wp_json_encode( $wpmu_no_data ); ?>;				
				(function () {
					var RAW     = <?php echo wp_json_encode( json_decode($uri_json) ); ?>;
					var HOMEURL = <?php echo wp_json_encode( json_decode($home_url) ); ?>;

					// Build flat row array
					var rows = Object.entries( RAW ).map( function ( e ) {
						var uri = e[0], d = e[1];
						return {
							uri:      uri,
							total:    d.total     || 0,
							warn:     d.warn      || 0,
							danger:   d.danger    || 0,
							critical: d.critical  || 0,
							avg:      d.avg_usage || 0,
							max:      d.max_usage || 0,
						};
					});

					var sortCol  = 'total';
					var sortDir  = 'desc';
					var hidden   = {};   // col -> bool

					function fmtB( b ) {
						b = parseInt( b, 10 ) || 0;
						if ( ! b ) return '–';
						var u = ['B','KB','MB','GB'], i = 0, v = b;
						while ( v >= 1024 && i < u.length - 1 ) { v /= 1024; i++; }
						return ( i === 0 ? v.toFixed(0) : v.toFixed(1) ) + '\u202f' + u[i];
					}

					function esc( s ) {
						return String( s )
							.replace(/&/g,'&amp;').replace(/</g,'&lt;')
							.replace(/>/g,'&gt;').replace(/"/g,'&quot;');
					}

					function render() {
						// Sort
						var sorted = rows.slice().sort( function ( a, b ) {
							var av = a[ sortCol ], bv = b[ sortCol ];
							if ( typeof av === 'string' ) {
								av = av.toLowerCase(); bv = bv.toLowerCase();
								return sortDir === 'asc' ? ( av < bv ? -1 : av > bv ? 1 : 0 )
								                        : ( av > bv ? -1 : av < bv ? 1 : 0 );
							}
							return sortDir === 'asc' ? av - bv : bv - av;
						});

						// Render rows
						var html = '';
						sorted.forEach( function ( r ) {
							var uriDisplay = r.uri.replace( /\?doing_wp_cron=[\d.]+/, '?doing_wp_cron=\u2026' );
							var fullUrl    = HOMEURL + r.uri.replace( /^\//, '' );

							var warnCell = hidden['warn'] ? '' :
								'<td class="nr col-warn" style="text-align:center;">' +
								( r.warn ? '<span class="wpmu-badge wpmu-bw">' + r.warn + '</span>' : '<span style="color:#ccc">–</span>' ) +
								'</td>';
							var dangerCell = hidden['danger'] ? '' :
								'<td class="nr col-danger" style="text-align:center;">' +
								( r.danger ? '<span class="wpmu-badge wpmu-bd">' + r.danger + '</span>' : '<span style="color:#ccc">–</span>' ) +
								'</td>';
							var criticalCell = hidden['critical'] ? '' :
								'<td class="nr col-critical" style="text-align:center;">' +
								( r.critical ? '<span class="wpmu-badge wpmu-bc">' + r.critical + '</span>' : '<span style="color:#ccc">–</span>' ) +
								'</td>';

							html += '<tr>'
								+ '<td><a href="' + esc( fullUrl ) + '" target="_blank" rel="noopener" style="word-break:break-all;">' + esc( uriDisplay ) + '</a></td>'
								+ '<td class="nr"><strong>' + r.total + '</strong></td>'
								+ warnCell
								+ dangerCell
								+ criticalCell
								+ '<td class="nr wpmu-avg">' + fmtB( r.avg ) + '</td>'
								+ '<td class="nr wpmu-max">' + fmtB( r.max ) + '</td>'
								+ '</tr>';
						});
						document.getElementById('wpmu-uri-tbody').innerHTML =
							html || '<tr><td colspan="7" style="color:#999;text-align:center;padding:12px;">' + WPMU_NO_DATA + '</td></tr>';

						// Update header classes + visibility
						document.querySelectorAll('#wpmu-uri-tbl thead th').forEach( function ( th ) {
							th.classList.remove('sa','sd');
							if ( th.dataset.col === sortCol ) {
								th.classList.add( sortDir === 'asc' ? 'sa' : 'sd' );
							}
							var col = th.dataset.col;
							if ( col && ( col === 'warn' || col === 'danger' || col === 'critical' ) ) {
								th.style.display = hidden[ col ] ? 'none' : '';
							}
						});
					}

					// Sort on header click
					document.querySelectorAll('#wpmu-uri-tbl thead th').forEach( function ( th ) {
						th.addEventListener('click', function () {
							var col = this.dataset.col;
							if ( ! col ) return;
							if ( sortCol === col ) {
								sortDir = sortDir === 'asc' ? 'desc' : 'asc';
							} else {
								sortCol = col;
								sortDir = col === 'uri' ? 'asc' : 'desc';
							}
							render();
						});
					});

					// Toggle buttons
					document.querySelectorAll('.wpmu-toggle-btn').forEach( function ( btn ) {
						btn.addEventListener('click', function () {
							var col = this.dataset.col;
							hidden[ col ] = ! hidden[ col ];
							this.classList.toggle('is-hidden', !! hidden[ col ] );
							this.querySelector('.btn-icon').textContent = hidden[ col ] ? '✕' : '✓';
							render();
						});
					});

					render();
				})();
				</script>
			<?PHP
				} else {
					echo "<h2>".esc_html__( 'No digest created yet. See "Digest interval" in the settings.', 'wp-memory-usage' )."</h2>";
				}
			?>

			<?php elseif ( 'history' === $tab ) :
				$opts = self::get_log();
				$totalanz = count($opts);
				$log  = array_slice( $opts, -1 * self::$event_anz_display );
				$log  = array_reverse( $log );
			?>
				<h2><?php 
					if (self::$event_anz_display >= $totalanz) {
						echo esc_html($totalanz) . " " . esc_html__( 'recent events', 'wp-memory-usage' ) . ", ";	
					} else {
						#echo esc_html(self::$event_anz_display) . " " . esc_html__( 'recent events displayed', 'wp-memory-usage' ) . " (" . esc_html($totalanz) .  esc_html__(" stored events", 'wp-memory-usage' ). "), ";	
						echo esc_html( 
							sprintf(
							/* translators: 1: number of displayed events, 2: number of total stored events */
							__( '%1$d recent events displayed (%2$d stored events),', 'wp-memory-usage' ),
							self::$event_anz_display,
							$totalanz
						) );						
					}
					$currenttime = wp_date( get_option('date_format') . ', ' . get_option('time_format') );
					echo esc_html__( 'Servertime', 'wp-memory-usage' ). ": ".esc_html($currenttime); 
				?></h2>
				<?php if ( empty( $log ) ) : ?>
					<p><?php 
					echo esc_html__( 'No events recorded yet.', 'wp-memory-usage' ); 
					echo "<hr>";
					
					$opts = self::get_settings();
					
					$count_off = 0;

					echo esc_html__('Log WARN', 'wp-memory-usage' ).": ";
					if ($opts["logop_warn_pct"]=="0") {
						echo '<span style="color: red;">';
						echo esc_html__('Off', 'wp-memory-usage' );
						echo "</span>";
						$count_off++;
					} else {
						echo esc_html__('On', 'wp-memory-usage' );
					}
					echo "<p>";
					
					echo esc_html__('Log DANGER', 'wp-memory-usage' ).": ";
					if ($opts["logop_danger_pct"]=="0") {
						echo '<span style="color: red;">';
						echo esc_html__('Off', 'wp-memory-usage' );
						echo "</span>";
						$count_off++;
					} else {
						echo esc_html__('On', 'wp-memory-usage' );
					}
					echo "<p>";

					echo esc_html__('Log CRITICAL', 'wp-memory-usage' ).": ";
					if ($opts["logop_critical_pct"]=="0") {
						echo '<span style="color: red;">';
						echo esc_html__('Off', 'wp-memory-usage' );
						echo "</span>";
						$count_off++;
					} else {
						echo esc_html__('On', 'wp-memory-usage' );
					}
					/*
					echo "<p>";
					
					echo __('Log OK', 'wp-memory-usage' ).": ";;
					if ($opts["log_ok"]=="0") {
						echo '<span style="color: red;">';
						echo __('Off', 'wp-memory-usage' );
						echo "</span>";
						$count_off++;
					} else {
						echo __('On', 'wp-memory-usage' );
					}
					*/
					
					if ($count_off==3) {
						echo "<hr>";
						echo '<span style="color: red;">';
						echo esc_html__('Logging switched off', 'wp-memory-usage' );
						echo "</span>";
					}
					#echo "<hr>".print_r($opts, true);
					?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width: 1200px;">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Time', 'wp-memory-usage' ); ?></th>
								<th><?php echo esc_html__( 'State', 'wp-memory-usage' ); ?></th>
								<th><?php echo esc_html__( 'Usage / Limit', 'wp-memory-usage' ); ?></th>
								<th><?php echo esc_html__( 'Type', 'wp-memory-usage' ); ?></th>
								<th><?php echo esc_html__( 'URI', 'wp-memory-usage' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $log as $e1 ) :
								$c    = isset( $e1['c'] ) && is_array( $e1['c'] ) ? $e1['c'] : array();
								$lastmb = (int) $e1['l'];
								$perc   = $lastmb > 0 ? (int) ( ( (int) $e1['u'] ) / $lastmb * 100 ) : 0;
							?>
							<tr>
								<td><?php echo esc_html( 
									wp_date( get_option('date_format') . ', ' . get_option('time_format'), (int) $e1['t'] ) ); 
									?></td>
								<td><strong><?php echo esc_html( strtoupper( (string) $e1['s'] ) ); echo "<br>" . esc_html($perc) . "%"; ?></strong></td>
								<td><?php echo esc_html( self::format_bytes( (int) $e1['u'] ) . ' / ' . ( (int) $e1['l'] > 0 ? self::format_bytes( (int) $e1['l'] ) : __( 'unlimited', 'wp-memory-usage' ) ) ); ?></td>
								<td><?php echo esc_html( (string) ( isset( $c['type'] ) ? $c['type'] : '' ) ); ?></td>
								<td><code><?php echo esc_html( (string) ( isset( $c['uri'] ) ? $c['uri'] : '' ) ); ?></code></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php elseif ( 'check_installation' === $tab ) :
				// ══════════════════════════════════════════════════════
				// CHECK INSTALLATION
				// ══════════════════════════════════════════════════════
				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				WP_Filesystem();
				
				$checks = array(); // array of ['label', 'status' (ok|warn|error), 'detail', 'hint']

				// ── 1. PHP-Mindestversion ──────────────────────────────
				$php_min     = '7.4.0';
				$php_rec     = '8.0.0';
				$php_current = PHP_VERSION;
				if ( version_compare( $php_current, $php_min, '<' ) ) {
					$checks[] = array(
						'status' => 'error',
						'label'  => __( 'PHP Version', 'wp-memory-usage' ),
						'hint'   => __( 'Upgrade PHP to at least 7.4. PHP 8.1+ is recommended.', 'wp-memory-usage' ),
						'detail' => __( 'PHP', 'wp-memory-usage' ) . ' ' . $php_current . ' – ' . __( 'minimum required:', 'wp-memory-usage' ) . ' ' . $php_min,
						
					);
				} elseif ( version_compare( $php_current, $php_rec, '<' ) ) {
					$checks[] = array(
						'label'  => __( 'PHP Version', 'wp-memory-usage' ),
						'status' => 'warn',
						'detail' => __( 'PHP', 'wp-memory-usage' ) . ' ' . $php_current . ' – ' . __( 'works, but PHP', 'wp-memory-usage' ) . ' ' . $php_rec . '+ ' . __( 'is recommended (required for match-expressions)', 'wp-memory-usage' ),
						'hint'   => __( 'Consider upgrading to PHP 8.0 or higher for full feature support.', 'wp-memory-usage' ),
					);
				} else {
					$checks[] = array(
						'label'  => __( 'PHP Version', 'wp-memory-usage' ),
						'status' => 'ok',
						'detail' => __( 'PHP', 'wp-memory-usage' ) . ' ' . $php_current . ': ' . __( 'OK', 'wp-memory-usage' ),
						'hint'   => '',
					);
				}

				// ── 2. PHP-Erweiterungen ───────────────────────────────
				foreach ( array( 'json', 'pcre' ) as $ext ) {
					if ( extension_loaded( $ext ) ) {
						$checks[] = array( 
							'label' => __( 'PHP extension:', 'wp-memory-usage' ) . ' ' . $ext,
							'status' => 'ok',    
							'detail' => 'ext/' . $ext . ' ' . __( 'loaded', 'wp-memory-usage' ),
							'hint'   => ''
							);
					} else {
						$checks[] = array( 
							'label' => __( 'PHP extension:', 'wp-memory-usage' ) . ' ' . $ext,
							'status' => 'error', 
							'detail' => 'ext/' . $ext . ' ' . __( 'NOT loaded – required by this plugin', 'wp-memory-usage' ),
							'hint'   => 'ext/' . $ext . ' ' . __( 'in your php.ini.', 'wp-memory-usage' )
							);
					}
				}

				// ── 3. Log-Verzeichnis: vorhanden / erstellbar ─────────
				$log_dir = self::WPMU_LOG_PATH;
				if ( is_dir( $log_dir ) ) {
					$checks[] = array(
						'label'  => __( 'Log directory exists', 'wp-memory-usage' ),
						'status' => 'ok',
						'detail' => esc_html( $log_dir ),
						'hint'   => '',
					);
				} else {
					// Versuch, es anzulegen
					if ( ! $wp_filesystem->is_dir( $log_dir ) ) {
						$created = $wp_filesystem->mkdir( $log_dir, 0755 );
					}		
					#$created = @mkdir( $log_dir, 0755, true );
					
					if ( $created ) {
						$checks[] = array(
							'label'  => __( 'Log directory exists', 'wp-memory-usage' ),
							'status' => 'warn',
							'detail' => __('Directory did not exist – created now: ', 'wp-memory-usage' ) . esc_html( $log_dir ),
							'hint'   => __('Make sure this path persists across deployments.', 'wp-memory-usage' ),
						);
					} else {
						$checks[] = array(
							'label'  => __( 'Log directory exists', 'wp-memory-usage' ),
							'status' => 'error',
							'detail' => __('Does NOT exist and could not be created: ', 'wp-memory-usage' ) . esc_html( $log_dir ),
							'hint'   => __('Create the directory manually and make it writable for the webserver user (e.g. www-data). Command: mkdir -p ', 'wp-memory-usage' ) . $log_dir . ' && chmod 755 ' . $log_dir,
						);
					}
				}

				// ── 4. Log-Verzeichnis: schreibbar ────────────────────
				if ( is_dir( $log_dir ) ) {
					#if ( is_writable( $log_dir ) ) {
					if ( $wp_filesystem->is_writable( $log_dir ) ) {						
						$checks[] = array( 
							'label' => __('Log directory writable', 'wp-memory-usage' ), 
							'status' => 'ok', 
							'detail' => __('Directory is writable', 'wp-memory-usage' ), 
							'hint' => '' 
							);
					} else {
						$checks[] = array(
							'label'  => __('Log directory writable', 'wp-memory-usage' ),
							'status' => 'error',
							'detail' => __('Directory exists but is NOT writable: ', 'wp-memory-usage' ) . esc_html( $log_dir ),
							'hint'   => __('Run: chmod 755 ', 'wp-memory-usage' ) . $log_dir . __(' (or chown it to the webserver user)', 'wp-memory-usage' ),
						);
					}
				}

				// ── 5. Log-Datei: schreiben & lesen ───────────────────
				if ( is_dir( $log_dir ) && $wp_filesystem->is_writable( $log_dir ) ) {
					$test_file = $log_dir . 'wpmu-test-' . time() . '.tmp';
					$test_data = array( 'wpmu_test' => true, 'ts' => time() );
					// phpcs:disable WordPress.WP.AlternativeFunctions
					$write_ok  = @file_put_contents( $test_file, json_encode( $test_data ) . "\n" );
					// phpcs:enable WordPress.WP.AlternativeFunctions
					if ( $write_ok !== false ) {
						// phpcs:disable WordPress.WP.AlternativeFunctions
						$read_back = @file_get_contents( $test_file );
						// phpcs:enable WordPress.WP.AlternativeFunctions
						$parsed    = json_decode( trim( $read_back ), true );
						wp_delete_file( $test_file );
						if ( is_array( $parsed ) && ! empty( $parsed['wpmu_test'] ) ) {
							$checks[] = array( 
								'label' => __('Log file write & read test', 'wp-memory-usage' ), 
								'status' => 'ok', 
								'detail' => __('Write → read → parse: OK', 'wp-memory-usage' ), 
								'hint' => '' );
						} else {
							$checks[] = array( 
								'label' => __('Log file write & read test', 'wp-memory-usage' ), 
								'status' => 'error', 
								'detail' => __('File written but JSON parse failed', 'wp-memory-usage' ), 
								'hint' => __('Check filesystem for corruption or encoding issues.', 'wp-memory-usage' )
								);
						}
					} else {
						$checks[] = array( 
							'label' => __('Log file write & read test', 'wp-memory-usage' ), 
							'status' => 'error', 
							'detail' => __('Could not write test file to ', 'wp-memory-usage' ) . esc_html( $log_dir ), 
							'hint' => __('Check directory permissions and available disk space.', 'wp-memory-usage' )
							);
					}
				} else {
					$checks[] = array( 
						'label' => __('Log file write & read test', 'wp-memory-usage' ), 
						'status' => 'error', 
						'detail' => __('Skipped – log directory not writable', 'wp-memory-usage' ), 
						'hint' => __('Fix the log directory issue first.', 'wp-memory-usage' )
						);
				}

				// ── 6. Vorhandene Logdateien ──────────────────────────
				if ( is_dir( $log_dir ) ) {
					$log_file     = $log_dir . self::WPMU_LOG_FILE;
					$digest_files = glob( $log_dir . 'digest_*.cgibak' );
					$backup_files = glob( $log_dir . 'wpmu-log_*.cgibak' );
					$n_digest     = is_array( $digest_files ) ? count( $digest_files ) : 0;
					$n_backup     = is_array( $backup_files ) ? count( $backup_files ) : 0;
					$log_exists   = file_exists( $log_file );
					$log_size     = $log_exists ? size_format( filesize( $log_file ) ) : '–';
					$detail =
						__( 'Active log:', 'wp-memory-usage' ) . ' ' .
						( $log_exists ? __( 'exists', 'wp-memory-usage' ) : __( 'not yet created', 'wp-memory-usage' ) ) .
						' (' . __( 'size:', 'wp-memory-usage' ) . ' ' . $log_size . ') | ' .
						__( 'Digest files:', 'wp-memory-usage' ) . ' ' . $n_digest . ' | ' .
						__( 'Backup log files:', 'wp-memory-usage' ) . ' ' . $n_backup;
				
					$checks[] = array(
						'label'  => __('Log files overview', 'wp-memory-usage' ),
						'status' => 'ok',
						'detail' => $detail,
						'hint'   => $n_digest === 0 ? __('No digest files yet – they are created automatically on the first cron run.', 'wp-memory-usage' ) : '',
					);
				}

				// ── 7. WP-Cron aktiv ──────────────────────────────────
				if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
					$checks[] = array(
						'label'  => 'WP-Cron',
						'status' => 'warn',
						'detail' => __('DISABLE_WP_CRON is TRUE – WP-Cron is disabled', 'wp-memory-usage' ),
						'hint'   => __('This plugin uses WP-Cron for log rotation and digest creation. Make sure a real system cron runs wp-cron.php regularly (e.g. every 5 minutes), otherwise no digests will be created.', 'wp-memory-usage' ),
					);
				} else {
					$next_cleanup = wp_next_scheduled( 'wpmu_cleanup_hook' );
					if ( $next_cleanup ) {
						$checks[] = array(
							'label'  => __('WP-Cron: cleanup hook scheduled', 'wp-memory-usage' ),
							'status' => 'ok',
							'detail' => __('Next run: ', 'wp-memory-usage' ) . wp_date( get_option('date_format') . ', ' . get_option('time_format'), $next_cleanup ),
							'hint'   => '',
						);
					} else {
						$checks[] = array(
							'label'  => __('WP-Cron: cleanup hook scheduled', 'wp-memory-usage' ),
							'status' => 'warn',
							'detail' => __('wpmu_cleanup_hook is NOT scheduled', 'wp-memory-usage' ),
							'hint'   => __('Deactivate and reactivate the plugin, or visit the settings page once to trigger rescheduling.', 'wp-memory-usage' ),
						);
					}
				}

				// ── 8. wp_mail verfügbar ──────────────────────────────
				if ( function_exists( 'wp_mail' ) ) {
					$opts_chk = self::get_settings();
					$mail_en  = ! empty( $opts_chk['send_email'] );
					$mail_to  = $opts_chk['email_to'] ?? '';
					$mail_valid = is_email( $mail_to );
					if ( $mail_en && $mail_valid ) {
						$checks[] = array( 
							'label' => __('Email alerts', 'wp-memory-usage' ), 
							'status' => 'ok', 
							'detail' => __('Enabled, recipient: ', 'wp-memory-usage' ) . esc_html( $mail_to ), 
							'hint' => '' 
						);
					} elseif ( $mail_en && ! $mail_valid ) {
						$checks[] = array( 
							'label' => __('Email alerts', 'wp-memory-usage' ), 
							'status' => 'error', 
							'detail' => __('Enabled but recipient address is invalid: "', 'wp-memory-usage' ) . esc_html( $mail_to ) . '"', 
							'hint' => __('Enter a valid email address in Settings.' , 'wp-memory-usage' )
							);
					} else {
						$checks[] = array( 
							'label'  => __( 'Email alerts', 'wp-memory-usage' ),
							'status' => 'warn',
							'detail' => __( 'Disabled in settings', 'wp-memory-usage' ),
							'hint'   => __( 'Enable "Send email alerts" in Settings to receive notifications.', 'wp-memory-usage' ),
						);
					}
				} else {
					$checks[] = array( 
							'label'  => __( 'Email alerts', 'wp-memory-usage' ),
							'status' => 'error',
							'detail' => __( 'wp_mail() not available', 'wp-memory-usage' ),
							'hint'   => __( 'Email sending is not functional on this installation.', 'wp-memory-usage' ),
						);
				}

				// ── 9. memory_get_peak_usage verfügbar ────────────────
				if ( function_exists( 'memory_get_peak_usage' ) ) {
					$checks[] = array( 
						'label' => 'memory_get_peak_usage()', 
						'status' => 'ok', 
						'detail' => __('Function available – current peak: ', 'wp-memory-usage' ) . size_format( memory_get_peak_usage( true ) ), 
						'hint' => '' );
				} else {
					$checks[] = array( 
						'label' => __('memory_get_peak_usage()', 'wp-memory-usage' ), 
						'status' => 'warn', 
						'detail' => __('Not available on this PHP build', 'wp-memory-usage' ),
						'hint' => __('Peak memory tracking is disabled. The plugin falls back to memory_get_usage().', 'wp-memory-usage' )
						);
				}

				// ── 10. Disk-Space ────────────────────────────────────
				if ( is_dir( $log_dir ) ) {
					$free = @disk_free_space( $log_dir );
					if ( $free !== false ) {
						$status_disk = $free < 10 * 1024 * 1024 ? 'warn' : 'ok'; // < 10 MB
						$checks[]    = array(
							'label'  => __('Disk space (log directory)', 'wp-memory-usage' ),
							'status' => $status_disk,
							'detail' => __('Free: ', 'wp-memory-usage' ) . size_format( $free ),
							'hint'   => $status_disk === 'warn' ? __('Very little free disk space – log files may not be written.', 'wp-memory-usage' ) : '',
						);
					}
				}

				// ── Render ─────────────────────────────────────────────
				$count_ok    = count( array_filter( $checks, fn( $c ) => $c['status'] === 'ok' ) );
				$count_warn  = count( array_filter( $checks, fn( $c ) => $c['status'] === 'warn' ) );
				$count_error = count( array_filter( $checks, fn( $c ) => $c['status'] === 'error' ) );

				$overall_color = $count_error > 0 ? '#8B0000' : ( $count_warn > 0 ? '#7a5200' : '#2d6a2d' );
				$overall_bg    = $count_error > 0 ? '#fdf2f2' : ( $count_warn > 0 ? '#fef9ec' : '#f2faf2' );
				$overall_icon  = $count_error > 0 ? '🆘' : ( $count_warn > 0 ? '⚠️' : '✅' );
				$overall_text = $count_error > 0
					? $count_error . ' ' . __( 'error(s),', 'wp-memory-usage' ) . ' ' . $count_warn . ' ' . __( 'warning(s)', 'wp-memory-usage' )
					: ( $count_warn > 0
						? $count_warn . ' ' . __( 'warning(s) – no errors', 'wp-memory-usage' )
						: __( 'All checks passed', 'wp-memory-usage' ) );
				?>
				<h2><?php echo esc_html__( 'Check Installation', 'wp-memory-usage' ); ?></h2>
				<p><?php echo esc_html__( 'This tab checks whether the plugin can work correctly on this server.', 'wp-memory-usage' ); ?></p>

				<div style="display:inline-flex;align-items:center;gap:10px;padding:10px 18px;border-radius:6px;margin-bottom:18px;background:<?php echo esc_attr( $overall_bg ); ?>;border:2px solid <?php echo esc_attr( $overall_color ); ?>;color:<?php echo esc_attr( $overall_color ); ?>;font-weight:700;font-size:14px;">
					<?php echo esc_html($overall_icon); ?> <?php echo esc_html( $overall_text ); ?>
					&nbsp;|&nbsp; ✅ <?php echo (int) $count_ok; ?>
					&nbsp; ⚠️ <?php echo (int) $count_warn; ?>
					&nbsp; 🆘 <?php echo (int) $count_error; ?>
				</div>

				<table class="widefat striped" style="max-width:1100px;">
					<thead>
						<tr>
							<th style="width:24px;"></th>
							<th style="width:280px;"><?php echo esc_html__( 'Check', 'wp-memory-usage' ); ?></th>
							<th><?php echo esc_html__( 'Result', 'wp-memory-usage' ); ?></th>
							<th><?php echo esc_html__( 'Hint / Action', 'wp-memory-usage' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $checks as $chk ) :
						$icon  = $chk['status'] === 'ok' ? '✅' : ( $chk['status'] === 'warn' ? '⚠️' : '🆘' );
						$color = $chk['status'] === 'ok' ? '' : ( $chk['status'] === 'warn' ? '#7a5200' : '#8B0000' );
						$bg    = $chk['status'] === 'ok' ? '' : ( $chk['status'] === 'warn' ? '#fef9ec' : '#fdf2f2' );
					?>
					<tr<?php echo $bg ? ' style="background:' . esc_attr( $bg ) . ';"' : ''; ?>>
						<td style="text-align:center;font-size:16px;"><?php echo esc_html($icon); ?></td>
						<td><strong style="color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $chk['label'] ); ?></strong></td>
						<td style="font-family:monospace;font-size:12px;color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $chk['detail'] ); ?></td>
						<td style="font-size:12px;color:#555;"><?php echo esc_html( $chk['hint'] ); ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

			<?php endif; ?>
		</div>
		<?php
	}

	/** ---------------- Helpers ---------------- */
	private static function format_bytes( $bytes ) {
		$bytes = (int) $bytes;
		if ( $bytes <= 0 ) { return '0 B'; }
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$idx   = 0;
		$val   = (float) $bytes;
		while ( $val >= 1024 && $idx < count( $units ) - 1 ) { $val /= 1024; $idx++; }
		return sprintf( $idx === 0 ? '%.0f %s' : '%.1f %s', $val, $units[ $idx ] );
	}

	private static function format_pct( $usage, $limit ) {
		if ( $limit <= 0 ) { return 'n/a'; }
		return sprintf( '%.1f%%', ( $usage / $limit ) * 100 );
	}

	private static function get_effective_memory_limit_bytes() {
		$php_raw = ini_get( 'memory_limit' );
		$php     = self::parse_size_to_bytes( is_string( $php_raw ) ? $php_raw : '' );
		$wp_raw  = defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '';
		$wp      = self::parse_size_to_bytes( $wp_raw );
		if ( '-1' === (string) $php_raw || -1 === $php ) {
			return $wp > 0 ? $wp : 0;
		}
		if ( $php > 0 && $wp > 0 ) { return min( $php, $wp ); }
		return $php > 0 ? $php : ( $wp > 0 ? $wp : 0 );
	}

	private static function parse_size_to_bytes( $val ) {
		$val = trim( (string) $val );
		if ( '' === $val )   { return 0; }
		if ( '-1' === $val ) { return -1; }
		if ( ctype_digit( $val ) ) { return (int) $val; }
		$unit = strtoupper( substr( $val, -1 ) );
		$num  = substr( $val, 0, -1 );
		if ( ! is_numeric( $num ) ) { return 0; }
		$n = (float) $num;
		switch ( $unit ) {
			case 'K': return (int) round( $n * 1024 );
			case 'M': return (int) round( $n * 1024 * 1024 );
			case 'G': return (int) round( $n * 1024 * 1024 * 1024 );
			case 'T': return (int) round( $n * 1024 * 1024 * 1024 * 1024 );
			default:  return 0;
		}
	}

	/** ---------------- Threshold evaluation ---------------- */
	private static function state_for_usage( $usage, $limit, $opts ) {
		if ( $limit <= 0 ) { return 'ok'; }
		$ratio    = $usage / $limit;
		$warn     = max( 0.01, ( (int) $opts['warn_pct'] )     / 100 );
		$danger   = max( 0.01, ( (int) $opts['danger_pct'] )   / 100 );
		$critical = max( 0.01, ( (int) $opts['critical_pct'] ) / 100 );
		if ( $ratio >= $critical ) { return 'critical'; }
		if ( $ratio >= $danger )   { return 'danger'; }
		if ( $ratio >= $warn )     { return 'warn'; }
		return 'ok';
	}

	private static function state_rank( $state ) {
		switch ( $state ) {
			case 'warn':     return 1;
			case 'danger':   return 2;
			case 'critical': return 3;
			default:         return 0;
		}
	}

	private static function get_current_memory_bytes( $use_peak ) {
		if ( $use_peak && function_exists( 'memory_get_peak_usage' ) ) {
			return (int) memory_get_peak_usage( true );
		}
		return (int) memory_get_usage( true );
	}

} // end class
endif;
// Boot
#WPMU_Threshold_Alerts::init();
