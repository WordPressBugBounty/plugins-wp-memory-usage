<?php
/*
Plugin Name: WP-Memory-Usage
Plugin URI: https://www.json-content-importer.com
Description: Show up memory limits, current memory usage, IP-Address, PHP-Version in the dashboard and admin footer
Author: Bernhard Kux
Version: 2.0.1
Author URI: https://www.json-content-importer.com
Text Domain: wp-memory-usage
Domain Path: /languages/
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Copyright Bernhard Kux 
*/

/* block direct requests */
if ( !function_exists( 'add_action' ) ) {
	echo 'Hello, this is a plugin: You must not call me directly.';
	exit;
}
defined('ABSPATH') OR exit;

// Load translations early for both admin and frontend.
function wpmu_load_textdomain() {
   $mofile = plugin_dir_path( __FILE__ ) . 'languages/wp-memory-usage-' . get_locale() . '.mo';
   load_textdomain( 'wp-memory-usage', $mofile );
	/*
    error_log( 'wpmu mo-file path: ' . $mofile );
    error_log( 'wpmu mo-file exists: ' . ( file_exists( $mofile ) ? 'YES' : 'NO' ) );
    error_log( 'wpmu locale: ' . get_locale() );
	$mo = new MO();
	if ( $mo->import_from_file( $mofile) ) {
		error_log( 'MO loaded OK, entries: ' . count( $mo->entries ) );
	} else {
		error_log( 'MO load FAILED' );
	}   
	#load_plugin_textdomain( 'wp-memory-usage', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	*/
}
add_action( 'plugins_loaded', 'wpmu_load_textdomain', 1 );

const WPMU_LOG_FILE = "wpmu-log.cgi";
const WPMU_LOG_PATH = 	ABSPATH. '../logs/wpmu/';

class wp_memory_usage {
		public $ipadr = "";
		public $servername = "";
		public $servernameout = "";
		public $memory = array();	

		
		public function __construct() {
			$this->get_ip_address();
			#$this->load_textdomain();
			#add_action( 'init', array( $this, 'load_textdomain' ) );
            add_action( 'init', array ($this, 'check_limit') );
			add_action( 'wp_dashboard_setup', array ($this, 'add_dashboard') );
			if ( is_multisite() ) { 
				add_action( 'wp_network_dashboard_setup', array ($this, 'add_dashboard') );
			}
			add_filter( 'admin_footer_text', array ($this, 'add_footer') );
		}
		
		private function getinput($fieldkey, $default="") {
			#var_Dump($_GET);
			if (
				! isset($_GET['_wpnonce']) ||
				! wp_verify_nonce( sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'memusage' )
			) {
				#echo "NONCE FAILED for GET $fieldkey<hr>";
				return ""; #wp_die('Ung�ltiger Nonce � Sicherheits�berpr�fung fehlgeschlagen.');
			}
			#echo "NONCE OK for GET $fieldkey<hr>";
			return sanitize_text_field(wp_unslash(($_GET[$fieldkey] ?? $default)));
		}
		
		private function getserverinput($fieldkey, $default="") {
			return sanitize_text_field(wp_unslash(($_SERVER[$fieldkey] ?? $default)));
		}
		
        #public function load_textdomain() {
		#	load_plugin_textdomain( 'wp-memory-usage', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		#}
		
        public function check_limit() {
			$this->memory['phplimit'] = "";
			$this->memory['phplimitunity'] = 'MB';
			if (!is_null(ini_get('memory_limit'))) {
				$phplimit_memory = ini_get('memory_limit');
				if (preg_match("/G$/i", $phplimit_memory)) {
					# set in gigabyte 
					$phplimit_memory = 1024 * preg_replace("/G$/i", "", $phplimit_memory);
				}
				$this->memory['phplimit'] = (int) $phplimit_memory;
			}
			$ret = $this->formatWP_MEMORY_LIMIT(WP_MEMORY_LIMIT);
			$this->memory["wpmb"] = $ret["mb"] ?? '';
			$this->memory["wpunity"] = $ret["unity"]  ?? '';
			
			$ret = $this->formatWP_MEMORY_LIMIT(WP_MAX_MEMORY_LIMIT);
			$this->memory["wpmaxmb"] = $ret["mb"]  ?? '';
			$this->memory["wpmaxunity"] = $ret["unity"]  ?? '';
        }
		
