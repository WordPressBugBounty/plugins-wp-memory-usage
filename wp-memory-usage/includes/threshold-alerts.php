<?php
/**
 * Threshold alerts for WP-Memory-Usage
 * - This does NOT attribute memory usage to a specific plugin. It records the request context where the peak happened.
 */
if ( ! defined( 'ABSPATH' ) ) {	exit; }
if ( ! class_exists( 'WPMU_Threshold_Alerts' ) ) :
final class WPMU_Threshold_Alerts {
	const OPTION_KEY  = 'wpmu_threshold_alerts';
	const CAP         = 'manage_options';
	const DIGEST_HOOK = 'wpmu_daily_digest';
	#private static $nolog_admin = FALSE;
	private static $event_anz_display = 10;
	private static $event_anz_store   = 10;
	const SETTING_COLOR_BACK   = "#e7f3ff";
	const SETTING_COLOR_FONT   = "#0c5d9e";
	const SETTING_COLOR_BORDER = "#3498db";

	const WPMU_LOG_FILE = "wpmu-log.cgi";
	const WPMU_LOG_PATH = ABSPATH . '../logs/wpmu/';

	/** DB table name (without prefix) */
	const DB_LOG_TABLE = 'wpmu_log';

	private static $log_path = "";

	/* =========================================================
	 * INIT
	 * ========================================================= */
	public static function init( $event_anz_display, $event_anz_store, $log_path ) {
		if ( is_admin() ) {
			add_action( 'admin_menu',    array( __CLASS__, 'add_admin_menu' ) );
			add_action( 'admin_init',    array( __CLASS__, 'register_settings' ) );
			add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_node' ), 100 );
			self::$event_anz_display = $event_anz_display;
		}
		self::$log_path         = $log_path;
		self::$event_anz_store  = $event_anz_store;
		add_action( 'plugins_loaded', function () {
			register_shutdown_function( array( __CLASS__, 'check_thresholds_on_shutdown' ) );
		} );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_intervals' ) );
		add_action( 'wpmu_cleanup_hook', array( __CLASS__, 'cron_cleanup' ) );
		self::reschedule_cron();

		register_activation_hook( dirname( __FILE__ ) . '/../wp-memory-usage.php', array( __CLASS__, 'activate' ) );
		register_deactivation_hook( dirname( __FILE__ ) . '/../wp-memory-usage.php', array( __CLASS__, 'deactivate' ) );
	}

	/* =========================================================
	 * STORAGE BACKEND HELPERS
	 * ========================================================= */

	/**
	 * Returns the active storage backend: 'file' or 'db'.
	 * The backend is always stored in wp_options so it can be read before
	 * the settings file/table is available.
	 */
	private static function get_backend() {
		// During a settings save the new value arrives in $_POST; read it directly
		// so the sanitize callback can switch backends on the fly.
		$raw = get_option( 'wpmu_storage_backend', 'file' );
		return ( $raw === 'db' ) ? 'db' : 'file';
	}

	/**
	 * Returns the full DB table name (with WP prefix).
	 */
	private static function db_log_table() {
		global $wpdb;
		return $wpdb->prefix . self::DB_LOG_TABLE;
	}

