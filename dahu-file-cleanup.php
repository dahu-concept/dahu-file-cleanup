<?php
/**
 * Plugin Name:       Dahu File Cleanup
 * Plugin URI:        https://dahu-concept.fr
 * Description:       A retention policy for the generated files that pile up on a WordPress server: named rules with their own folders, file extensions and maximum age, a dry run that lists every file before anything is deleted, a daily scheduled run, a per-run ceiling and a log.
 * Version:           1.0.0
 * Author:            Hugo Vial-Jaime
 * Author URI:        mailto:contact@dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       dahu-file-cleanup
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package DahuFileCleanup
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_FILE_CLEANUP_VERSION', '1.0.0' );
define( 'DAHU_FILE_CLEANUP_FILE', __FILE__ );
define( 'DAHU_FILE_CLEANUP_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_FILE_CLEANUP_URL', plugin_dir_url( __FILE__ ) );
define( 'DAHU_FILE_CLEANUP_PAGE', 'dahu-file-cleanup' );
define( 'DAHU_FILE_CLEANUP_CRON', 'dahu_file_cleanup_daily' );

/* -------------------------------------------------------------------------
 * Translations
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'dahu-file-cleanup',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

/* -------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------- */

require_once DAHU_FILE_CLEANUP_PATH . 'includes/class-dahu-file-cleanup-settings.php';
require_once DAHU_FILE_CLEANUP_PATH . 'includes/class-dahu-file-cleanup-rules.php';
require_once DAHU_FILE_CLEANUP_PATH . 'includes/class-dahu-file-cleanup-paths.php';
require_once DAHU_FILE_CLEANUP_PATH . 'includes/class-dahu-file-cleanup-scanner.php';
require_once DAHU_FILE_CLEANUP_PATH . 'includes/class-dahu-file-cleanup-runner.php';
require_once DAHU_FILE_CLEANUP_PATH . 'includes/class-dahu-file-cleanup-log.php';

if ( is_admin() ) {
	require_once DAHU_FILE_CLEANUP_PATH . 'includes/class-dahu-file-cleanup-admin.php';
	Dahu_File_Cleanup_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once DAHU_FILE_CLEANUP_PATH . 'includes/class-dahu-file-cleanup-cli.php';
	Dahu_File_Cleanup_CLI::init();
}

/* -------------------------------------------------------------------------
 * The scheduled run
 *
 * The event is daily and always scheduled; what it does depends entirely on
 * which rules are enabled, and a fresh install has no rules at all. An event
 * that fires over an empty rule list costs one option read.
 * ---------------------------------------------------------------------- */

add_action(
	DAHU_FILE_CLEANUP_CRON,
	static function () {
		Dahu_File_Cleanup_Runner::run( 'scheduled' );
	}
);

/**
 * Schedule the daily event if it is not already scheduled.
 *
 * Called on activation and, as a safety net, on admin_init: an optimisation
 * plugin that clears the cron array takes this event with it, and a retention
 * policy that silently stopped running is worse than one that never ran. The
 * wp_next_scheduled() test is what keeps this from writing to the cron option
 * on every admin page load.
 *
 * @return void
 */
function dahu_file_cleanup_schedule() {

	if ( wp_next_scheduled( DAHU_FILE_CLEANUP_CRON ) ) {
		return;
	}

	// 03:00 site time, tomorrow: a run that walks large folders belongs
	// outside the hours when the site is being used.
	$now   = (int) current_time( 'timestamp' );
	$at    = (int) strtotime( 'tomorrow 03:00', $now );
	$delay = max( HOUR_IN_SECONDS, $at - $now );

	wp_schedule_event( time() + $delay, 'daily', DAHU_FILE_CLEANUP_CRON );
}

add_action( 'admin_init', 'dahu_file_cleanup_schedule' );

register_activation_hook(
	__FILE__,
	static function () {
		dahu_file_cleanup_schedule();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( DAHU_FILE_CLEANUP_CRON );
	}
);

/* -------------------------------------------------------------------------
 * Plugins screen
 * ---------------------------------------------------------------------- */

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {

		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'tools.php?page=' . DAHU_FILE_CLEANUP_PAGE ) ),
			esc_html__( 'Rules', 'dahu-file-cleanup' )
		);

		array_unshift( $links, $settings );

		return $links;
	}
);

add_filter(
	'plugin_row_meta',
	static function ( $links, $file ) {

		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( 'https://dahu-concept.fr' ),
			esc_html__( 'Hugo Vial-Jaime — Dahu-Concept', 'dahu-file-cleanup' )
		);

		return $links;
	},
	10,
	2
);
