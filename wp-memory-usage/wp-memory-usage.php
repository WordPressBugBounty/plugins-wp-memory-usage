<?php
/*
Plugin Name: WP-Memory-Usage
Plugin URI: https://www.json-content-importer.com
Description: Show up memory limits, current memory usage, IP-Address, PHP-Version in the dashboard and admin footer
Author: Bernhard Kux
Version: 1.2.10
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

if ( is_admin() ) {	

	define( 'WPMEMORYUSAGEVERSION', '1.2.10' ); // current version number
	
	function wp_memory_usage_i18n_init() {
		$pd = dirname(
			plugin_basename(__FILE__)
		).'/languages/';
		
		$id = "wp-memory-usage";
		$loaderrorlevel = load_plugin_textdomain($id, false, $pd);
	}
	add_action('plugins_loaded', 'wp_memory_usage_i18n_init');

	class wp_memory_usage {
		private $ipadr = "";
		private $servername = "";
		private $memory = array();	
		
		public function __construct() {
			$this->get_ip_adress();
            add_action( 'init', array (&$this, 'check_limit') );
			add_action( 'wp_dashboard_setup', array (&$this, 'add_dashboard') );
			if ( is_multisite() ) { 
				add_action( 'wp_network_dashboard_setup', array (&$this, 'add_dashboard') );
			}
			add_filter( 'admin_footer_text', array (&$this, 'add_footer') );
		}
        
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
		
		private function check_memory_usage() {
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
				
				//If the bar is tp small we move the text outside
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

				<?PHP	
					if ($this->memory['percent']>=0) {
					?>
				<div class="progressbar">
					<div style="border:1px solid #DDDDDD; background-color:#F9F9F9;	border-color: rgb(223, 223, 223); box-shadow: 0px 1px 0px rgb(255, 255, 255) inset; border-radius: 3px;">
                        <div class="button-primary" style="width: <?php echo esc_html($this->memory['percentwidth']); ?>%;<?php echo esc_html($this->memory['color']);?>padding: 0px;border-width:0px; color:#FFFFFF;text-align:right; border-color: rgb(223, 223, 223); box-shadow: 0px 1px 0px rgb(255, 255, 255) inset; border-radius: 3px; margin-top: -1px;">
							<div style="padding:2px;<?php echo esc_html($this->memory['percent_pos']); ?>"><?php echo esc_html($this->memory['percent']); ?>%</div>
						</div>
					</div>
				</div>
				<?php } ?>
						
<!-- START measure memory 				-->
						<hr>
						<?PHP
							echo '<strong>'.esc_html(__('Multiple Memory Measurement: Reload page and measure memory', 'wp-memory-usage')).'</strong><br>';
							echo esc_html(__('You might switch off some plugins to check, which plugin consumes significant memory.', 'wp-memory-usage'))."<br>";
							$cst = $_GET["c"] ?? '';
							$cst = htmlspecialchars($cst);
							$ac = $_GET["ac"] ?? '';
							$ac = htmlspecialchars($ac);

							$wpmemoryusage_settings_str = get_option('wpmemoryusage_settings') ?? '';
							$wpmemoryusage_settings = json_decode($wpmemoryusage_settings_str, TRUE);

							$nonceCheck = wp_verify_nonce( ($_REQUEST['memusage'] ?? ''), "memusage" );
							if ($nonceCheck || is_null($wpmemoryusage_settings)) {
								$nomeas = $_GET["nomeas"] ?? '';
								$validatedValue_nomeas = filter_input(INPUT_GET, 'nomeas', FILTER_VALIDATE_INT);
								if ($validatedValue_nomeas && $nomeas>0) { 
									$wpmemoryusage_settings["nomeas"] = $nomeas; 
								} else if ($cst!="st" && $ac!="stop") {
									$wpmemoryusage_settings["nomeas"] = 2; 
								}
								if ($wpmemoryusage_settings["nomeas"]>2000) {
									$wpmemoryusage_settings["nomeas"] = 2000;
								}

								$secrel = $_GET["secrel"] ?? '';
								$validatedValue_secrel = filter_input(INPUT_GET, 'secrel', FILTER_VALIDATE_INT);
								if ($validatedValue_secrel && $secrel>0) { 
									$wpmemoryusage_settings["secrel"] = $secrel; 
								} else if ($cst!="st" && $ac!="stop") {
									$wpmemoryusage_settings["secrel"] = 1000; 
								}
								$memOptSave = update_option('wpmemoryusage_settings', json_encode($wpmemoryusage_settings));
							}

							echo "<form action=".esc_url(admin_url()).">";
							wp_nonce_field( "memusage", "memusage" );
							echo esc_html(__('Number of measuring points', 'wp-memory-usage')).': <input type=number name=nomeas value="'.esc_attr($wpmemoryusage_settings["nomeas"]).'"><br>';
							echo esc_html(__('Milliseconds between page reloads', 'wp-memory-usage')).': <input type=number name=secrel value="'.esc_attr($wpmemoryusage_settings["secrel"]).'"><br>';
							echo '<input type=submit value="'.esc_html(__('Store settings', 'wp-memory-usage')).'">';
							echo "</form><hr>";

							# for test only
							#$this->memory["wpmb"]= 30;

							$z = 1;
							$validatedValue_z = filter_input(INPUT_GET, 'meme', FILTER_VALIDATE_INT);
							if ($validatedValue_z && $validatedValue_z>0) { 
									$z = $_GET["meme"];
							}
							
							if ($z==1) {
								$memOptSave = update_option('wpmemoryusage_emopt', array());
							}
							$memOpt = get_option('wpmemoryusage_emopt', array());
							$anzpoints = count($memOpt);
							$now = time();
							$summb = 0;
							$anz = 0;

							$ha = array();
							if ($anzpoints>0) {
								krsort($memOpt);
								foreach ($memOpt as $key => $value) {
									$value = intval($value + 0.5);
									$ha[$value] = $ha[$value] ?? 0;
									$ha[$value]++;
									$summb += $value;
									$anz++;
								}
								$av = $summb/$anz;
							}
					
							$colgreen = "#d9ead3";
							$colred = "#f4cccc";

							if ($cst=="st" && $wpmemoryusage_settings["nomeas"]==$anz) {
								echo '<a href='.esc_url(admin_url()).'?c=st>'.esc_html(__('Restart Measurement', 'wp-memory-usage')).'</a><hr>';
							} else if ($cst=="st" && $wpmemoryusage_settings["nomeas"]!=$anz) {
								echo '<a href='.esc_url(admin_url()).'?ac=stop>'.esc_html(__('Stop Measurement', 'wp-memory-usage')).'</a><hr>';
							} else {
								echo '<a href='.esc_url(admin_url()).'?c=st>'.esc_html(__('Start Measurement', 'wp-memory-usage')).'</a><hr>';
							}
					
						$error_WP_MEMORY_LIMIT_not_set = esc_html(__('WP_MEMORY_LIMIT not set', 'wp-memory-usage'));
						$error_WP_MAX_MEMORY_LIMIT_not_set = esc_html(__('WP_MAX_MEMORY_LIMIT not set', 'wp-memory-usage'));  
						
						if ($anz>0) {
							echo "<table border=1 width=100%>";
							$mp = round($anz/$wpmemoryusage_settings["nomeas"]*1000)/10;
							if ($mp<100) {	$colli = $colred;	} else { $colli = $colgreen; }
							echo "<tr bgcolor=".esc_attr($colli)."><td colspan=3>";
							echo esc_html(__('Number of measuring points', 'wp-memory-usage'))." ";
							if ($wpmemoryusage_settings["nomeas"]>0) {
								echo esc_html($mp.__('% (', 'wp-memory-usage'));
							}
							echo esc_html($anz);
							echo " ".esc_html(__('of', 'wp-memory-usage'))." ";
							echo esc_html($wpmemoryusage_settings["nomeas"]);
							if ($wpmemoryusage_settings["nomeas"]>0) {
								echo esc_html(__(')', 'wp-memory-usage'));
							}
							echo "</td></tr>";

							echo "<tr bgcolor=#cfe2f3><td>";
							echo esc_html(__('Used MB', 'wp-memory-usage'));
							echo "</td><td>";
							if (empty($this->memory["wpmb"])) {
								echo esc_html($error_WP_MEMORY_LIMIT_not_set);
							} else {
								/* translators: 1: Memory Limit in MB */
								$translatable_string = __('MB (max. %s)', 'wp-memory-usage');
								$formatted_string = sprintf($translatable_string, esc_html($this->memory["wpmb"]. $this->memory["wpunity"]));
								echo esc_html($formatted_string);
							}
							echo "</td><td>";
							echo esc_html(__('%MB', 'wp-memory-usage'));
							echo "</td></tr>";
						
							$val = round($summb/$anz); 
							$valproz = -1;
							if ($this->memory["wpmb"]>0) {
								$valproz = round($val/$this->memory["wpmb"]*1000)/10; 
							} 
							if ($valproz>0 && $valproz<100) {	$colli = $colgreen;	} else { $colli = $colred; }
							echo "<tr bgcolor=".esc_attr($colli)."><td>";
							echo esc_html(__('Average MB', 'wp-memory-usage'));
							echo "</td><td>";
							echo esc_html($val);
							echo "</td><td>";
							if ($valproz>0) {
								echo esc_html($valproz.__('%', 'wp-memory-usage'));
							} else {
								echo esc_html($error_WP_MEMORY_LIMIT_not_set);
							}
							echo "</td></tr>";
							
							$val = min($memOpt); 
							$valproz = -1;
							if ($this->memory["wpmb"]>0) {
								$valproz = round($val/$this->memory["wpmb"]*1000)/10; 
							}
							if ($valproz>0 && $valproz<100) {	$colli = $colgreen;	} else { $colli = $colred; }
							echo "<tr bgcolor=".esc_attr($colli)."><td>";
							echo esc_html(__('Min MB', 'wp-memory-usage'));
							echo "</td><td>";
							echo esc_html($val);
							echo "</td><td>";
							if ($valproz>0) {
								echo esc_html($valproz.__('%', 'wp-memory-usage'));
							} else {
								echo esc_html($error_WP_MEMORY_LIMIT_not_set);
							}
							echo "</td></tr>";

							$val = max($memOpt); 
							$valproz = -1;
							if ($this->memory["wpmb"]>0) {
								$valproz = round($val/$this->memory["wpmb"]*1000)/10; 
							}
							if ($valproz>0 && $valproz<100) {	$colli = $colgreen;	} else { $colli = $colred; }
							if ($valproz<100) {	$colli = $colgreen;	} else { $colli = $colred; }
							echo "<tr bgcolor=".esc_attr($colli)."><td>";
							echo esc_html(__('Max MB', 'wp-memory-usage'));
							echo "</td><td>";
							echo esc_html($val);
							echo "</td><td>";
							if ($valproz>0) {
								echo esc_html($valproz.__('%', 'wp-memory-usage'));
							} else {
								echo esc_html($error_WP_MEMORY_LIMIT_not_set);
							}
							echo "</td></tr>";

							if (count($ha)>0) {
								krsort($ha);
								echo "<tr bgcolor=#cfe2f3><td>";
								echo esc_html(__('MB', 'wp-memory-usage'));
								echo "</td><td colspan=2>";
								echo esc_html(__('Occurrence', 'wp-memory-usage'));
								echo "</td></tr>";
								foreach ($ha as $key => $value) {
									$valproz = -1;
									if ($this->memory["wpmb"]>0) {
										$valproz = round($key/$this->memory["wpmb"]*1000)/10; 
									}
									if ($valproz>0 && $valproz<100) {	$colli = $colgreen;	} else { $colli = $colred; }
									echo "<tr bgcolor=".esc_attr($colli)."><td>";
									echo esc_html($key);
									echo "</td><td colspan=2>";
									echo esc_html($value);
									echo "</td></tr>";
								}
							}
							echo "</table>";
						}
						if ($mem>0) {
							$memOpt[$now] = $mem;
							krsort($memOpt);
							$memOpt = array_slice($memOpt, 0, $wpmemoryusage_settings["nomeas"], TRUE);
							$memOptSave = update_option('wpmemoryusage_emopt', $memOpt);
						}

							if ($anzpoints < $wpmemoryusage_settings["nomeas"] && $cst=="st") {
								$next = 1+$z;
								echo "<script>";
								echo 'setTimeout(function() {window.location.assign("'.esc_url(admin_url()).'?c=st&meme='.esc_html($next).'");}, '.esc_html($wpmemoryusage_settings["secrel"]).');';
								echo "</script>";
								#echo '<a href="javascript:newDoc()">jump '.$next.'</a><hr>';
							}

					?>
					</span>