	/**
	 * Creates the log table if it does not yet exist.
	 * Called on activation and when the admin switches to 'db'.
	 */
	public static function maybe_create_db_table() {
		global $wpdb;
		$table      = self::db_log_table();
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			log_type     VARCHAR(20)  NOT NULL DEFAULT 'event',
			log_key      VARCHAR(100) NOT NULL DEFAULT '',
			log_data     LONGTEXT     NOT NULL,
			created_at   DATETIME     NOT NULL,
			PRIMARY KEY (id),
			KEY log_type_key (log_type, log_key),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/* =========================================================
	 * SETTINGS: READ / WRITE
	 * Abstraction layer – delegates to file or db backend.
	 * ========================================================= */

	private static function get_settings() {
		if ( self::get_backend() === 'db' ) {
			return self::get_settings_db();
		}
		return self::get_settings_file();
	}

	private static function set_settings( $opts ) {
		if ( self::get_backend() === 'db' ) {
			return self::set_settings_db( $opts );
		}
		return self::set_settings_file( $opts );
	}

	/* --- file implementation --- */
	private static function get_settings_file() {
		$settings = self::get_from_file( "settings.cgi" );
		if ( $settings === [] ) {
			$settings = self::defaults();
		}
		return $settings;
	}

	private static function set_settings_file( $opts ) {
		return self::update_file( $opts, "settings.cgi" );
	}

	/* --- db implementation --- */
	private static function get_settings_db() {
		global $wpdb;
		$table = esc_sql( self::db_log_table() );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_var(
			"SELECT log_data FROM `{$table}` WHERE log_type = 'settings' AND log_key = 'main' ORDER BY id DESC LIMIT 1"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( $row ) {
			$decoded = json_decode( $row, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return self::defaults();
	}

	private static function set_settings_db( $opts ) {
		global $wpdb;
		$table = self::db_log_table();
		self::maybe_create_db_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// Delete old settings row(s), then insert fresh.
		$wpdb->delete( $table, array( 'log_type' => 'settings', 'log_key' => 'main' ), array( '%s', '%s' ) );
		$result = $wpdb->insert(
			$table,
			array(
				'log_type'   => 'settings',
				'log_key'    => 'main',
				'log_data'   => wp_json_encode( $opts ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		return ( $result !== false );
	}

	/* =========================================================
	 * LOG: READ / WRITE / APPEND
	 * ========================================================= */

	private static function get_log() {
		if ( self::get_backend() === 'db' ) {
			return self::get_log_db();
		}
		return self::get_complete_file( self::WPMU_LOG_FILE );
	}

	private static function add_log( $opts ) {
		if ( self::get_backend() === 'db' ) {
			return self::add_log_db( $opts );
		}
		return self::append_file( $opts, self::WPMU_LOG_FILE );
	}

	/* --- db log implementation --- */
	private static function get_log_db() {
		global $wpdb;
		$table = esc_sql( self::db_log_table() );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col(
			"SELECT log_data FROM `{$table}` WHERE log_type = 'log' ORDER BY id ASC"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$result = array();
		foreach ( $rows as $row ) {
			$decoded = json_decode( $row, true );
			if ( is_array( $decoded ) ) {
				$result[] = $decoded;
			}
		}
		return $result;
	}

	private static function add_log_db( $opts ) {
		global $wpdb;
		$table = self::db_log_table();
		self::maybe_create_db_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'log_type'   => 'log',
				'log_key'    => '',
				'log_data'   => wp_json_encode( $opts ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/* =========================================================
	 * DIGEST: READ / WRITE  (always file-based for now,
	 * but the two helpers below are backend-aware)
	 * ========================================================= */

	/**
	 * Read a digest entry by filename (file mode) or db key (db mode).
	 * For db mode the $filename is used as the log_key.
	 */
	private static function get_digest( $filename ) {
		if ( self::get_backend() === 'db' ) {
			return self::get_digest_db( $filename );
		}
		return self::get_from_file( $filename );
	}

	private static function save_digest( $data, $filename ) {
		if ( self::get_backend() === 'db' ) {
			return self::save_digest_db( $data, $filename );
		}
		return self::update_file( $data, $filename );
	}

	private static function delete_digest( $filename ) {
		if ( self::get_backend() === 'db' ) {
			return self::delete_digest_db( $filename );
		}
		$path = self::WPMU_LOG_PATH . $filename;
		if ( file_exists( $path ) ) {
			return wp_delete_file( $path );
		}
		return false;
	}

	private static function list_digests() {
		if ( self::get_backend() === 'db' ) {
			return self::list_digests_db();
		}
		return self::list_digests_file();
	}

	/* --- file digest helpers --- */
	private static function list_digests_file() {
		$digest_files = array();
		foreach ( glob( self::WPMU_LOG_PATH . 'digest_*.cgibak' ) ?: array() as $bak ) {
			$fn    = basename( $bak );
			$raw   = str_replace( array( 'digest_', '.cgibak' ), '', $fn );
			$dt    = DateTime::createFromFormat( 'Y-m-d-H-i-s', $raw );
			$label = $dt
				? wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $dt->getTimestamp() )
				: $fn;
			$digest_files[ $fn ] = $label;
		}
		krsort( $digest_files );
		return $digest_files;
	}

	/* --- db digest helpers --- */
	private static function get_digest_db( $key ) {
		global $wpdb;
		$table = esc_sql( self::db_log_table() );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT log_data FROM `{$table}` WHERE log_type = 'digest' AND log_key = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		if ( $row ) {
			$decoded = json_decode( $row, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	private static function save_digest_db( $data, $key ) {
		global $wpdb;
		$table = self::db_log_table();
		self::maybe_create_db_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'log_type' => 'digest', 'log_key' => $key ), array( '%s', '%s' ) );
		$wpdb->insert(
			$table,
			array(
				'log_type'   => 'digest',
				'log_key'    => $key,
				'log_data'   => wp_json_encode( $data ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	private static function delete_digest_db( $key ) {
		global $wpdb;
		$table = self::db_log_table();
		if ( $key === 'all' ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			return $wpdb->delete( $table, array( 'log_type' => 'digest' ), array( '%s' ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		return $wpdb->delete( $table, array( 'log_type' => 'digest', 'log_key' => $key ), array( '%s', '%s' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	private static function list_digests_db() {
		global $wpdb;
		$table = esc_sql( self::db_log_table() );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT log_key, created_at FROM `{$table}` WHERE log_type = 'digest' ORDER BY created_at DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$digest_list = array();
		foreach ( $rows as $row ) {
			$key   = $row['log_key'];
			// Try to format from key if it looks like a timestamp slug
			$raw   = str_replace( array( 'digest_', '.cgibak' ), '', $key );
			$dt    = DateTime::createFromFormat( 'Y-m-d-H-i-s', $raw );
			$label = $dt
				? wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $dt->getTimestamp() )
				: wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( $row['created_at'] ) );
			$digest_list[ $key ] = $label;
		}
		return $digest_list;
	}

	/* =========================================================
	 * DB LOG ROTATION (parallel to file cron_cleanup)
	 * ========================================================= */
	private static function cron_cleanup_db() {
		global $wpdb;
		$table = esc_sql( self::db_log_table() );
		$ts    = wp_date( 'Y-m-d-H-i-s' );

		// 1) Read all current log rows
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col(
			"SELECT log_data FROM `{$table}` WHERE log_type = 'log' ORDER BY id ASC"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $rows ) ) { return; }

		$opts_arr = array();
		foreach ( $rows as $r ) {
			$d = json_decode( $r, true );
			if ( is_array( $d ) ) { $opts_arr[] = $d; }
		}

		// 2) Delete all log rows
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'wpmu_log', array( 'log_type' => 'log' ), array( '%s' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		// 3) Build aggregation (same logic as file path)
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
		self::aggregate_log_rows( $aggregation, $opts_arr );
		$aggregation['interval']['duration_sec'] =
			$aggregation['interval']['last'] - $aggregation['interval']['first'];
		foreach ( $aggregation['uri'] as $uri_key => &$udata ) {
			$udata['avg_usage'] = $udata['total'] > 0
				? (int) round( $udata['sum_usage'] / $udata['total'] )
				: 0;
			unset( $udata['sum_usage'] );
		}
		unset( $udata );

		$digest_key = 'digest_' . $ts . '.cgibak';
		self::save_digest_db( $aggregation, $digest_key );

		// 4) Send digest email if needed
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
			self::send_digest_email( $settings_for_mail['email_to'], $aggregation, $digest_key );
		}

		// 5) Prune old digests from DB
		$opts    = self::get_settings();
		$del_min = isset( $opts['del_frequency_logfiles'] ) ? (int) $opts['del_frequency_logfiles'] : 1440;
		if ( $del_min > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $del_min * 60 );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE log_type = 'digest' AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
		}
	}

	/** Shared aggregation logic extracted for reuse */
	private static function aggregate_log_rows( &$aggregation, $rows ) {
		foreach ( $rows as $e1 ) {
			$s    = (string) ( $e1['s'] ?? 'ok' );
			$t    = (int)    ( $e1['t'] ?? 0 );
			$u    = (int)    ( $e1['u'] ?? 0 );
			$c    = is_array( $e1['c'] ) ? $e1['c'] : array();
			$type = (string) ( $c['type'] ?? '' );
			$uri  = (string) ( $c['uri']  ?? '' );

			$aggregation['status'][ $s ] = ( $aggregation['status'][ $s ] ?? 0 ) + 1;

			if ( $type ) {
				$aggregation['type'][ $type ] = ( $aggregation['type'][ $type ] ?? 0 ) + 1;
			}

			if ( $uri ) {
				if ( ! isset( $aggregation['uri'][ $uri ] ) ) {
					$aggregation['uri'][ $uri ] = array(
						'total' => 0, 'warn' => 0, 'danger' => 0, 'critical' => 0,
						'sum_usage' => 0, 'max_usage' => 0,
					);
				}
				$aggregation['uri'][ $uri ]['total']++;
				$aggregation['uri'][ $uri ][ $s ] = ( $aggregation['uri'][ $uri ][ $s ] ?? 0 ) + 1;
				$aggregation['uri'][ $uri ]['sum_usage'] += $u;
				if ( $u > $aggregation['uri'][ $uri ]['max_usage'] ) {
					$aggregation['uri'][ $uri ]['max_usage'] = $u;
				}
			}

			if ( $t < $aggregation['interval']['first'] ) { $aggregation['interval']['first'] = $t; }
			if ( $t > $aggregation['interval']['last']  ) { $aggregation['interval']['last']  = $t; }

			$minute_key = wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), (int) $t );
			$aggregation['interval']['per_minute'][ $minute_key ] =
				( $aggregation['interval']['per_minute'][ $minute_key ] ?? 0 ) + 1;
		}
	}

	/* =========================================================
	 * CRON
	 * ========================================================= */
	public static function reschedule_cron() {
		$opts         = self::get_settings();
		$interval_min = isset( $opts['rotate_log'] ) ? (int) $opts['rotate_log'] : 60;
		$hook         = 'wpmu_cleanup_hook';
		$next         = wp_next_scheduled( $hook );
		$schedule     = wp_get_schedule( $hook );
		$expected     = 'wpmu_every_' . $interval_min . '_min';
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
			/* translators: %d: number of minutes */
			'display'  => sprintf( did_action( 'init' ) ? __( 'Every %d minutes (WPMU)', 'wp-memory-usage' ) : 'Every %d minutes (WPMU)', $interval_min ),
		);
		return $schedules;
	}

	public static function activate() {
		// Ensure DB table exists if DB backend is already chosen
		if ( self::get_backend() === 'db' ) {
			self::maybe_create_db_table();
		}
	}

	public static function deactivate() {
		$ts = wp_next_scheduled( 'wpmu_cleanup_hook' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'wpmu_cleanup_hook' );
		}
	}

	public static function cron_cleanup() {
		if ( self::get_backend() === 'db' ) {
			self::cron_cleanup_db();
			return;
		}
		// ── FILE PATH (original code) ──────────────────────────────────────
		$dir  = self::WPMU_LOG_PATH;
		$file = $dir . self::WPMU_LOG_FILE;
		$ts   = wp_date( 'Y-m-d-H-i-s' );
		$backup = $dir . 'wpmu-log_' . $ts . '.cgibak';

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( $wp_filesystem->exists( $file ) ) {
			if ( ! $wp_filesystem->move( $file, $backup, true ) ) { }
		}

		if ( file_exists( $backup ) ) {
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
			self::aggregate_log_rows( $aggregation, $opts );
			$aggregation['interval']['duration_sec'] =
				$aggregation['interval']['last'] - $aggregation['interval']['first'];
			foreach ( $aggregation['uri'] as $uri_key => &$udata ) {
				$udata['avg_usage'] = $udata['total'] > 0
					? (int) round( $udata['sum_usage'] / $udata['total'] )
					: 0;
				unset( $udata['sum_usage'] );
			}
			unset( $udata );

			$filenamedigest = 'digest_' . $ts . '.cgibak';
			self::update_file( $aggregation, $filenamedigest );

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
		}

		// Delete old backups
		$opts    = self::get_settings();
		$del_min = isset( $opts['del_frequency_logfiles'] ) ? (int) $opts['del_frequency_logfiles'] : 1440;
		if ( $del_min > 0 ) {
			$max_age_sec = $del_min * 60;
			$now         = time();
			foreach ( glob( $dir . '*.cgibak' ) as $bak ) {
				if ( strpos( basename( $bak ), 'wpmu-log_' ) === 0 ) {
					if ( filemtime( $bak ) < ( $now - $max_age_sec ) ) {
						wp_delete_file( $bak );
					}
				}
			}
		}
	}

	/* =========================================================
	 * FILE HELPERS (original, unchanged)
	 * ========================================================= */
	#### LOGGING: FILE SAVE and LOAD : BEGIN ####
	private static function get_from_file( $filename ) {
		if ( empty( $filename ) ) { return NULL; }
		$dir = self::WPMU_LOG_PATH;

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0755 );
		}
		$file = $dir . $filename;
		if ( ! file_exists( $file ) ) {
			return [];
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$fh = @fopen( $file, 'rb' );
		if ( ! $fh ) return [];
		if ( ! flock( $fh, LOCK_SH ) ) { fclose( $fh ); return []; }
		$content = fgets( $fh );
		$content = trim( $content );
		flock( $fh, LOCK_UN );
		fclose( $fh );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		return json_decode( $content, TRUE );
	}

	private static function get_complete_file( $filename ) {
		if ( empty( $filename ) ) { return NULL; }
		$dir = self::WPMU_LOG_PATH;

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0755 );
		}
		$file = $dir . $filename;
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$fh = @fopen( $file, 'rb' );
		if ( ! $fh ) return [];
		if ( ! flock( $fh, LOCK_SH ) ) { fclose( $fh ); return []; }
		$content = stream_get_contents( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		$lines  = array_filter( explode( "\n", trim( $content ) ) );
		$result = [];
		foreach ( $lines as $line ) {
			$decoded = json_decode( trim( $line ), true );
			if ( is_array( $decoded ) ) {
				$result[] = $decoded;
			}
		}
		return $result;
	}

	private static function append_file( $opts, $filename ) {
		if ( empty( $filename ) ) { return NULL; }
		$dir = self::WPMU_LOG_PATH;

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0755 );
		}
		$file    = $dir . $filename;
		$optsStr = json_encode( $opts ) . "\n";
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$fh = fopen( $file, 'ab' );
		if ( $fh ) {
			flock( $fh, LOCK_EX );
			fwrite( $fh, $optsStr );
			fflush( $fh );
			flock( $fh, LOCK_UN );
			fclose( $fh );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}

	private static function update_file( $opts, $filename ) {
		if ( empty( $filename ) ) { return NULL; }
		$dir = self::WPMU_LOG_PATH;

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0755 );
		}
		$file    = $dir . $filename;
		$optsStr = json_encode( $opts );
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$fh = fopen( $file, 'c+' );
		if ( ! $fh ) return;
		flock( $fh, LOCK_EX );
		ftruncate( $fh, 0 );
		rewind( $fh );
		fwrite( $fh, $optsStr );
		fflush( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh );
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}
	#### LOGGING: FILE SAVE and LOAD : END ####

	/* =========================================================
	 * DEFAULTS
	 * ========================================================= */
	private static function defaults() {
		return array(
			'warn_pct'               => 70,
			'danger_pct'             => 85,
			'critical_pct'           => 95,
			'logop_warn_pct'         => 0,
			'logop_danger_pct'       => 0,
			'logop_critical_pct'     => 0,
			'email_to'               => get_option( 'admin_email' ),
			'send_email'             => 0,
			'track_peak'             => 1,
			'log_ajax'               => 1,
			'log_cron'               => 1,
			'log_rest'               => 1,
			'log_admin'              => 1,
			'log_favicon'            => 1,
			'log_ok'                 => 0,
			'digest_enabled'         => 1,
			'rotate_log'             => 30,
			'del_frequency_logfiles' => 1440,
		);
	}

	/* =========================================================
	 * SETTINGS UI
	 * ========================================================= */
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
		echo esc_html__( 'Thresholds are evaluated as percentage of the effective memory limit (min of PHP and WP limits where applicable).', 'wp-memory-usage' );
	}
	public static function section_intro_storage() {
		echo esc_html__( 'Choose where settings and log data are stored: in files (classic) or in the WordPress database.', 'wp-memory-usage' );
	}

	public static function sanitize_options_file( $input ) {
		$opts_settings = self::get_settings();
		$in = is_array( $input ) ? $input : array();

		$opts_settings['warn_pct']     = self::clamp_int( isset( $in['warn_pct'] )     ? $in['warn_pct']     : $opts_settings['warn_pct'],     1, 99 );
		$opts_settings['danger_pct']   = self::clamp_int( isset( $in['danger_pct'] )   ? $in['danger_pct']   : $opts_settings['danger_pct'],   1, 100 );
		$opts_settings['critical_pct'] = self::clamp_int( isset( $in['critical_pct'] ) ? $in['critical_pct'] : $opts_settings['critical_pct'], 1, 100 );
		$opts_settings['logop_warn_pct']     = ! empty( $in['logop_warn_pct'] )     ? 1 : 0;
		$opts_settings['logop_danger_pct']   = ! empty( $in['logop_danger_pct'] )   ? 1 : 0;
		$opts_settings['logop_critical_pct'] = ! empty( $in['logop_critical_pct'] ) ? 1 : 0;
		if ( $opts_settings['danger_pct'] <= $opts_settings['warn_pct'] ) {
			$opts_settings['danger_pct'] = min( 100, $opts_settings['warn_pct'] + 5 );
		}
		if ( $opts_settings['critical_pct'] <= $opts_settings['danger_pct'] ) {
			$opts_settings['critical_pct'] = min( 100, $opts_settings['danger_pct'] + 5 );
		}
		$opts_settings['email_to']               = sanitize_email( isset( $in['email_to'] ) ? $in['email_to'] : $opts_settings['email_to'] );
		$opts_settings['send_email']             = ! empty( $in['send_email'] ) ? 1 : 0;
		$opts_settings['track_peak']             = ! empty( $in['track_peak'] ) ? 1 : 0;
		$opts_settings['del_frequency_logfiles'] = self::clamp_int( isset( $in['del_frequency_logfiles'] ) ? $in['del_frequency_logfiles'] : $opts_settings['del_frequency_logfiles'], 0, 43200 );
		$opts_settings['digest_enabled']         = ! empty( $in['digest_enabled'] ) ? 1 : 0;
		$opts_settings['rotate_log']             = self::clamp_int( isset( $in['rotate_log'] ) ? $in['rotate_log'] : $opts_settings['rotate_log'], 1, 600 );
		$opts_settings['log_admin']   = ! empty( $in['log_admin'] )   ? 1 : 0;
		$opts_settings['log_ajax']    = ! empty( $in['log_ajax'] )    ? 1 : 0;
		$opts_settings['log_rest']    = ! empty( $in['log_rest'] )    ? 1 : 0;
		$opts_settings['log_cron']    = ! empty( $in['log_cron'] )    ? 1 : 0;
		$opts_settings['log_favicon'] = ! empty( $in['log_favicon'] ) ? 1 : 0;
		$opts_settings['log_ok']      = ! empty( $in['log_ok'] )      ? 1 : 0;

		// ── Storage backend ───────────────────────────────────────────────
		$old_backend = get_option( 'wpmu_storage_backend', 'file' );
		$new_backend = ( isset( $in['storage_backend'] ) && $in['storage_backend'] === 'db' ) ? 'db' : 'file';
		$switching   = ( $old_backend !== $new_backend );

		update_option( 'wpmu_storage_backend', $new_backend );
		if ( $new_backend === 'db' ) {
			self::maybe_create_db_table();
		}

		// On first switch to DB: write settings into the new backend before
		// set_settings() runs, so the table exists and is ready.
		if ( $switching && $new_backend === 'db' ) {
			self::set_settings_db( $opts_settings );
		}

		$result = self::set_settings( $opts_settings );

		if ( $result === false ) {
			add_settings_error(
				self::OPTION_KEY,
				'wpmu_save_failed',
				__( 'Error: Settings could not be saved. Check if the log directory is writable at Tab "Check Installation".', 'wp-memory-usage' ),
				'error'
			);
		}
		// No extra success notice – WordPress Settings API already shows "Settings saved."

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
				$attrs .= ' min="0" max="43200"';
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
			default:
				break;
		}
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	public static function render_select( $args ) {
		$opts    = self::get_settings();
		$key     = isset( $args['key'] ) ? (string) $args['key'] : '';
		$val     = isset( $opts[ $key ] ) ? (string) $opts[ $key ] : '';
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

	/** Render the storage backend radio buttons */
	public static function render_storage_backend( $args ) {
		$current = self::get_backend();
		$options = array(
			'file' => __( 'File system (classic – stores data outside the webroot, e.g. logs/wpmu/)', 'wp-memory-usage' ),
			'db'   => __( 'WordPress database (new – stores data in a dedicated table wp_wpmu_log)', 'wp-memory-usage' ),
		);
		foreach ( $options as $val => $label ) {
			printf(
				'<label style="display:block;margin-bottom:6px;"><input type="radio" name="%s[storage_backend]" value="%s" %s /> %s</label>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $val ),
				checked( $current, $val, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'Changing the backend does not migrate existing data. File-backend data stays in the log directory; DB-backend data stays in the database table.', 'wp-memory-usage' ) . '</p>';
	}

	/* =========================================================
	 * ADMIN BAR
	 * ========================================================= */
	public static function admin_bar_node( $bar ) {
		if ( ! is_user_logged_in() || ! current_user_can( self::CAP ) ) { return; }
		$opts  = self::get_settings();
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

	/* =========================================================
	 * CORE LOGIC
	 * ========================================================= */
	public static function check_thresholds_on_shutdown() {
		$opts_settings = self::get_settings();
		$opts  = [];
		$limit = self::get_effective_memory_limit_bytes();
		$usage = self::get_current_memory_bytes( ! empty( $opts_settings['track_peak'] ) );
		$context = self::collect_context();
		$state   = self::state_for_usage( $usage, $limit, $opts_settings );
		$opts['st'] = $state;
		$opts['pe'] = (int) $usage;
		$opts['li'] = (int) $limit;
		self::log_event( $opts, $opts_settings, $context );
	}

	private static function send_email_alert( $to, $state, $usage, $limit, $context ) {
		$site    = wp_parse_url( home_url(), PHP_URL_HOST );
		$subject = sprintf(
			'[%s] Memory %s (%s)',
			$site ? $site : 'WordPress',
			strtoupper( $state ),
			self::format_pct( $usage, $limit )
		);
		$lines   = array();
		$lines[] = __( 'WP Memory Threshold Alert', 'wp-memory-usage' );
		$lines[] = '';
		$lines[] = __( 'State: ', 'wp-memory-usage' ) . strtoupper( $state );
		$lines[] = __( 'Usage: ', 'wp-memory-usage' ) . self::format_bytes( $usage );
		$lines[] = __( 'Limit: ', 'wp-memory-usage' ) . ( $limit > 0 ? self::format_bytes( $limit ) : __( 'unlimited', 'wp-memory-usage' ) );
		$lines[] = __( 'Ratio: ', 'wp-memory-usage' ) . self::format_pct( $usage, $limit );
		$lines[] = '';
		$lines[] = __( 'Context:', 'wp-memory-usage' );
		$lines[] = __( '- Type:', 'wp-memory-usage' ) . ' '   . (string) ( isset( $context['type'] )   ? $context['type']   : '' );
		$lines[] = __( '- Method:', 'wp-memory-usage' ) . ' ' . (string) ( isset( $context['method'] ) ? $context['method'] : '' );
		$lines[] = __( '- URI:', 'wp-memory-usage' ) . ' '    . (string) ( isset( $context['uri'] )    ? $context['uri']    : '' );
		if ( ! empty( $context['admin_screen'] ) ) { $lines[] = __( '- Admin screen:', 'wp-memory-usage' ) . ' ' . (string) $context['admin_screen']; }
		if ( ! empty( $context['post_id'] ) )      { $lines[] = __( '- Post ID:', 'wp-memory-usage' ) . ' '     . (string) $context['post_id']; }
		if ( ! empty( $context['ajax_action'] ) )  { $lines[] = __( '- AJAX action:', 'wp-memory-usage' ) . ' ' . (string) $context['ajax_action']; }
		if ( ! empty( $context['rest_route'] ) )   { $lines[] = __( '- REST route:', 'wp-memory-usage' ) . ' '  . (string) $context['rest_route']; }
		if ( ! empty( $context['user'] ) )         { $lines[] = __( '- User:', 'wp-memory-usage' ) . ' '        . (string) $context['user']; }
		$lines[] = '';
		$lines[] = __( 'Suggested actions:', 'wp-memory-usage' );
		$lines[] = __( '- Reproduce this URL/action and temporarily disable recently added/updated plugins', 'wp-memory-usage' );
		$lines[] = __( '- If it is an import/editor screen, try with fewer plugins active', 'wp-memory-usage' );
		$lines[] = __( '- Consider raising PHP memory_limit / WP_MEMORY_LIMIT if appropriate', 'wp-memory-usage' );
		$lines[] = '';
		$lines[] = __( 'Settings: ', 'wp-memory-usage' ) . admin_url( 'options-general.php?page=wpmu-memory-alerts' );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		return (bool) wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
	}

	/* =========================================================
	 * DIGEST EMAIL (unchanged)
	 * ========================================================= */
	public static function send_digest_email( $to, $aggregation, $filename ) {
		$site     = wp_parse_url( home_url(), PHP_URL_HOST );
		$site     = $site ? $site : 'WordPress';
		$warn     = (int) ( $aggregation['status']['warn']     ?? 0 );
		$danger   = (int) ( $aggregation['status']['danger']   ?? 0 );
		$critical = (int) ( $aggregation['status']['critical'] ?? 0 );
		$total    = $warn + $danger + $critical + (int) ( $aggregation['status']['ok'] ?? 0 );
		$severity = $critical > 0 ? __( 'CRITICAL', 'wp-memory-usage' ) : ( $danger > 0 ? __( 'DANGER', 'wp-memory-usage' ) : __( 'WARN', 'wp-memory-usage' ) );
		$subject  = sprintf( '[%s] Memory Digest %s – warn:%d danger:%d critical:%d', $site, $severity, $warn, $danger, $critical );
		$lines   = array();
		$lines[] = __( 'WP Memory Usage – Digest Report', 'wp-memory-usage' );
		$lines[] = str_repeat( '-', 50 );
		$lines[] = __( 'File   : ', 'wp-memory-usage' ) . $filename;
		$lines[] = __( 'Site   : ', 'wp-memory-usage' ) . home_url();
		$lines[] = '';
		$lines[] = __( 'STATUS SUMMARY', 'wp-memory-usage' );
		$lines[] = '  ' . __( 'OK       : ', 'wp-memory-usage' ) . (int) ( $aggregation['status']['ok']       ?? 0 );
		$lines[] = '  ' . __( 'WARN     : ', 'wp-memory-usage' ) . $warn;
		$lines[] = '  ' . __( 'DANGER   : ', 'wp-memory-usage' ) . $danger;
		$lines[] = '  ' . __( 'CRITICAL : ', 'wp-memory-usage' ) . $critical;
		$lines[] = '  ' . __( 'Total    : ', 'wp-memory-usage' ) . $total;
		$lines[] = '';
		$first = $aggregation['interval']['first'] ?? 0;
		$last  = $aggregation['interval']['last']  ?? 0;
		if ( $first && $last ) {
			$lines[] = __( 'TIME RANGE', 'wp-memory-usage' );
			$lines[] = '  ' . __( 'From : ', 'wp-memory-usage' ) . wp_date( 'Y-m-d H:i:s', $first );
			$lines[] = '  ' . __( 'To   : ', 'wp-memory-usage' ) . wp_date( 'Y-m-d H:i:s', $last );
			$dur     = $last - $first;
			$lines[] = '  ' . __( 'Dur  : ', 'wp-memory-usage' ) . floor( $dur / 60 ) . ' min ' . ( $dur % 60 ) . ' sec';
			$lines[] = '';
		}
		if ( ! empty( $aggregation['type'] ) ) {
			$lines[] = __( 'REQUEST TYPES', 'wp-memory-usage' );
			foreach ( $aggregation['type'] as $type => $cnt ) {
				$lines[] = sprintf( '  %-12s: %d', strtoupper( $type ), $cnt );
			}
			$lines[] = '';
		}
		if ( ! empty( $aggregation['uri'] ) ) {
			$uris_with_alerts = array_filter( $aggregation['uri'], function ( $u ) {
				return ( ( $u['warn'] ?? 0 ) + ( $u['danger'] ?? 0 ) + ( $u['critical'] ?? 0 ) ) > 0;
			} );
			uasort( $uris_with_alerts, function ( $a, $b ) {
				$sa = ( $a['warn'] ?? 0 ) + ( $a['danger'] ?? 0 ) * 2 + ( $a['critical'] ?? 0 ) * 3;
				$sb = ( $b['warn'] ?? 0 ) + ( $b['danger'] ?? 0 ) * 2 + ( $b['critical'] ?? 0 ) * 3;
				return $sb <=> $sa;
			} );
			$lines[] = __( 'TOP URIs WITH ALERTS (warn/danger/critical | avg | max)', 'wp-memory-usage' );
			$count = 0;
			foreach ( $uris_with_alerts as $uri => $ud ) {
				if ( ++$count > 20 ) { break; }
				$lines[] = sprintf( '  %s', $uri );
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
		$lines[] = __( 'Settings:', 'wp-memory-usage' ) . admin_url( 'options-general.php?page=wpmu-memory-alerts&tab=digest' );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		return (bool) wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
	}

	/* =========================================================
	 * CONTEXT CAPTURE (unchanged)
	 * ========================================================= */
	private static function collect_context() {
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$is_ajax  = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		$is_cron  = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		$is_rest  = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( '' !== $uri && false !== strpos( $uri, '/wp-json/' ) );
		$is_admin = is_admin();
		$type     = $is_cron ? 'cron' : ( $is_ajax ? 'ajax' : ( $is_rest ? 'rest' : ( $is_admin ? 'admin' : 'front' ) ) );
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
		$user     = wp_get_current_user();
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
			'is_ajax'      => $is_ajax  ? 1 : 0,
			'is_cron'      => $is_cron  ? 1 : 0,
			'is_rest'      => $is_rest  ? 1 : 0,
		);
	}

	/* =========================================================
	 * LOG EVENT
	 * ========================================================= */
	private static function log_event( &$opts, $opts_settings, $ctx ) {
		if ( ( ! $opts_settings['log_admin'] ) && ( $ctx['is_admin'] ) ) { return NULL; }
		if ( ( ! $opts_settings['log_ok'] )    && ( $opts['st'] === 'ok' ) ) { return NULL; }
		if ( ( ! $opts_settings['logop_warn_pct'] )     && ( $opts['st'] === 'warn' ) )     { return NULL; }
		if ( ( ! $opts_settings['logop_danger_pct'] )   && ( $opts['st'] === 'danger' ) )   { return NULL; }
		if ( ( ! $opts_settings['logop_critical_pct'] ) && ( $opts['st'] === 'critical' ) ) { return NULL; }
		if ( ( ! $opts_settings['log_rest'] )    && ( $ctx['is_rest'] ) ) { return NULL; }
		if ( ( ! $opts_settings['log_ajax'] )    && ( $ctx['is_ajax'] ) ) { return NULL; }
		if ( ( ! $opts_settings['log_cron'] )    && ( $ctx['is_cron'] ) ) { return NULL; }
		if ( preg_match( '/favicon.ico/', $ctx['uri'] ) ) { return TRUE; }
		$optsout = array(
			't' => time(),
			's' => (string) $opts['st'],
			'u' => (int)    $opts['pe'],
			'l' => (int)    $opts['li'],
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

	/* =========================================================
	 * SETTINGS REGISTRATION
	 * ========================================================= */
	public static function add_admin_menu() {
		add_options_page(
			esc_html__( 'Memory Threshold Alerts', 'wp-memory-usage' ),
			esc_html__( 'Memory Alerts', 'wp-memory-usage' ),
			self::CAP,
			'wpmu-memory-alerts',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'wpmu_threshold_settings',
			self::OPTION_KEY,
			array( __CLASS__, 'sanitize_options_file' )
		);

		// ── Storage backend section ────────────────────────────────────────
		add_settings_section(
			'wpmu_storage',
			'<h2 style="background-color: ' . self::SETTING_COLOR_BACK . '; padding: 10px; color: ' . self::SETTING_COLOR_FONT . '; border: 2px solid ' . self::SETTING_COLOR_BORDER . ';">'
			. esc_html__( 'Storage Backend', 'wp-memory-usage' ) . '</h2>',
			array( __CLASS__, 'section_intro_storage' ),
			'wpmu-memory-alerts'
		);
		add_settings_field(
			'storage_backend',
			esc_html__( 'Where to store data', 'wp-memory-usage' ),
			array( __CLASS__, 'render_storage_backend' ),
			'wpmu-memory-alerts',
			'wpmu_storage'
		);

		// ── Thresholds section ─────────────────────────────────────────────
		add_settings_section(
			'wpmu_main',
			'<h2 style="background-color: ' . self::SETTING_COLOR_BACK . '; padding: 10px; color: ' . self::SETTING_COLOR_FONT . '; border: 2px solid ' . self::SETTING_COLOR_BORDER . ';">'
			. esc_html__( 'Thresholds', 'wp-memory-usage' ) . '</h2>',
			array( __CLASS__, 'section_intro_thresholds' ),
			'wpmu-memory-alerts'
		);
		$fields = array(
			'warn_pct'     => array( 'label' => esc_html__( 'Warning threshold', 'wp-memory-usage' ),  'log_label' => esc_html__( 'Log Warning', 'wp-memory-usage' ) ),
			'danger_pct'   => array( 'label' => esc_html__( 'Danger threshold', 'wp-memory-usage' ),   'log_label' => esc_html__( 'Log Danger', 'wp-memory-usage' ) ),
			'critical_pct' => array( 'label' => esc_html__( 'Critical threshold', 'wp-memory-usage' ), 'log_label' => esc_html__( 'Log Critical', 'wp-memory-usage' ) ),
		);
		foreach ( $fields as $key => $field ) {
			add_settings_field( $key, esc_html( $field['label'] . ' %' ), array( __CLASS__, 'render_field' ), 'wpmu-memory-alerts', 'wpmu_main', array( 'key' => $key ) );
			add_settings_field( 'logop_' . $key, $field['log_label'], array( __CLASS__, 'render_checkbox' ), 'wpmu-memory-alerts', 'wpmu_main', array( 'key' => 'logop_' . $key ) );
		}

		// ── How to measure ─────────────────────────────────────────────────
		add_settings_section( 'wpmu_measure', '<h2 style="background-color: ' . self::SETTING_COLOR_BACK . '; padding: 10px; color: ' . self::SETTING_COLOR_FONT . '; border: 2px solid ' . self::SETTING_COLOR_BORDER . ';">' . esc_html__( 'How to measure', 'wp-memory-usage' ) . '</h2>', array( __CLASS__, 'section_intro_measure' ), 'wpmu-memory-alerts' );
		add_settings_field( 'track_peak', esc_html__( 'Use peak memory (recommended)', 'wp-memory-usage' ), array( __CLASS__, 'render_checkbox' ), 'wpmu-memory-alerts', 'wpmu_measure', array( 'key' => 'track_peak' ) );

		// ── Logging ────────────────────────────────────────────────────────
		add_settings_section( 'wpmu_logging', '<h2 style="background-color: ' . self::SETTING_COLOR_BACK . '; padding: 10px; color: ' . self::SETTING_COLOR_FONT . '; border: 2px solid ' . self::SETTING_COLOR_BORDER . ';">' . esc_html__( 'Logging', 'wp-memory-usage' ) . '</h2>', array( __CLASS__, 'section_intro_log' ), 'wpmu-memory-alerts' );
		$log_fields = array(
			'log_ajax'    => esc_html__( 'Log Ajax',        'wp-memory-usage' ),
			'log_rest'    => esc_html__( 'Log Rest',        'wp-memory-usage' ),
			'log_admin'   => esc_html__( 'Log Admin',       'wp-memory-usage' ),
			'log_cron'    => esc_html__( 'Log Cron',        'wp-memory-usage' ),
			'log_favicon' => esc_html__( 'Log favicon.ico', 'wp-memory-usage' ),
			'log_ok'      => esc_html__( 'Log OK',          'wp-memory-usage' ),
		);
		foreach ( $log_fields as $logkey => $label ) {
			add_settings_field( $logkey, $label, array( __CLASS__, 'render_checkbox' ), 'wpmu-memory-alerts', 'wpmu_logging', array( 'key' => $logkey ) );
		}

		// ── Alert settings ─────────────────────────────────────────────────
		add_settings_section( 'wpmu_alertsettings', '<h2 style="background-color: ' . self::SETTING_COLOR_BACK . '; padding: 10px; color: ' . self::SETTING_COLOR_FONT . '; border: 2px solid ' . self::SETTING_COLOR_BORDER . ';">' . esc_html__( 'How to alert', 'wp-memory-usage' ) . '</h2>', array( __CLASS__, 'section_intro_howalert' ), 'wpmu-memory-alerts' );
		add_settings_field( 'email_to',               esc_html__( 'Alert email recipient', 'wp-memory-usage' ),      array( __CLASS__, 'render_field' ),    'wpmu-memory-alerts', 'wpmu_alertsettings', array( 'key' => 'email_to' ) );
		add_settings_field( 'send_email',             esc_html__( 'Send email alerts', 'wp-memory-usage' ),          array( __CLASS__, 'render_checkbox' ), 'wpmu-memory-alerts', 'wpmu_alertsettings', array( 'key' => 'send_email' ) );
		add_settings_field( 'rotate_log',             esc_html__( 'Digest interval', 'wp-memory-usage' ),            array( __CLASS__, 'render_field' ),    'wpmu-memory-alerts', 'wpmu_alertsettings', array( 'key' => 'rotate_log' ) );
		add_settings_field( 'del_frequency_logfiles', esc_html__( 'Delete rotated Logfiles', 'wp-memory-usage' ),    array( __CLASS__, 'render_field' ),    'wpmu-memory-alerts', 'wpmu_alertsettings', array( 'key' => 'del_frequency_logfiles' ) );
	}

	/* =========================================================
	 * RENDER SETTINGS PAGE
	 * (All tab content is kept from the original; only the
	 *  digest-delete and history tabs are backend-aware.)
	 * ========================================================= */
	public static function render_settings_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-memory-usage' ) );
		}
		$opts = self::get_settings();
		$tab  = 'history';
		if ( isset( $_GET['tab'], $_GET['_wpmu_tab_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpmu_tab_nonce'] ) ), 'wpmu_tab_nav' ) ) {
			$tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
		}
		if ( ! in_array( $tab, array( 'settings', 'current', 'actions', 'digest', 'history', 'check_installation', 'diagnose' ), true ) ) {
			$tab = 'history';
		}
		$page_url  = admin_url( 'options-general.php?page=wpmu-memory-alerts' );
		$tab_nonce = wp_create_nonce( 'wpmu_tab_nav' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Memory Usage', 'wp-memory-usage' ); ?> – <?php echo esc_html__( 'Threshold Alerts', 'wp-memory-usage' ); ?></h1>

			<?php
			// Show active backend badge
			$backend_label = self::get_backend() === 'db'
				? '<span style="background:#d4edda;color:#155724;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600;margin-left:10px;">💾 ' . esc_html__( 'Backend: Database', 'wp-memory-usage' ) . '</span>'
				: '<span style="background:#fff3cd;color:#856404;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600;margin-left:10px;">📁 ' . esc_html__( 'Backend: File system', 'wp-memory-usage' ) . '</span>';
			echo wp_kses_post( $backend_label );
			?>

			<h2 class="nav-tab-wrapper" style="margin-top: 12px;">
				<?php
				$tabs = array(
					'settings'           => esc_html__( '⚙️ Settings',          'wp-memory-usage' ),
					'history'            => esc_html__( '📋 History',            'wp-memory-usage' ),
					'digest'             => esc_html__( '📊 Digest',             'wp-memory-usage' ),
					'actions'            => esc_html__( '🛠️ Actions',           'wp-memory-usage' ),
					'diagnose'           => esc_html__( '🩺 Diagnose',           'wp-memory-usage' ),
					'current'            => esc_html__( '📏 Memory Thresholds',  'wp-memory-usage' ),
					'check_installation' => esc_html__( '🔍 Check Installation', 'wp-memory-usage' ),
				);
				foreach ( $tabs as $t => $label ) :
				?>
				<a href="<?php echo esc_url( add_query_arg( array( 'tab' => $t, '_wpmu_tab_nonce' => $tab_nonce ), $page_url ) ); ?>"
				   class="nav-tab <?php echo ( $t === $tab ) ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
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
				$wp_limit_raw  = defined( 'WP_MEMORY_LIMIT' )    ? WP_MEMORY_LIMIT    : '';
				$wpmax_raw     = defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '';
				$php_limit_b   = self::parse_size_to_bytes( is_string( $php_limit_raw ) ? $php_limit_raw : '' );
				$wp_limit_b    = self::parse_size_to_bytes( is_string( $wp_limit_raw )  ? $wp_limit_raw  : '' );
				$wpmax_b       = self::parse_size_to_bytes( is_string( $wpmax_raw )     ? $wpmax_raw     : '' );
				$effective_b   = self::get_effective_memory_limit_bytes();
				$effective_mb  = $effective_b > 0 ? (int) round( $effective_b / 1048576 ) : 0;
				$s_opts        = self::get_settings();
				$warn_pct      = (int) ( $s_opts['warn_pct']     ?? 70 );
				$danger_pct    = (int) ( $s_opts['danger_pct']   ?? 85 );
				$critical_pct  = (int) ( $s_opts['critical_pct'] ?? 95 );
				$warn_mb       = $effective_mb > 0 ? round( $effective_mb * $warn_pct     / 100, 1 ) : null;
				$danger_mb     = $effective_mb > 0 ? round( $effective_mb * $danger_pct   / 100, 1 ) : null;
				$critical_mb   = $effective_mb > 0 ? round( $effective_mb * $critical_pct / 100, 1 ) : null;
				$recs = array();
				if ( $effective_mb <= 0 ) {
					$recs[] = array( 'icon' => '🆘', 'color' => '#8B0000', 'bg' => '#fdf2f2', 'text' => __( 'Could not determine the effective memory limit. Check that WP_MEMORY_LIMIT or PHP memory_limit are set.', 'wp-memory-usage' ) );
				} elseif ( $effective_mb < 64 ) {
					$recs[] = array( 'icon' => '🆘', 'color' => '#8B0000', 'bg' => '#fdf2f2', 'text' => __( 'Effective limit is very low:', 'wp-memory-usage' ) . ' ' . $effective_mb . ' MB. ' . __( 'Most WordPress sites need at least 128 MB; WooCommerce, page builders or heavy plugins often need 256 MB or more. You will likely see out-of-memory errors.', 'wp-memory-usage' ) );
				} elseif ( $effective_mb < 128 ) {
					$recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec', 'text' => __( 'Effective limit is', 'wp-memory-usage' ) . ' ' . ( $effective_mb ) . ' MB. ' . __( 'Tight for a typical WordPress site. Consider raising to at least 128 MB (256 MB recommended). Add to wp-config.php: define(\'WP_MEMORY_LIMIT\', \'256M\');', 'wp-memory-usage' ) );
				} elseif ( $effective_mb < 256 ) {
					$recs[] = array( 'icon' => '✅', 'color' => '#2d6a2d', 'bg' => '#f2faf2', 'text' => __( 'Effective limit is', 'wp-memory-usage' ) . ' ' . ( $effective_mb ) . ' MB. ' . __( 'Acceptable for most sites. For WooCommerce, LMS or heavy builders, 256 MB+ is better.', 'wp-memory-usage' ) );
				} else {
					$recs[] = array( 'icon' => '✅', 'color' => '#2d6a2d', 'bg' => '#f2faf2', 'text' => __( 'Effective limit is', 'wp-memory-usage' ) . ' ' . ( $effective_mb ) . ' MB. ' . __( 'Good.', 'wp-memory-usage' ) );
				}
				if ( $php_limit_b > 0 && $wp_limit_b > 0 && $wp_limit_b > $php_limit_b ) {
					$recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec', 'text' => 'WP_MEMORY_LIMIT (' . ( (string) $wp_limit_raw ) . ') ' . __( 'is higher than PHP memory_limit', 'wp-memory-usage' ) . ' (' . ( (string) $php_limit_raw ) . '). ' . __( 'WordPress cannot exceed the PHP ceiling – the PHP limit wins. Raise memory_limit in php.ini / .htaccess / php_value.', 'wp-memory-usage' ) );
				}
				if ( $wp_limit_b > 0 && $wpmax_b > 0 && $wp_limit_b > $wpmax_b ) {
					$recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec', 'text' => 'WP_MEMORY_LIMIT (' . ( (string) $wp_limit_raw ) . ') ' . __( 'is higher than WP_MAX_MEMORY_LIMIT', 'wp-memory-usage' ) . ' (' . ( (string) $wpmax_raw ) . '). ' . __( 'WP_MAX_MEMORY_LIMIT should be ≥ WP_MEMORY_LIMIT.', 'wp-memory-usage' ) );
				}
				$gap_warn_danger = $danger_pct - $warn_pct;
				$gap_danger_crit = $critical_pct - $danger_pct;
				/* translators: 1: warning threshold percentage, 2: danger threshold percentage, 3: gap in percentage points */
				if ( $gap_warn_danger < 5 ) { $recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec', 'text' => sprintf( __( 'Warning (%d%%) and Danger (%d%%) thresholds are very close together (gap: %d%%). Consider a gap of at least 10%%.', 'wp-memory-usage' ), $warn_pct, $danger_pct, $gap_warn_danger ) ); }
				/* translators: 1: danger threshold percentage, 2: critical threshold percentage, 3: gap in percentage points */
				if ( $gap_danger_crit < 5 )  { $recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec', 'text' => sprintf( __( 'Danger (%d%%) and Critical (%d%%) thresholds are very close together (gap: %d%%). Consider a gap of at least 5%%.', 'wp-memory-usage' ), $danger_pct, $critical_pct, $gap_danger_crit ) ); }
				/* translators: %d: warning threshold percentage */
				if ( $warn_pct < 50 )         { $recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec', 'text' => sprintf( __( 'Warning threshold is very low (%d%%). You will receive many false-positive alerts on normal pages. A value of 65–75%% is typical.', 'wp-memory-usage' ), $warn_pct ) ); }
				/* translators: %d: critical threshold percentage */
				if ( $critical_pct < 90 )     { $recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec', 'text' => sprintf( __( 'Critical threshold is set to %d%% – that leaves little headroom before actual out-of-memory errors. Consider raising to 92–95%%.', 'wp-memory-usage' ), $critical_pct ) ); }
				/* translators: %d: critical threshold percentage */
				if ( $critical_pct > 98 )     { $recs[] = array( 'icon' => '⚠️', 'color' => '#7a5200', 'bg' => '#fef9ec', 'text' => sprintf( __( 'Critical threshold is very high (%d%%). At this level you may already be getting OOM errors before the alert fires. 95%% is a safer upper bound.', 'wp-memory-usage' ), $critical_pct ) ); }
				if ( count( $recs ) === 1 && $recs[0]['icon'] === '✅' ) {
					/* translators: 1: warning threshold percentage, 2: danger threshold percentage, 3: critical threshold percentage */
					$recs[] = array( 'icon' => '✅', 'color' => '#2d6a2d', 'bg' => '#f2faf2', 'text' => sprintf( __( 'Threshold settings look good: Warn %d%% / Danger %d%% / Critical %d%%.', 'wp-memory-usage' ), $warn_pct, $danger_pct, $critical_pct ) );
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
						<tr><td><code>WP_MEMORY_LIMIT</code></td><td><?php echo $wp_limit_raw ? esc_html( (string) $wp_limit_raw ) : '<em>' . esc_html__( 'not defined', 'wp-memory-usage' ) . '</em>'; ?></td><td><?php echo esc_html__( 'Memory limit for the regular (frontend) WordPress runtime.', 'wp-memory-usage' ); ?></td></tr>
						<tr><td><code>WP_MAX_MEMORY_LIMIT</code></td><td><?php echo $wpmax_raw ? esc_html( (string) $wpmax_raw ) : '<em>' . esc_html__( 'not defined', 'wp-memory-usage' ) . '</em>'; ?></td><td><?php echo esc_html__( 'Memory limit for admin-area tasks.', 'wp-memory-usage' ); ?></td></tr>
						<tr><td><code>PHP memory_limit</code></td><td><?php echo $php_limit_raw ? esc_html( (string) $php_limit_raw ) : '<em>' . esc_html__( 'unknown', 'wp-memory-usage' ) . '</em>'; ?></td><td><?php echo esc_html__( 'The PHP-level memory limit set by your server.', 'wp-memory-usage' ); ?></td></tr>
						<tr><td><strong><?php echo esc_html__( 'Effective limit used for alerts', 'wp-memory-usage' ); ?></strong></td><td><strong><?php echo $effective_b > 0 ? esc_html( self::format_bytes( (int) $effective_b ) ) : esc_html__( 'unlimited/unknown', 'wp-memory-usage' ); ?></strong></td><td><?php echo esc_html__( 'Lower of the WordPress and PHP limits.', 'wp-memory-usage' ); ?></td></tr>
					</tbody>
				</table>
				<?php if ( $effective_mb > 0 ) : ?>
				<h3 style="margin-top:20px;"><?php echo esc_html__( 'Alert thresholds in absolute values', 'wp-memory-usage' ); ?></h3>
				<table class="widefat striped" style="max-width:600px;">
					<thead><tr><th><?php echo esc_html__( 'Level', 'wp-memory-usage' ); ?></th><th><?php echo esc_html__( '%', 'wp-memory-usage' ); ?></th><th><?php echo esc_html__( '≈ MB', 'wp-memory-usage' ); ?></th></tr></thead>
					<tbody>
						<tr><td><strong style="color:#f0ad4e;">⚠ <?php echo esc_html__( 'Warn', 'wp-memory-usage' ); ?></strong></td><td><?php echo esc_html( $warn_pct ); ?>%</td><td><?php echo esc_html( $warn_mb !== null ? $warn_mb . ' MB' : '–' ); ?></td></tr>
						<tr><td><strong style="color:#d9534f;">🔴 <?php echo esc_html__( 'Danger', 'wp-memory-usage' ); ?></strong></td><td><?php echo esc_html( $danger_pct ); ?>%</td><td><?php echo esc_html( $danger_mb !== null ? $danger_mb . ' MB' : '–' ); ?></td></tr>
						<tr><td><strong style="color:#8B0000;">🆘 <?php echo esc_html__( 'Critical', 'wp-memory-usage' ); ?></strong></td><td><?php echo esc_html( $critical_pct ); ?>%</td><td><?php echo esc_html( $critical_mb !== null ? $critical_mb . ' MB' : '–' ); ?></td></tr>
					</tbody>
				</table>
				<?php endif; ?>
				<h3 style="margin-top:20px;"><?php echo esc_html__( 'Assessment & Recommendations', 'wp-memory-usage' ); ?></h3>
				<?php foreach ( $recs as $rec ) : ?>
				<div style="display:flex;align-items:flex-start;gap:10px;padding:10px 14px;margin-bottom:8px;border-radius:5px;border-left:4px solid <?php echo esc_attr( $rec['color'] ); ?>;background:<?php echo esc_attr( $rec['bg'] ); ?>;">
					<span style="font-size:18px;line-height:1.3;"><?php echo esc_html( $rec['icon'] ); ?></span>
					<span style="color:<?php echo esc_attr( $rec['color'] ); ?>;font-size:13px;"><?php echo esc_html( $rec['text'] ); ?></span>
				</div>
				<?php endforeach; ?>

			<?php elseif ( 'actions' === $tab ) : ?>
				<h2><?php echo esc_html__( 'What you can do', 'wp-memory-usage' ); ?></h2>
				<p><?php echo esc_html__( 'This tab explains practical next steps when you receive a memory alert.', 'wp-memory-usage' ); ?></p>
				<h3><?php echo esc_html__( 'Step 1: Understand the severity', 'wp-memory-usage' ); ?></h3>
				<ul style="list-style: disc; padding-left: 20px;">
					<li><strong><?php echo esc_html__( 'Warning', 'wp-memory-usage' ); ?></strong>: <?php echo esc_html__( 'Close to the limit. Usually no immediate outage, but you should watch it.', 'wp-memory-usage' ); ?></li>
					<li><strong><?php echo esc_html__( 'Danger', 'wp-memory-usage' ); ?></strong>: <?php echo esc_html__( 'High risk. Actions like editing, imports, backups or WooCommerce tasks may fail.', 'wp-memory-usage' ); ?></li>
					<li><strong><?php echo esc_html__( 'Critical', 'wp-memory-usage' ); ?></strong>: <?php echo esc_html__( 'Very likely to trigger "Allowed memory size exhausted". Act now to avoid outages.', 'wp-memory-usage' ); ?></li>
				</ul>
				<h3><?php echo esc_html__( 'Step 2: Identify what triggered it', 'wp-memory-usage' ); ?></h3>
				<p><?php echo esc_html__( 'Open the "Digest" or "History" tab and look at the context (URL, admin screen, AJAX action, REST route, cron).', 'wp-memory-usage' ); ?></p>
				<h3><?php echo esc_html__( 'Common actions', 'wp-memory-usage' ); ?></h3>
				<ol style="list-style: decimal; padding-left: 20px;">
					<li><?php echo esc_html__( 'If it happens during imports/backups/crawlers: schedule these jobs at low traffic times.', 'wp-memory-usage' ); ?></li>
					<li><?php echo esc_html__( 'Increase the PHP memory limit if your hosting allows it (often the quickest fix).', 'wp-memory-usage' ); ?></li>
					<li><?php echo esc_html__( 'Update WordPress core, themes and plugins.', 'wp-memory-usage' ); ?></li>
					<li><?php echo esc_html__( 'If it happens on frontend pages: check the page and its plugins. Disable suspects temporarily to confirm.', 'wp-memory-usage' ); ?></li>
				</ol>

			<?php elseif ( 'digest' === $tab ) :
				// ── Delete action ─────────────────────────────────────────────────
				$delete_notice = '';
				if (
					isset( $_POST['wpmu_digest_delete'], $_POST['wpmu_digest_delete_nonce'], $_POST['wpmu_digest_delete_file'] ) &&
					wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpmu_digest_delete_nonce'] ) ), 'wpmu_digest_delete' ) &&
					current_user_can( self::CAP )
				) {
					$del_fn = sanitize_file_name( wp_unslash( $_POST['wpmu_digest_delete_file'] ) );
					if ( $del_fn === 'all' || preg_match( '/^digest_[\d\-]+\.cgibak$/', $del_fn ) ) {
						$ok = self::delete_digest( $del_fn );
						if ( $ok !== false ) {
							$delete_notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Deleted.', 'wp-memory-usage' ) . '</p></div>';
						} else {
							$delete_notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not delete.', 'wp-memory-usage' ) . '</p></div>';
						}
					}
				}
				echo wp_kses_post( $delete_notice );

				// ── List digests ──────────────────────────────────────────────────
				$digest_files = self::list_digests();

				// ── Selected digest ───────────────────────────────────────────────
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

				// ── Load data ────────────────────────────────────────────────────
				$data = array();
				if ( $merge_all && ! empty( $digest_files ) ) {
					foreach ( array_keys( $digest_files ) as $fn ) {
						$d = self::get_digest( $fn );
						if ( ! is_array( $d ) || empty( $d ) ) { continue; }
						if ( empty( $data ) ) { $data = $d; continue; }
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
						if ( isset( $d['interval']['first'] ) && $d['interval']['first'] < ( $data['interval']['first'] ?? PHP_INT_MAX ) ) {
							$data['interval']['first'] = $d['interval']['first'];
						}
						if ( isset( $d['interval']['last'] ) && $d['interval']['last'] > ( $data['interval']['last'] ?? 0 ) ) {
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
					$data = self::get_digest( $selected_file );
					if ( ! is_array( $data ) ) { $data = array(); }
				} elseif ( ! empty( $digest_files ) ) {
					$selected_file = array_key_first( $digest_files );
					$data = self::get_digest( $selected_file );
					if ( ! is_array( $data ) ) { $data = array(); }
				}

				$digest_nonce = wp_create_nonce( 'wpmu_digest_sel' );
				?>
				<div style="margin:16px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#f9f9f9;padding:12px 16px;border:1px solid #ddd;border-radius:6px;">
					<form method="get" style="display:contents;">
						<input type="hidden" name="page" value="wpmu-memory-alerts">
						<input type="hidden" name="tab" value="digest">
						<input type="hidden" name="_wpmu_tab_nonce" value="<?php echo esc_attr( $tab_nonce ); ?>">
						<input type="hidden" name="wpmu_digest_nonce" value="<?php echo esc_attr( $digest_nonce ); ?>">
						<label for="wpmu_digest_sel" style="font-weight:600;white-space:nowrap;"><?php echo esc_html__( 'Show Digest:', 'wp-memory-usage' ); ?></label>
						<select name="wpmu_digest_sel" id="wpmu_digest_sel" onchange="this.form.submit()" style="min-width:280px;padding:5px 8px;">
							<?php if ( empty( $digest_files ) ) : ?>
								<option value=""><?php echo esc_html__( '— no digest files found —', 'wp-memory-usage' ); ?></option>
							<?php else : ?>
								<?php if ( count( $digest_files ) > 1 ) : ?>
								<option value="__all__" <?php selected( $merge_all, true ); ?>><?php
									/* translators: %d: number of digest files */
									printf( esc_html__( '⊕ Merge all %d digest files', 'wp-memory-usage' ), count( $digest_files ) );
								?></option>
								<option disabled>──────────────────────────────</option>
								<?php endif; ?>
								<?php foreach ( $digest_files as $fn => $label ) : ?>
								<option value="<?php echo esc_attr( $fn ); ?>" <?php selected( ! $merge_all && $selected_file === $fn ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<noscript><button type="submit" class="button"><?php echo esc_html__( 'Show', 'wp-memory-usage' ); ?></button></noscript>
					</form>
					<?php if ( $selected_file && ! $merge_all ) : ?>
					<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this digest? This cannot be undone.', 'wp-memory-usage' ) ); ?>');"> 
						<input type="hidden" name="page" value="wpmu-memory-alerts">
						<input type="hidden" name="tab"  value="digest">
						<input type="hidden" name="_wpmu_tab_nonce"         value="<?php echo esc_attr( $tab_nonce ); ?>">
						<input type="hidden" name="wpmu_digest_delete"      value="1">
						<input type="hidden" name="wpmu_digest_delete_file" value="<?php echo esc_attr( $selected_file ); ?>">
						<input type="hidden" name="wpmu_digest_delete_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpmu_digest_delete' ) ); ?>">
						<button type="submit" class="button button-small" style="border-color:#d9534f;color:#d9534f;background:#fff;">🗑 <?php echo esc_html__( 'Delete this digest', 'wp-memory-usage' ); ?></button>
					</form>
					<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete all digests? This cannot be undone.', 'wp-memory-usage' ) ); ?>');"> 
						<input type="hidden" name="page" value="wpmu-memory-alerts">
						<input type="hidden" name="tab"  value="digest">
						<input type="hidden" name="_wpmu_tab_nonce"         value="<?php echo esc_attr( $tab_nonce ); ?>">
						<input type="hidden" name="wpmu_digest_delete"      value="1">
						<input type="hidden" name="wpmu_digest_delete_file" value="all">
						<input type="hidden" name="wpmu_digest_delete_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpmu_digest_delete' ) ); ?>">
						<button type="submit" class="button button-small" style="border-color:#d9534f;color:#d9534f;background:#fff;">🗑 <?php echo esc_html__( 'Delete all digests', 'wp-memory-usage' ); ?></button>
					</form>
					<?php endif; ?>
					<?php if ( $merge_all ) : ?>
					<span style="background:#e7f3ff;border:1px solid #3498db;border-radius:4px;padding:4px 10px;font-size:12px;color:#0c5d9e;font-weight:600;">
						<?php
						/* translators: %d: number of merged digest files */
						printf( esc_html__( 'Merged: %d files', 'wp-memory-usage' ), count( $digest_files ) );
						?>
					</span>
					<?php endif;
					$currenttime = wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) );
					echo esc_html__( 'Servertime', 'wp-memory-usage' ) . ': ' . esc_html( $currenttime );
					?>
				</div>
				<?php
				if ( count( $data ) > 0 ) {
					$dur  = $data['interval']['duration_sec'] ?? 0;
					$mins = floor( $dur / 60 );
					$secs = $dur % 60;
					$interval_min = 60 * 3;
					$per_interval = array();
					foreach ( $data['interval']['per_minute'] as $minute => $count ) {
						$ts_slot  = strtotime( $minute );
						$slot_ts  = floor( $ts_slot / ( $interval_min * 60 ) ) * ( $interval_min * 60 );
						$slot_key = wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $slot_ts );
						$per_interval[ $slot_key ] = ( $per_interval[ $slot_key ] ?? 0 ) + $count;
					}
					$max_count = $per_interval ? max( $per_interval ) : 1;
					?>
					<table><tr><td valign="top">
					<table class="widefat striped" style="max-width:400px;">
						<thead><tr><th colspan="2"><h3><?php echo esc_html__( 'Status Summary', 'wp-memory-usage' ); ?></h3></th></tr>
						<tr><th><?php echo esc_html__( 'Status', 'wp-memory-usage' ); ?></th><th><?php echo esc_html__( 'Occurrences', 'wp-memory-usage' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $data['status'] as $s => $count ) :
							$color = '#5cb85c';
							if ( $s === 'warn' )     { $color = '#f0ad4e'; }
							elseif ( $s === 'danger' ) { $color = '#d9534f'; }
							elseif ( $s === 'critical' ) { $color = '#8B0000'; }
						?>
						<tr><td><strong style="color:<?php echo esc_html( $color ); ?>"><?php echo esc_html( strtoupper( (string) $s ) ); ?></strong></td><td><?php echo esc_html( $count ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</td><td valign="top">
					<table class="widefat striped" style="max-width:400px;">
						<thead><tr><th colspan="2"><h3><?php echo esc_html__( 'Request types', 'wp-memory-usage' ); ?></h3></th></tr>
						<tr><th><?php echo esc_html__( 'Type', 'wp-memory-usage' ); ?></th><th><?php echo esc_html__( 'Occurrences', 'wp-memory-usage' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $data['type'] as $type => $count ) : ?>
						<tr><td><?php echo esc_html( $type ); ?></td><td><?php echo esc_html( $count ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</td><td valign="top">
					<table class="widefat striped" style="max-width:600px;">
						<thead><tr><th colspan="3"><h3><?php echo esc_html__( 'Events each', 'wp-memory-usage' ) . ' ' . esc_html( $interval_min ) . ' ' . esc_html__( 'Minutes', 'wp-memory-usage' ); ?></h3></th></tr></thead>
						<tr><th colspan="3">
							<?php echo esc_html__( 'From', 'wp-memory-usage' ); ?>: <strong><?php echo esc_html( wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $data['interval']['first'] ) ); ?></strong> &nbsp;|&nbsp;
							<?php echo esc_html__( 'To', 'wp-memory-usage' ); ?>: <strong><?php echo esc_html( wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $data['interval']['last'] ) ); ?></strong> &nbsp;|&nbsp;
							<?php echo esc_html__( 'Duration', 'wp-memory-usage' ); ?>: <strong><?php echo esc_html( $mins ) . ' ' . esc_html__( 'min.', 'wp-memory-usage' ) . ' ' . esc_html( $secs ) . ' ' . esc_html__( 'sec.', 'wp-memory-usage' ); ?></strong>
						</th></tr>
						<thead><tr><th><?php echo esc_html__( 'Time window', 'wp-memory-usage' ); ?></th><th><?php echo esc_html__( 'Occurrences', 'wp-memory-usage' ); ?></th><th><?php echo esc_html__( 'Event Distribution', 'wp-memory-usage' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $per_interval as $slot => $count ) :
							$bar_width = round( ( $count / $max_count ) * 200 );
							$bar_color = $count >= 10 ? '#d9534f' : ( $count >= 5 ? '#f0ad4e' : '#5cb85c' );
						?>
						<tr>
							<td><?php echo esc_html( $slot ); ?> – <?php echo esc_html( wp_date( 'H:i', strtotime( $slot ) + $interval_min * 60 ) ); ?></td>
							<td><strong><?php echo esc_html( $count ); ?></strong></td>
							<td><div style="width:<?php echo esc_attr( $bar_width ); ?>px;height:14px;background:<?php echo esc_attr( $bar_color ); ?>;border-radius:3px;"></div></td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</td></tr></table>

					<h3>URIs</h3>
					<?php
					uasort( $data['uri'], fn( $a, $b ) => $b['total'] <=> $a['total'] );
					$uri_json = json_encode( $data['uri'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP );
					$home_url = json_encode( trailingslashit( home_url() ) );
					?>
					<style>
					.wpmu-uri-wrap{max-width:1150px;margin-top:8px;}.wpmu-uri-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;}.wpmu-toggle-btn{display:inline-flex;align-items:center;gap:4px;padding:4px 11px;border-radius:4px;border:2px solid transparent;font-size:12px;font-weight:600;cursor:pointer;background:#fff;transition:opacity .15s,box-shadow .15s;user-select:none;line-height:1.5;}.wpmu-toggle-btn[data-col="warn"]{border-color:#f0ad4e;color:#7a5200;}.wpmu-toggle-btn[data-col="danger"]{border-color:#d9534f;color:#a02020;}.wpmu-toggle-btn[data-col="critical"]{border-color:#8B0000;color:#8B0000;}.wpmu-toggle-btn.is-hidden{opacity:.35;}.wpmu-toggle-btn .btn-icon{font-size:10px;}#wpmu-uri-tbl{width:100%;border-collapse:collapse;font-size:13px;}#wpmu-uri-tbl th{background:#f5f5f5;padding:7px 10px;text-align:left;border-bottom:2px solid #ddd;white-space:nowrap;cursor:pointer;user-select:none;}#wpmu-uri-tbl th:hover{background:#eaeaea;}#wpmu-uri-tbl th .si{font-size:10px;margin-left:3px;color:#aaa;}#wpmu-uri-tbl th.sa .si::after{content:"▲";color:#0073aa;}#wpmu-uri-tbl th.sd .si::after{content:"▼";color:#0073aa;}#wpmu-uri-tbl th:not(.sa):not(.sd) .si::after{content:"⇅";}#wpmu-uri-tbl td{padding:6px 10px;border-bottom:1px solid #eee;vertical-align:middle;}#wpmu-uri-tbl tbody tr:hover td{background:#f9f9f9;}#wpmu-uri-tbl td.nr{text-align:right;font-variant-numeric:tabular-nums;}.wpmu-badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;}.wpmu-bw{background:#f0ad4e;color:#3d2b00;}.wpmu-bd{background:#d9534f;color:#fff;}.wpmu-bc{background:#8B0000;color:#fff;}.wpmu-avg{color:#666;font-size:12px;}.wpmu-max{font-weight:700;}
					</style>
					<div class="wpmu-uri-wrap">
						<div class="wpmu-uri-controls">
							<strong style="font-size:13px;"><?php echo esc_html__( 'Toggle Columns', 'wp-memory-usage' ); ?>:</strong>
							<button type="button" class="wpmu-toggle-btn" data-col="warn"><span class="btn-icon">✓</span> <?php echo esc_html__( '⚠ warn', 'wp-memory-usage' ); ?></button>
							<button type="button" class="wpmu-toggle-btn" data-col="danger"><span class="btn-icon">✓</span> <?php echo esc_html__( '🔴 danger', 'wp-memory-usage' ); ?></button>
							<button type="button" class="wpmu-toggle-btn" data-col="critical"><span class="btn-icon">✓</span> <?php echo esc_html__( '🆘 critical', 'wp-memory-usage' ); ?></button>
						</div>
						<table id="wpmu-uri-tbl" class="widefat striped">
							<thead><tr>
								<th data-col="uri">URI <span class="si"></span></th>
								<th data-col="total" class="nr sd"><?php echo esc_html__( 'total', 'wp-memory-usage' ); ?> <span class="si"></span></th>
								<th data-col="warn"     class="nr col-warn"    style="text-align:center;"><?php echo esc_html__( '⚠ warn', 'wp-memory-usage' ); ?><span class="si"></span></th>
								<th data-col="danger"   class="nr col-danger"  style="text-align:center;"><?php echo esc_html__( '🔴 danger', 'wp-memory-usage' ); ?><span class="si"></span></th>
								<th data-col="critical" class="nr col-critical" style="text-align:center;"><?php echo esc_html__( '🆘 critical', 'wp-memory-usage' ); ?><span class="si"></span></th>
								<th data-col="avg" class="nr"><?php echo esc_html__( 'Ø average', 'wp-memory-usage' ); ?><span class="si"></span></th>
								<th data-col="max" class="nr"><?php echo esc_html__( 'max', 'wp-memory-usage' ); ?> <span class="si"></span></th>
							</tr></thead>
							<tbody id="wpmu-uri-tbody"></tbody>
						</table>
					</div>
					<?php $wpmu_no_data = __( 'No data', 'wp-memory-usage' ); ?>
					<script>
					var WPMU_NO_DATA=<?php echo wp_json_encode($wpmu_no_data);?>;
					(function(){var RAW=<?php echo wp_json_encode(json_decode($uri_json));?>;var HOMEURL=<?php echo wp_json_encode(json_decode($home_url));?>;var rows=Object.entries(RAW).map(function(e){var uri=e[0],d=e[1];return{uri:uri,total:d.total||0,warn:d.warn||0,danger:d.danger||0,critical:d.critical||0,avg:d.avg_usage||0,max:d.max_usage||0};});var sortCol='total',sortDir='desc',hidden={};function fmtB(b){b=parseInt(b,10)||0;if(!b)return'–';var u=['B','KB','MB','GB'],i=0,v=b;while(v>=1024&&i<u.length-1){v/=1024;i++;}return(i===0?v.toFixed(0):v.toFixed(1))+'\u202f'+u[i];}function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}function render(){var sorted=rows.slice().sort(function(a,b){var av=a[sortCol],bv=b[sortCol];if(typeof av==='string'){av=av.toLowerCase();bv=bv.toLowerCase();return sortDir==='asc'?(av<bv?-1:av>bv?1:0):(av>bv?-1:av<bv?1:0);}return sortDir==='asc'?av-bv:bv-av;});var html='';sorted.forEach(function(r){var uriDisplay=r.uri.replace(/\?doing_wp_cron=[\d.]+/,'?doing_wp_cron=\u2026');var fullUrl=HOMEURL+r.uri.replace(/^\//,'');var warnCell=hidden['warn']?'':'<td class="nr col-warn" style="text-align:center;">'+(r.warn?'<span class="wpmu-badge wpmu-bw">'+r.warn+'</span>':'<span style="color:#ccc">–</span>')+'</td>';var dangerCell=hidden['danger']?'':'<td class="nr col-danger" style="text-align:center;">'+(r.danger?'<span class="wpmu-badge wpmu-bd">'+r.danger+'</span>':'<span style="color:#ccc">–</span>')+'</td>';var criticalCell=hidden['critical']?'':'<td class="nr col-critical" style="text-align:center;">'+(r.critical?'<span class="wpmu-badge wpmu-bc">'+r.critical+'</span>':'<span style="color:#ccc">–</span>')+'</td>';html+='<tr>'+'<td><a href="'+esc(fullUrl)+'" target="_blank" rel="noopener" style="word-break:break-all;">'+esc(uriDisplay)+'</a></td>'+'<td class="nr"><strong>'+r.total+'</strong></td>'+warnCell+dangerCell+criticalCell+'<td class="nr wpmu-avg">'+fmtB(r.avg)+'</td>'+'<td class="nr wpmu-max">'+fmtB(r.max)+'</td>'+'</tr>';});document.getElementById('wpmu-uri-tbody').innerHTML=html||'<tr><td colspan="7" style="color:#999;text-align:center;padding:12px;">'+WPMU_NO_DATA+'</td></tr>';document.querySelectorAll('#wpmu-uri-tbl thead th').forEach(function(th){th.classList.remove('sa','sd');if(th.dataset.col===sortCol){th.classList.add(sortDir==='asc'?'sa':'sd');}var col=th.dataset.col;if(col&&(col==='warn'||col==='danger'||col==='critical')){th.style.display=hidden[col]?'none':'';}});}document.querySelectorAll('#wpmu-uri-tbl thead th').forEach(function(th){th.addEventListener('click',function(){var col=this.dataset.col;if(!col)return;if(sortCol===col){sortDir=sortDir==='asc'?'desc':'asc';}else{sortCol=col;sortDir=col==='uri'?'asc':'desc';}render();});});document.querySelectorAll('.wpmu-toggle-btn').forEach(function(btn){btn.addEventListener('click',function(){var col=this.dataset.col;hidden[col]=!hidden[col];this.classList.toggle('is-hidden',!!hidden[col]);this.querySelector('.btn-icon').textContent=hidden[col]?'✕':'✓';render();});});render();})();
					</script>
					<?php
				} else {
					echo '<h2>' . esc_html__( 'No digest created yet. See "Digest interval" in the settings.', 'wp-memory-usage' ) . '</h2>';
				}
				?>

			<?php elseif ( 'history' === $tab ) :
				$log_entries = self::get_log();
				$totalanz    = count( $log_entries );
				$log         = array_slice( $log_entries, -1 * self::$event_anz_display );
				$log         = array_reverse( $log );
				?>
				<h2><?php
				if ( self::$event_anz_display >= $totalanz ) {
					echo esc_html( $totalanz ) . ' ' . esc_html__( 'recent events', 'wp-memory-usage' ) . ', ';
				} else {
					/* translators: 1: number of displayed events, 2: number of total stored events */
					echo esc_html( sprintf( __( '%1$d recent events displayed (%2$d stored events),', 'wp-memory-usage' ), self::$event_anz_display, $totalanz ) );
				}
				$currenttime = wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) );
				echo esc_html__( 'Servertime', 'wp-memory-usage' ) . ': ' . esc_html( $currenttime );
				?></h2>
				<?php if ( empty( $log ) ) : ?>
					<p><?php echo esc_html__( 'No events recorded yet.', 'wp-memory-usage' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width: 1200px;">
						<thead><tr>
							<th><?php echo esc_html__( 'Time', 'wp-memory-usage' ); ?></th>
							<th><?php echo esc_html__( 'State', 'wp-memory-usage' ); ?></th>
							<th><?php echo esc_html__( 'Usage / Limit', 'wp-memory-usage' ); ?></th>
							<th><?php echo esc_html__( 'Type', 'wp-memory-usage' ); ?></th>
							<th><?php echo esc_html__( 'URI', 'wp-memory-usage' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $log as $e1 ) :
							$c      = isset( $e1['c'] ) && is_array( $e1['c'] ) ? $e1['c'] : array();
							$lastmb = (int) $e1['l'];
							$perc   = $lastmb > 0 ? (int) ( ( (int) $e1['u'] ) / $lastmb * 100 ) : 0;
						?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), (int) $e1['t'] ) ); ?></td>
							<td><strong><?php echo esc_html( strtoupper( (string) $e1['s'] ) ); echo '<br>' . esc_html( $perc ) . '%'; ?></strong></td>
							<td><?php echo esc_html( self::format_bytes( (int) $e1['u'] ) . ' / ' . ( (int) $e1['l'] > 0 ? self::format_bytes( (int) $e1['l'] ) : __( 'unlimited', 'wp-memory-usage' ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( isset( $c['type'] ) ? $c['type'] : '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( isset( $c['uri'] ) ? $c['uri'] : '' ) ); ?></code></td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

			<?php elseif ( 'check_installation' === $tab ) :
				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
				WP_Filesystem();
				$checks = array();
				// PHP version
				$php_min     = '7.4.0'; $php_rec = '8.0.0'; $php_current = PHP_VERSION;
				if ( version_compare( $php_current, $php_min, '<' ) ) {
					$checks[] = array( 'status' => 'error', 'label' => __( 'PHP Version', 'wp-memory-usage' ), 'hint' => __( 'Upgrade PHP to at least 7.4.', 'wp-memory-usage' ), 'detail' => 'PHP ' . $php_current . ' – minimum: ' . $php_min );
				} elseif ( version_compare( $php_current, $php_rec, '<' ) ) {
					$checks[] = array( 'status' => 'warn',  'label' => __( 'PHP Version', 'wp-memory-usage' ), 'hint' => __( 'Consider upgrading to PHP 8.0+.', 'wp-memory-usage' ), 'detail' => 'PHP ' . $php_current . ' – PHP ' . $php_rec . '+ recommended' );
				} else {
					$checks[] = array( 'status' => 'ok',    'label' => __( 'PHP Version', 'wp-memory-usage' ), 'hint' => '', 'detail' => 'PHP ' . $php_current . ': OK' );
				}
				foreach ( array( 'json', 'pcre' ) as $ext ) {
					$checks[] = array(
						'status' => extension_loaded( $ext ) ? 'ok' : 'error',
						'label'  => __( 'PHP extension:', 'wp-memory-usage' ) . ' ' . $ext,
						'detail' => 'ext/' . $ext . ' ' . ( extension_loaded( $ext ) ? __( 'loaded', 'wp-memory-usage' ) : __( 'NOT loaded', 'wp-memory-usage' ) ),
						'hint'   => extension_loaded( $ext ) ? '' : __( 'Enable in php.ini.', 'wp-memory-usage' ),
					);
				}

				// Storage backend specific checks
				if ( self::get_backend() === 'db' ) {
					global $wpdb;
					$table = self::db_log_table();
					// phpcs:disable WordPress.DB.DirectDatabaseQuery
					$tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table;
					// phpcs:enable WordPress.DB.DirectDatabaseQuery
					if ( $tbl_exists ) {
						// phpcs:disable WordPress.DB.DirectDatabaseQuery
						$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						// phpcs:enable WordPress.DB.DirectDatabaseQuery
						$checks[] = array( 'status' => 'ok', 'label' => __( 'DB table exists', 'wp-memory-usage' ), 'detail' => $table . ' (' . $row_count . ' rows)', 'hint' => '' );
					} else {
						$checks[] = array( 'status' => 'warn', 'label' => __( 'DB table exists', 'wp-memory-usage' ), 'detail' => $table . ' ' . __( 'does not exist yet – will be created on first save', 'wp-memory-usage' ), 'hint' => __( 'Save settings once to create the table.', 'wp-memory-usage' ) );
					}
				} else {
					// File backend checks
					$log_dir = self::WPMU_LOG_PATH;
					if ( is_dir( $log_dir ) ) {
						$checks[] = array( 'status' => 'ok', 'label' => __( 'Log directory exists', 'wp-memory-usage' ), 'detail' => esc_html( $log_dir ), 'hint' => '' );
					} else {
						$created  = $wp_filesystem->mkdir( $log_dir, 0755 );
						$checks[] = array(
							'status' => $created ? 'warn' : 'error',
							'label'  => __( 'Log directory exists', 'wp-memory-usage' ),
							'detail' => $created ? __( 'Created now: ', 'wp-memory-usage' ) . esc_html( $log_dir ) : __( 'Cannot create: ', 'wp-memory-usage' ) . esc_html( $log_dir ),
							'hint'   => $created ? '' : 'mkdir -p ' . $log_dir . ' && chmod 755 ' . $log_dir,
						);
					}
					if ( is_dir( $log_dir ) ) {
						$checks[] = array(
							'status' => $wp_filesystem->is_writable( $log_dir ) ? 'ok' : 'error',
							'label'  => __( 'Log directory writable', 'wp-memory-usage' ),
							'detail' => $wp_filesystem->is_writable( $log_dir ) ? __( 'Writable', 'wp-memory-usage' ) : __( 'NOT writable', 'wp-memory-usage' ),
							'hint'   => $wp_filesystem->is_writable( $log_dir ) ? '' : 'chmod 755 ' . $log_dir,
						);
					}
				}
				// ── Log files overview (file backend only) ────────────────
				if ( self::get_backend() === 'file' ) {
					$log_dir_ci = self::WPMU_LOG_PATH;
					if ( is_dir( $log_dir_ci ) ) {
						$log_file_ci     = $log_dir_ci . self::WPMU_LOG_FILE;
						$digest_files_ci = glob( $log_dir_ci . 'digest_*.cgibak' );
						$backup_files_ci = glob( $log_dir_ci . 'wpmu-log_*.cgibak' );
						$n_digest_ci     = is_array( $digest_files_ci ) ? count( $digest_files_ci ) : 0;
						$n_backup_ci     = is_array( $backup_files_ci ) ? count( $backup_files_ci ) : 0;
						$log_exists_ci   = file_exists( $log_file_ci );
						$log_size_ci     = $log_exists_ci ? size_format( filesize( $log_file_ci ) ) : '–';
						$detail_ci =
							__( 'Active log:', 'wp-memory-usage' ) . ' ' .
							( $log_exists_ci ? __( 'exists', 'wp-memory-usage' ) : __( 'not yet created', 'wp-memory-usage' ) ) .
							' (' . __( 'size:', 'wp-memory-usage' ) . ' ' . $log_size_ci . ') | ' .
							__( 'Digest files:', 'wp-memory-usage' ) . ' ' . $n_digest_ci . ' | ' .
							__( 'Backup log files:', 'wp-memory-usage' ) . ' ' . $n_backup_ci;
						$checks[] = array(
							'label'  => __( 'Log files overview', 'wp-memory-usage' ),
							'status' => 'ok',
							'detail' => $detail_ci,
							'hint'   => $n_digest_ci === 0 ? __( 'No digest files yet – they are created automatically on the first cron run.', 'wp-memory-usage' ) : '',
						);
					}
				}

				// ── WP-Cron ───────────────────────────────────────────────
				if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
					$checks[] = array( 'status' => 'warn', 'label' => 'WP-Cron', 'detail' => __( 'DISABLE_WP_CRON is TRUE – WP-Cron is disabled', 'wp-memory-usage' ), 'hint' => __( 'This plugin uses WP-Cron for log rotation and digest creation. Make sure a real system cron runs wp-cron.php regularly (e.g. every 5 minutes), otherwise no digests will be created.', 'wp-memory-usage' ) );
				} else {
					$next_cleanup = wp_next_scheduled( 'wpmu_cleanup_hook' );
					if ( $next_cleanup ) {
						$checks[] = array(
							'status' => 'ok',
							'label'  => __( 'WP-Cron: cleanup hook scheduled', 'wp-memory-usage' ),
							'detail' => __( 'Next run: ', 'wp-memory-usage' ) . wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $next_cleanup ),
							'hint'   => '',
						);
					} else {
						$checks[] = array(
							'status' => 'warn',
							'label'  => __( 'WP-Cron: cleanup hook scheduled', 'wp-memory-usage' ),
							'detail' => __( 'wpmu_cleanup_hook is NOT scheduled', 'wp-memory-usage' ),
							'hint'   => __( 'Deactivate and reactivate the plugin, or visit the settings page once to trigger rescheduling.', 'wp-memory-usage' ),
						);
					}
				}

				// ── Email alerts ──────────────────────────────────────────
				if ( function_exists( 'wp_mail' ) ) {
					$opts_chk   = self::get_settings();
					$mail_en    = ! empty( $opts_chk['send_email'] );
					$mail_to    = $opts_chk['email_to'] ?? '';
					$mail_valid = is_email( $mail_to );
					if ( $mail_en && $mail_valid ) {
						$checks[] = array(
							'label'  => __( 'Email alerts', 'wp-memory-usage' ),
							'status' => 'ok',
							'detail' => __( 'Enabled, recipient: ', 'wp-memory-usage' ) . esc_html( $mail_to ),
							'hint'   => '',
						);
					} elseif ( $mail_en && ! $mail_valid ) {
						$checks[] = array(
							'label'  => __( 'Email alerts', 'wp-memory-usage' ),
							'status' => 'error',
							'detail' => __( 'Enabled but recipient address is invalid: "', 'wp-memory-usage' ) . esc_html( $mail_to ) . '"',
							'hint'   => __( 'Enter a valid email address in Settings.', 'wp-memory-usage' ),
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

				// ── memory_get_peak_usage ─────────────────────────────────
				if ( function_exists( 'memory_get_peak_usage' ) ) {
					$checks[] = array(
						'label'  => 'memory_get_peak_usage()',
						'status' => 'ok',
						'detail' => __( 'Function available – current peak: ', 'wp-memory-usage' ) . size_format( memory_get_peak_usage( true ) ),
						'hint'   => '',
					);
				} else {
					$checks[] = array(
						'label'  => __( 'memory_get_peak_usage()', 'wp-memory-usage' ),
						'status' => 'warn',
						'detail' => __( 'Not available on this PHP build', 'wp-memory-usage' ),
						'hint'   => __( 'Peak memory tracking is disabled. The plugin falls back to memory_get_usage().', 'wp-memory-usage' ),
					);
				}

				// ── Disk space (file backend only) ────────────────────────
				if ( self::get_backend() === 'file' ) {
					$log_dir_ds = self::WPMU_LOG_PATH;
					if ( is_dir( $log_dir_ds ) ) {
						$free = @disk_free_space( $log_dir_ds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
						if ( $free !== false ) {
							$status_disk = $free < 10 * 1024 * 1024 ? 'warn' : 'ok';
							$checks[]    = array(
								'label'  => __( 'Disk space (log directory)', 'wp-memory-usage' ),
								'status' => $status_disk,
								'detail' => __( 'Free: ', 'wp-memory-usage' ) . size_format( $free ),
								'hint'   => $status_disk === 'warn' ? __( 'Very little free disk space – log files may not be written.', 'wp-memory-usage' ) : '',
							);
						}
					}
				}

				$count_ok = count( array_filter( $checks, fn( $c ) => $c['status'] === 'ok' ) );
				$count_warn = count( array_filter( $checks, fn( $c ) => $c['status'] === 'warn' ) );
				$count_error = count( array_filter( $checks, fn( $c ) => $c['status'] === 'error' ) );
				$overall_color = $count_error > 0 ? '#8B0000' : ( $count_warn > 0 ? '#7a5200' : '#2d6a2d' );
				$overall_bg    = $count_error > 0 ? '#fdf2f2' : ( $count_warn > 0 ? '#fef9ec' : '#f2faf2' );
				$overall_icon  = $count_error > 0 ? '🆘' : ( $count_warn > 0 ? '⚠️' : '✅' );
				$overall_text  = $count_error > 0 ? $count_error . ' ' . __( 'error(s),', 'wp-memory-usage' ) . ' ' . $count_warn . ' ' . __( 'warning(s)', 'wp-memory-usage' ) : ( $count_warn > 0 ? $count_warn . ' ' . __( 'warning(s) – no errors', 'wp-memory-usage' ) : __( 'All checks passed', 'wp-memory-usage' ) );
				?>
				<h2><?php echo esc_html__( 'Check Installation', 'wp-memory-usage' ); ?></h2>
				<div style="display:inline-flex;align-items:center;gap:10px;padding:10px 18px;border-radius:6px;margin-bottom:18px;background:<?php echo esc_attr( $overall_bg ); ?>;border:2px solid <?php echo esc_attr( $overall_color ); ?>;color:<?php echo esc_attr( $overall_color ); ?>;font-weight:700;font-size:14px;">
					<?php echo esc_html( $overall_icon ); ?> <?php echo esc_html( $overall_text ); ?>
					&nbsp;|&nbsp; ✅ <?php echo (int) $count_ok; ?> &nbsp; ⚠️ <?php echo (int) $count_warn; ?> &nbsp; 🆘 <?php echo (int) $count_error; ?>
				</div>
				<table class="widefat striped" style="max-width:1100px;">
					<thead><tr><th style="width:24px;"></th><th style="width:280px;"><?php echo esc_html__( 'Check', 'wp-memory-usage' ); ?></th><th><?php echo esc_html__( 'Result', 'wp-memory-usage' ); ?></th><th><?php echo esc_html__( 'Hint / Action', 'wp-memory-usage' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $checks as $chk ) :
						$icon  = $chk['status'] === 'ok' ? '✅' : ( $chk['status'] === 'warn' ? '⚠️' : '🆘' );
						$color = $chk['status'] === 'ok' ? '' : ( $chk['status'] === 'warn' ? '#7a5200' : '#8B0000' );
						$bg    = $chk['status'] === 'ok' ? '' : ( $chk['status'] === 'warn' ? '#fef9ec' : '#fdf2f2' );
					?>
					<tr<?php echo $bg ? ' style="background:' . esc_attr( $bg ) . ';"' : ''; ?>>
						<td style="text-align:center;font-size:16px;"><?php echo esc_html( $icon ); ?></td>
						<td><strong style="color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $chk['label'] ); ?></strong></td>
						<td style="font-family:monospace;font-size:12px;color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $chk['detail'] ); ?></td>
						<td style="font-size:12px;color:#555;"><?php echo esc_html( $chk['hint'] ); ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

			<?php elseif ( 'diagnose' === $tab ) :
				$diag_rows = array();
				$diag_rows[] = array( 'key' => 'memory_limit',               'value' => ini_get( 'memory_limit' ),                                    'note' => 'PHP memory limit' );
				$diag_rows[] = array( 'key' => 'memory_get_usage()',          'value' => self::format_bytes( memory_get_usage( true ) ),                'note' => 'Currently allocated' );
				$diag_rows[] = array( 'key' => 'memory_get_peak_usage(real)', 'value' => self::format_bytes( memory_get_peak_usage( true ) ),            'note' => 'Peak (OS-allocated)' );
				$diag_rows[] = array( 'key' => 'memory_get_peak_usage()',     'value' => self::format_bytes( memory_get_peak_usage( false ) ),           'note' => 'Peak (actually used)' );
				$diag_rows[] = array( 'key' => 'WP_MEMORY_LIMIT',             'value' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'n/a',        'note' => 'WordPress memory limit' );
				$diag_rows[] = array( 'key' => 'WP_MAX_MEMORY_LIMIT',         'value' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : 'n/a','note' => 'WordPress admin memory limit' );
				#$diag_rows[] = array( 'key' => 'Storage backend',             'value' => self::get_backend() === 'db' ? 'Database (' . self::db_log_table() . ')' : 'File system (' . self::WPMU_LOG_PATH . ')', 'note' => 'Active WPMU storage' );
				
				///////
				// OPcache
				$diag_rows[] = array( 'key' => 'opcache.enable',                'value' => ini_get('opcache.enable') ? 'On' : 'Off',                     'note' => '' );
				$diag_rows[] = array( 'key' => 'opcache.enable_cli',             'value' => ini_get('opcache.enable_cli') ? 'On' : 'Off',                 'note' => '' );
				$diag_rows[] = array( 'key' => 'opcache.memory_consumption',     'value' => ini_get('opcache.memory_consumption') . ' MB',                'note' => 'Reserved shared memory' );
				$diag_rows[] = array( 'key' => 'opcache.interned_strings_buffer','value' => ini_get('opcache.interned_strings_buffer') . ' MB',            'note' => 'String buffer' );
				$diag_rows[] = array( 'key' => 'opcache.max_accelerated_files',  'value' => ini_get('opcache.max_accelerated_files'),                     'note' => 'Max. cached files' );
				$diag_rows[] = array( 'key' => 'opcache.save_comments',          'value' => ini_get('opcache.save_comments') ? 'On' : 'Off',              'note' => 'Required for annotations' );
				if ( function_exists('opcache_get_status') ) {
					$ocs = opcache_get_status(false);
					if ( is_array($ocs) ) {
						$diag_rows[] = array( 'key' => 'opcache cached_scripts', 'value' => $ocs['opcache_statistics']['num_cached_scripts'] ?? 'n/a',    'note' => 'Currently cached scripts' );
						$diag_rows[] = array( 'key' => 'opcache memory_used',    'value' => self::format_bytes( $ocs['memory_usage']['used_memory'] ?? 0 ),'note' => '' );
						$diag_rows[] = array( 'key' => 'opcache hit_rate',       'value' => round( $ocs['opcache_statistics']['opcache_hit_rate'] ?? 0, 2 ) . ' %', 'note' => '' );
					}
				}

				// Garbage Collector
				$diag_rows[] = array( 'key' => 'zend.enable_gc',    'value' => ini_get('zend.enable_gc') ? 'On' : 'Off', 'note' => 'Cyclic garbage collector' );
				$diag_rows[] = array( 'key' => 'gc_enabled()',       'value' => gc_enabled() ? 'true' : 'false',           'note' => 'GC active at runtime?' );
				$diag_rows[] = array( 'key' => 'gc_collect_cycles()','value' => gc_collect_cycles() . ' cycles',           'note' => 'Manually collected' );

				// Extensions
				foreach ( array('xdebug','blackfire','newrelic','tideways','datadog') as $ext ) {
					$loaded = extension_loaded($ext);
					$diag_rows[] = array(
						'key'   => $ext,
						'value' => $loaded ? 'loaded' : 'not loaded',
						'note'  => ( $loaded && $ext === 'xdebug' ) ? 'Increases RAM by 20-30 MB!' : '',
					);
				}
				$diag_rows[] = array( 'key' => 'Loaded extensions', 'value' => count(get_loaded_extensions()), 'note' => 'More extensions = higher base RAM' );

				// General
				$diag_rows[] = array( 'key' => 'PHP Version',         'value' => PHP_VERSION,                               'note' => '' );
				$diag_rows[] = array( 'key' => 'PHP SAPI',            'value' => PHP_SAPI,                                  'note' => 'e.g. fpm-fcgi' );
				$diag_rows[] = array( 'key' => 'realpath_cache_size', 'value' => ini_get('realpath_cache_size'),             'note' => 'File path cache' );
				$diag_rows[] = array( 'key' => 'realpath_cache_ttl',  'value' => ini_get('realpath_cache_ttl') . 's',        'note' => 'TTL of path cache' );
				$diag_rows[] = array( 'key' => 'max_execution_time',  'value' => ini_get('max_execution_time') . 's',        'note' => '' );
				$diag_rows[] = array( 'key' => 'display_errors',      'value' => ini_get('display_errors') ? 'On' : 'Off',  'note' => 'Should be Off on production' );
				$diag_rows[] = array( 'key' => 'log_errors',          'value' => ini_get('log_errors') ? 'On' : 'Off',       'note' => '' );
				$diag_rows[] = array( 'key' => 'PHP_INT_SIZE',        'value' => PHP_INT_SIZE . ' Bytes',                   'note' => '8 = 64-bit system' );
				///////		
				
				$prompt_lines   = array();
				$prompt_lines[] = 'You are a PHP performance expert. Analyze the following PHP/WordPress diagnostic data:';
				$prompt_lines[] = '';
				$prompt_lines[] = '1. What stands out negatively (memory usage, configuration issues)?';
				$prompt_lines[] = '2. What are the most likely causes of high RAM consumption?';
				$prompt_lines[] = '3. Concrete optimization recommendations with exact php.ini settings.';
				$prompt_lines[] = '4. If two PHP versions are being compared: explain the differences.';
				$prompt_lines[] = '';
				$prompt_lines[] = 'Respond in a structured way. Be precise and technical.';
				$prompt_lines[] = '';
				$prompt_lines[] = '=== DIAGNOSTIC DATA ===';
				$prompt_lines[] = 'Date/Time:  ' . wp_date( 'Y-m-d H:i:s' );
				$prompt_lines[] = 'WordPress:  ' . get_bloginfo('version');
				$prompt_lines[] = 'Site URL:   ' . get_site_url();
				$prompt_lines[] = '';

				foreach ( $diag_rows as $r ) {
					$line = $r['key'] . ': ' . $r['value'];
					if ( ! empty( $r['note'] ) ) { $line .= '  // ' . $r['note']; }
					$prompt_lines[] = $line;
				}
				$prompt_text = implode( "\n", $prompt_lines );
				?>
				<h2><?php echo esc_html__( '🩺 Diagnose', 'wp-memory-usage' ); ?></h2>
				<table class="widefat striped" style="max-width:900px;margin-bottom:28px;font-family:monospace;font-size:12px;">
					<thead><tr><th style="width:270px;"><?php echo esc_html__( 'Setting', 'wp-memory-usage' ); ?></th><th style="width:180px;"><?php echo esc_html__( 'Value', 'wp-memory-usage' ); ?></th><th><?php echo esc_html__( 'Note', 'wp-memory-usage' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $diag_rows as $r ) : ?>
					<tr><td><code style="background:#f0f0f0;padding:1px 5px;border-radius:3px;"><?php echo esc_html( $r['key'] ); ?></code></td><td><strong><?php echo esc_html( $r['value'] ); ?></strong></td><td style="color:#555;font-family:sans-serif;font-size:12px;"><?php echo esc_html( $r['note'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<h3 style="margin-bottom:4px;">🤖 <?php echo esc_html__( 'AI Prompt (Copy & Paste)', 'wp-memory-usage' ); ?>
					<button type="button" id="wpmu-copy-btn" onclick="wpmuCopyPrompt()" style="margin-left:14px;padding:6px 18px;background:#2271b1;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;font-weight:600;vertical-align:middle;">📋 <?php echo esc_html__( 'Copy prompt', 'wp-memory-usage' ); ?></button>
					<span id="wpmu-copy-ok" style="display:none;margin-left:10px;color:#2d6a2d;font-size:13px;font-weight:600;">✓ <?php echo esc_html__( 'Copied to clipboard!', 'wp-memory-usage' ); ?></span>
				</h3>
				<textarea id="wpmu-diag-prompt" readonly style="width:100%;max-width:900px;height:280px;font-family:monospace;font-size:12px;line-height:1.6;padding:14px;background:#f8f9fa;border:1px solid #c3c4c7;border-radius:4px;resize:vertical;color:#1d2327;"><?php echo esc_textarea( $prompt_text ); ?></textarea>
				<script>function wpmuCopyPrompt(){var ta=document.getElementById('wpmu-diag-prompt');var ok=document.getElementById('wpmu-copy-ok');ta.select();ta.setSelectionRange(0,99999);if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(ta.value).then(function(){ok.style.display='inline';setTimeout(function(){ok.style.display='none';},2500);});}else{document.execCommand('copy');ok.style.display='inline';setTimeout(function(){ok.style.display='none';},2500);}}</script>

			<?php endif; ?>
		</div>
		<?php
	}

	/* =========================================================
	 * HELPERS
	 * ========================================================= */
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

	private static function get_current_memory_bytes( $use_peak ) {
		if ( $use_peak && function_exists( 'memory_get_peak_usage' ) ) {
			return (int) memory_get_peak_usage( true );
		}
		return (int) memory_get_usage( true );
	}

} // end class
endif;
