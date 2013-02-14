<?php
/**
 * Plugin Name: Tribe Quick Profiler
 * Provides an extremely light and efficient way to track what's running slow and heavy in your WordPress install.
 *
 * Installation:
 *
 * 1. Place this file in your wp-content/mu-plugins folder (create the folder if it doesn't already exist.)
 * 2. Add the following definitions to your wp-config.php file with desired settings.
 * 3. View your site and watch the logs to see what's slow.
 *
 * // Sampling rate
 * // This determines the odds that this plugin will run.
 * // Set this very low if you are testing on a high traffic site.
 * // Or set it to '1' if you want every page load to be profiled.
 *
 * define('TRIBE_PROFILE_SAMPLE_RATE', '0.5');
 *
 *
 * // Time Threshold (milliseconds)
 * // Determine the minimum amount of time increase that should trigger logging in ms.
 * // If the time spent between filters exceeds this amount, it will be logged.
 *
 * define('TRIBE_PROFILE_TIME_THRESHOLD', '10');
 *
 *
 * // Memory Threshold (kilobytes)
 * // Determine the minimum amount of memory increase that should trigger logging in kB.
 * // If the memory consumed between filters exceeds this amount, it will be logged.
 *
 * define('TRIBE_PROFILE_MEMORY_THRESHOLD', '1024');
 *
 *
 * // Log File
 * // If this is not set, then logging will be directed to the standard php error log.
 * // If set to '1', then logging will be directed to wp-content/tribe_profile.log
 * // If set to a full path, then logging will be directed to the specified file.
 *
 * define('TRIBE_PROFILE_LOG_FILE', '1');
 *
 * TODO
 * * Perhaps we should dump the results on shutdown and build a profiling viewer.
 * * This plugin does not currently assess cumulative effects. The cumulative effect of running a single hook 10000 times could be useful.
 * * It might be useful to add a log cycling function so that we avoid massive log files.
 * * Sometimes memory actually drops substantially. Is it worth logging that too?
 */

function tribe_profile_time() {
	// settings
	$sampling_rate = (defined('TRIBE_PROFILE_SAMPLE_RATE')) ? TRIBE_PROFILE_SAMPLE_RATE : 1; // 0 = off, 1 = always.
	$time_threshold = (defined('TRIBE_PROFILE_TIME_THRESHOLD')) ? TRIBE_PROFILE_TIME_THRESHOLD : 10; // minimum time in milliseconds for reporting.
	$mem_threshold = (defined('TRIBE_PROFILE_MEMORY_THRESHOLD')) ? TRIBE_PROFILE_MEMORY_THRESHOLD : 1024; // minimum memory in kb for reporting.

	// use sampling rate to decide if we should profile and generate a unique key.
	if ( !$sampling_rate ) return; // sample rate = 0 so turn this off.

	static $key;
	if ( $key === false ) return; // key was previously determined to be a silent load.
	if ( empty( $key ) ) { // key has not been set... let's roll the dice.
		$rand = rand( 0, 999 );
		if ( $sampling_rate < 1 && $rand > $sampling_rate * 1000 ) { // silent load.
			$key = false;
			return;
		}
		$key = 'Load:'.str_pad($rand,4,"0",STR_PAD_LEFT); // profiled load.
	}

	// Calculate time diff
	global $timestart;
	static $previous_time;
	$timeend = microtime( true );
	$time = round( ($timeend - $timestart) * 1000 );
	$timediff = $time - $previous_time;

	// Calculate memory diff
	static $previous_memory;
	$mem_now = round( memory_get_usage(  )/1024 );
	$memdiff = $mem_now - $previous_memory;

	static $previous_filter;
	$filter = current_filter();

	if (empty($previous_filter)) {
		$url = $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
		tribe_profiler_error_log( array( $key, $url ) );
		tribe_profiler_error_log( array( $key, 'TIME', 'DELTA', 'MEM', 'DELTA', 'FILTER' ) );
	} elseif ( $diff > $time_threshold || $memdiff > $mem_threshold ) {
		tribe_profiler_error_log( array( $key, $time, $timediff, $previous_memory, $memdiff, $previous_filter ) );
	}
	$previous_filter = $filter;
	$previous_memory = $mem_now;
	$previous_time = $time;
}
add_action('all','tribe_profile_time');

/**
 * Log the messages to a custom error log. Defaults to wp-content/tribe_profile.log.
 * @param array $columns
 */
function tribe_profiler_error_log( $columns = array() ) {
	if ( defined('TRIBE_PROFILE_LOG_FILE') ) {
		static $log_file;
		if ( empty( $log_file ) ) {
			$log_file = ( TRIBE_PROFILE_LOG_FILE == '1' ) ? WP_CONTENT_DIR.'/tribe_profile.log' : TRIBE_PROFILE_LOG_FILE;
		}
		error_log( join( '	', $columns )."\n", 3, $log_file );
	} else {
		error_log( join( '	', $columns ) );
	}
}

/**
 * Use this function in wp-includes/plugin.php around line 403 where it says:
 * call_user_func_array($the_['function'], array_slice($args, 0, (int) $the_['accepted_args']));
 * Add this function on the same line directly after the semicolon (but don't leave it there for long).
 *
 * Example:
 * tribe_function_dump( 'init', $tag, $the_['function'] );
 *
 * @param $hook_to_dump the hook we're looking for. for example: 'init'.
 * @param $hook_now_processing the hook now processing. Use $tag.
 * @param $function_now_processing the function now processing. Use $the_['function'].
 */
function tribe_function_dump( $hook_to_dump, $hook_now_processing, $function_now_processing ) {
	global $timestart;

	static $last_function_dump;
	//static $last_function, $previous_time, $previous_memory;

	// use the raw vars instead of timer_stop() to avoid ln10 filters
	$timeend = microtime( true );
	$time = round( ($timeend - $timestart) * 1000 );
	$diff = (isset($last_function_dump['time'])) ? $time - $last_function_dump['time'] : $time;

	// Calculate memory diff
	$memory = round( memory_get_usage()/1024 );
	$memdiff = (isset($last_function_dump['memory'])) ? $memory - $last_function_dump['memory'] : $memory;

	if ( $last_function_dump ) {
		tribe_profiler_error_log( array( 'FUNCTION TEST', $diff . 'ms', $memdiff . 'kb', $last_function_dump['hook'], $last_function_dump['function'] ) );
		$last_function_dump = false;
	}
	if ( $hook_now_processing == $hook_to_dump ) {
		if ( is_array( $function_now_processing ) ) {
			if ( is_string( $function_now_processing[0] ) ) {
				$function_now_processing = join('::',$function_now_processing);
			} elseif ( is_object( $function_now_processing[0] ) ) {
				$function_now_processing = get_class($function_now_processing[0]).'::'.$function_now_processing[1];
			}
		}
		$last_function_dump = array(
			'hook' => $hook_now_processing,
			'function' => $function_now_processing,
			'time' => $time,
			'memory' => $memory,
		);
	}
}
?>