		public function check_memory_usage() {
			$this->memory['usage'] = function_exists('memory_get_peak_usage') ? round(memory_get_peak_usage(true) / 1024 / 1024, 2) : 0;
			if ( !empty($this->memory['usage'])) {
				$this->memory['percent'] = -1;
				if (!empty($this->memory['wpmb']) && ($this->memory['wpmb']!=0)) {
					$this->memory['percent'] = round($this->memory['usage'] /$this->memory["wpmb"] * 100, 0);
				}
				
				$this->memory['percentphp'] = -1;
				if (!empty($this->memory['phplimit']) && ($this->memory['phplimit']!=0)) {
					$this->memory['percentphp'] = round ($this->memory['usage'] / $this->memory["phplimit"] * 100, 0);
				}
				
				//If the bar is too small we move the text outside
                $this->memory['percent_pos'] = '';
                //In case we are in our limits take the admin color 
                $this->memory['color'] = '';
				if ($this->memory['percent'] > 80) $this->memory['color'] = 'background: #E66F00;';
				if ($this->memory['percent'] > 95) $this->memory['color'] = 'background: red;';
                if ($this->memory['percent'] < 10) $this->memory['percent_pos'] = 'margin-right: -30px; color: #444;';
				$this->memory['percentwidth'] = $this->memory['percent'];
				if ($this->memory['percent']>100) {
					$this->memory['percentwidth'] = 100;
				}
			}		
		}
		
		public function dashboard_output() {
			$this->check_memory_usage();
			?>
				<ul>	
					<li><strong><?php echo esc_html(__('PHP Version', 'wp-memory-usage')); ?>:</strong> <span><?php 
						echo esc_html(PHP_VERSION); ?>&nbsp;/&nbsp;<?php echo esc_html((PHP_INT_SIZE * 8) . __('Bit OS', 'wp-memory-usage')); 
						
						if (!is_null(ini_get('max_execution_time'))) {
							$max_execution_time = (int) ini_get('max_execution_time'). __('sec', 'wp-memory-usage');
							echo " / ".esc_html(__('Max execution time: ', 'wp-memory-usage')).' '.esc_html($max_execution_time);
						}
						
						
						?></span></li>
					<li><strong><?php echo esc_html(__('Memory limits', 'wp-memory-usage')); ?>:</strong> <span>
					<?php 
						if (!empty($this->memory["wpmb"])) {
							echo esc_html(__('Wordpress', 'wp-memory-usage').' '.$this->memory["wpmb"]. $this->memory["wpunity"])." / "; 
						}
						if ($this->memory["wpmaxmb"]!="" && ($this->memory["wpmb"]!=$this->memory["wpmaxmb"])) {
							echo esc_html(__('Wordpress-Admin', 'wp-memory-usage').' '.$this->memory["wpmaxmb"]. $this->memory["wpmaxunity"])." / "; 
						}
						if ($this->memory['phplimit']!="") {
							echo esc_html(__('PHP ', 'wp-memory-usage').' '.$this->memory['phplimit'].$this->memory['phplimitunity']);
						}
					?>
					</span></li>
					<li><strong><?php 
						$mem = $this->memory['usage'] ?? 0;
						echo esc_html(__('Current Memory usage', 'wp-memory-usage')); ?>:</strong> <span><?php echo esc_html($mem.__('MB', 'wp-memory-usage')); ?> </span><br>

				<?php	
					if ($this->memory['percent']>=0) {
					?>
				<div class="progressbar">
					<div style="border:1px solid #DDDDDD; background-color:#F9F9F9;	border-color: rgb(223, 223, 223); box-shadow: 0px 1px 0px rgb(255, 255, 255) inset; border-radius: 3px;">
                        <div class="button-primary" style="width: <?php echo esc_html($this->memory['percentwidth']); ?>%;<?php echo esc_html($this->memory['color']);?>padding: 0px;border-width:0px; color:#FFFFFF;text-align:right; border-color: rgb(223, 223, 223); box-shadow: 0px 1px 0px rgb(255, 255, 255) inset; border-radius: 3px; margin-top: -1px;">
							<div style="padding:2px;<?php echo esc_html($this->memory['percent_pos']); ?>"><?php echo esc_html($this->memory['percent']); ?>%</div>
						</div>
					</div>
				</div>
				<?php } 
				
				$settings_url = admin_url( 'options-general.php?page=wpmu-memory-alerts' );
				echo '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings & Monitor', 'wp-memory-usage' ) . '</a>';
				#echo $settings_link;
				