<!-- END measure memory 				-->
					</li>
				</ul>
			<?php
		}
		 
		public function add_dashboard() {
			$servertime = gmdate(__('d.m.Y, H:i:s', 'wp-memory-usage'));
			wp_add_dashboard_widget( 'wp_memory_dashboard', __('Memory Overview', 'wp-memory-usage')."<br>".__('Servertime', 'wp-memory-usage').": ".$servertime, array (&$this, 'dashboard_output') );
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
			$ret = Array();
			if ($size=="k") { $ret["mb"] = ($number/1024); $ret["unity"] = "kB"; }
			if ($size=="m") { $ret["mb"] = $number; $ret["unity"] = "MB"; }
			if ($size=="g") { $ret["mb"] = ($number*1024); $ret["unity"] = "GB"; }
			if ($size=="t") { $ret["mb"] = ($number*(1024*1024)); $ret["unity"] = "TB"; }
			if ($size=="p") { $ret["mb"] = ($number*(1024*1024*1024)); $ret["unity"] = "PB"; }
			return $ret;
		}

		private function get_ip_adress() {
			if (isset($_SERVER[ 'SERVER_ADDR' ]) && !empty($_SERVER[ 'SERVER_ADDR' ])) {
				$this->ipadr = $_SERVER[ 'SERVER_ADDR' ];
			}
			if (empty($this->ipadr) && isset($_SERVER[ 'LOCAL_ADDR' ]) && !empty($_SERVER[ 'LOCAL_ADDR' ])) {
				$this->ipadr = $_SERVER[ 'LOCAL_ADDR' ];
			}
			
			if (!empty($_SERVER['SERVER_NAME'])) {
				$this->servername = " (".$_SERVER['SERVER_NAME'].")";
			}
		}

		public function add_footer($content) {
			$this->check_memory_usage();
			$content .= ' | '. __( 'WP Memory Limit:', 'wp-memory-usage'). ' ' . $this->memory['usage'] . ' ' . __( 'of', 'wp-memory-usage') . ' ' . $this->memory["wpmb"].$this->memory["wpunity"]. " (".$this->memory['percent']."%)";
			$content .= ' | '. __( 'PHP Memory Limit:', 'wp-memory-usage'). ' ' . $this->memory['usage'] . ' ' . __( 'of', 'wp-memory-usage') . ' ' . $this->memory['phplimit'].$this->memory['phplimitunity']. " (".$this->memory['percentphp']."%)";
			$content .= ' | '. __( 'IP-Address', 'wp-memory-usage') . " " . $this->servername. ': '.$this->ipadr;
			$content .= ' | '. __( 'PHP', 'wp-memory-usage') . ": " . PHP_VERSION;
			return $content;
		}

	}

	// Start this plugin once all other plugins are fully loaded
    function WP_Memory_Usage_action_plugins_loaded( $array ) { 
		return new wp_memory_usage();
    }; 
    add_action( 'plugins_loaded', 'WP_Memory_Usage_action_plugins_loaded', 10, 1 ); 	
}