				// ── Neuester Digest ────────────────────────────────────────────
				if ( class_exists( 'WPMU_Threshold_Alerts' ) ) {
					$digest_files = glob( ABSPATH . '../logs/wpmu/digest_*.cgibak' ) ?: array();
					if ( ! empty( $digest_files ) ) {
						// neueste Datei
						usort( $digest_files, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );
						$latest = $digest_files[0];
						// phpcs:disable WordPress.WP.AlternativeFunctions
						$fh = @fopen( $latest, 'rb' );
						$digest_data = null;
						if ( $fh ) {
							flock( $fh, LOCK_SH );
							$digest_data = json_decode( fgets( $fh ), true );
							flock( $fh, LOCK_UN );
							fclose( $fh );
						}
						// phpcs:enable WordPress.WP.AlternativeFunctions						
						if ( is_array( $digest_data ) && isset( $digest_data['status'] ) ) {
							$dw = (int) ( $digest_data['status']['warn']     ?? 0 );
							$dd = (int) ( $digest_data['status']['danger']   ?? 0 );
							$dc = (int) ( $digest_data['status']['critical'] ?? 0 );
							$fn_label = basename( $latest );
							$raw_ts   = str_replace( array( 'digest_', '.cgibak' ), '', $fn_label );
							$dt_obj   = DateTime::createFromFormat( 'Y-m-d-H-i-s', $raw_ts );
							$ts_label = $dt_obj ? wp_date( get_option('date_format') . ', ' . get_option('time_format'), $dt_obj->getTimestamp() ) : $fn_label;
							echo '<hr style="margin:8px 0;">';
							echo '<strong>' . esc_html__( 'Latest Digest', 'wp-memory-usage' ) . ':</strong> <span style="font-size:11px;color:#888;">' . esc_html( $ts_label ) . '</span><br>';
							#$any_alert = ( $dw + $dd + $dc ) > 0;
							echo '<span style="display:inline-flex;gap:6px;margin-top:4px;flex-wrap:wrap;">';
							echo '<span style="background:' . esc_attr( $dw  > 0 ? '#f0ad4e' : '#e8e8e8' ) . ';color:' . esc_attr( $dw  > 0 ? '#3d2b00' : '#888' ) . ';padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;">⚠ ' . esc_html($dw)  . '</span>';
							echo '<span style="background:' . esc_attr( $dd  > 0 ? '#d9534f' : '#e8e8e8' ) . ';color:' . esc_attr( $dd  > 0 ? '#fff'    : '#888' ) . ';padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;">🔴 ' . esc_html($dd)  . '</span>';
							echo '<span style="background:' . esc_attr( $dc  > 0 ? '#8B0000' : '#e8e8e8' ) . ';color:' . esc_attr( $dc  > 0 ? '#fff'    : '#888' ) . ';padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;">🆘 ' . esc_html($dc)  . '</span>';
							echo '</span>';
							$digest_url = admin_url( 'options-general.php?page=wpmu-memory-alerts&tab=digest' );
							echo ' &nbsp;<a href="' . esc_url( $digest_url ) . '" style="font-size:11px;">' . esc_html__( '→ Digest', 'wp-memory-usage' ) . '</a>';
						}
					}
				}
				?>

						

					</li>
				</ul>
			<?php
		}
		 
		public function add_dashboard() {
			#$servertime = gmdate(__('d.m.Y, H:i:s', 'wp-memory-usage'));
			$servertime = wp_date( get_option('date_format') . ', ' . get_option('time_format') );			
			wp_add_dashboard_widget( 'wp_memory_dashboard', __('Memory Overview', 'wp-memory-usage')."<br>".__('Servertime', 'wp-memory-usage').": ".$servertime, array ($this, 'dashboard_output') );
		}
		
		private function formatWP_MEMORY_LIMIT($valin) { #WP_MEMORY_LIMIT and WP_MAX_MEMORY_LIMIT come with size and unity
			$valin = $valin ?? '';
			if (empty($valin)) {
				return $valin;
			}
			if (preg_match("/G$/i", $valin)) {
				# set in gigabyte 
				$valinTmp = preg_replace("/G$/i", "", $valin);
				$valinNo = 1024 * $valinTmp;
				$valin = $valinNo."M";
			}
			$size  = strtolower(substr($valin, -1));
	       	$number = (int) substr($valin, 0, -1);
			$ret = array();
			if ($size=="k") { $ret["mb"] = ($number/1024); $ret["unity"] = "kB"; }
			if ($size=="m") { $ret["mb"] = $number; $ret["unity"] = "MB"; }
			if ($size=="g") { $ret["mb"] = ($number*1024); $ret["unity"] = "GB"; }
			if ($size=="t") { $ret["mb"] = ($number*(1024*1024)); $ret["unity"] = "TB"; }
			if ($size=="p") { $ret["mb"] = ($number*(1024*1024*1024)); $ret["unity"] = "PB"; }
			return $ret;
		}

		private function get_ip_address() {
			$get_SERVER_ADDR = $this->getserverinput('SERVER_ADDR');
			#if (isset($_SERVER[ 'SERVER_ADDR' ]) && !empty($_SERVER[ 'SERVER_ADDR' ])) {
			if (!empty($get_SERVER_ADDR)) {
				$this->ipadr = $get_SERVER_ADDR;
				#$this->ipadr = $_SERVER[ 'SERVER_ADDR' ];
			}
			$get_LOCAL_ADDR = $this->getserverinput('LOCAL_ADDR');
			if (empty($this->ipadr) && !empty($get_LOCAL_ADDR)) {
			#if (empty($this->ipadr) && isset($_SERVER[ 'LOCAL_ADDR' ]) && !empty($_SERVER[ 'LOCAL_ADDR' ])) {
				$this->ipadr = $get_LOCAL_ADDR;
				#$this->ipadr = $_SERVER[ 'LOCAL_ADDR' ];
			}
			
			$get_SERVER_NAME = $this->getserverinput('SERVER_NAME');
			if (!empty($get_SERVER_NAME)) {
				$this->servername = $get_SERVER_NAME;
				$this->servernameout = " (".$get_SERVER_NAME.")";
			}
		}

		public function add_footer($content) {
			$this->check_memory_usage();
			$content .= ' | '. __( 'WP Memory Limit:', 'wp-memory-usage'). ' ' . esc_html($this->memory['usage']) . ' ' . __( 'of', 'wp-memory-usage') . ' ' . esc_html($this->memory["wpmb"]).esc_html($this->memory["wpunity"]). " (".esc_html($this->memory['percent'])."%)";
			$content .= ' | '. __( 'PHP Memory Limit:', 'wp-memory-usage'). ' ' . esc_html($this->memory['usage']) . ' ' . __( 'of', 'wp-memory-usage') . ' ' . esc_html($this->memory['phplimit']).esc_html($this->memory['phplimitunity']). " (".esc_html($this->memory['percentphp'])."%)";
			$content .= ' | '. __( 'IP-Address', 'wp-memory-usage') . " " . esc_html($this->servernameout). ': '.esc_html($this->ipadr);
			$content .= ' | '. __( 'PHP', 'wp-memory-usage') . ": " . esc_html(PHP_VERSION);
			return $content;
		}

	}

	// Start this plugin once all other plugins are fully loaded
if ( is_admin() ) {
    function WP_Memory_Usage_action_plugins_loaded( $array ) { 
		return new wp_memory_usage();
    }; 
    add_action( 'plugins_loaded', 'WP_Memory_Usage_action_plugins_loaded', 10, 1 ); 	
}


if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/threshold-alerts.php' ) ) { #load WPMU_Threshold_Alerts
	require_once plugin_dir_path( __FILE__ ) . 'includes/threshold-alerts.php';
	if ( class_exists( 'WPMU_Threshold_Alerts' ) ) {
		WPMU_Threshold_Alerts::init(25, 100, WPMU_LOG_PATH);
	}
}

// Add Settings link in Plugins list
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {  # insert link in plugin list
	$settings_url = admin_url( 'options-general.php?page=wpmu-memory-alerts' );
	$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings & Monitor', 'wp-memory-usage' ) . '</a>';
	$links[] = $settings_link;
	return $links;
} );