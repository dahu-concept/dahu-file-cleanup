<?php
/**
 * Plugin Name:       Ubac File Cleanup
 * Description:       A retention policy for the generated files that pile up on a WordPress server: named rules with their own folders, file extensions and maximum age, a dry run that lists every file before anything is deleted, a daily scheduled run, a per-run ceiling and a log.
 * Version:           1.1.4
 * Author:            Hugo Vial-Jaime
 * Author URI:        https://dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ubac-cleanup
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 *
 * @package UbacCleanup
 */

defined( 'ABSPATH' ) || exit;

define( 'UBAC_CLEANUP_VERSION', '1.1.4' );
define( 'UBAC_CLEANUP_FILE', __FILE__ );
define( 'UBAC_CLEANUP_PATH', plugin_dir_path( __FILE__ ) );
define( 'UBAC_CLEANUP_URL', plugin_dir_url( __FILE__ ) );
define( 'UBAC_CLEANUP_PAGE', 'ubac-cleanup' );
define( 'UBAC_CLEANUP_CRON', 'ubac_cleanup_daily' );

/* -------------------------------------------------------------------------
 * Translations
 *
 * There is deliberately no load_plugin_textdomain() call here. Since
 * WordPress 4.6 the translations of a plugin hosted on WordPress.org are
 * loaded on demand, and the directory's review asks that the call be removed
 * rather than kept: this plugin requires 6.0, so there is no older WordPress
 * to cover. Translations come from translate.wordpress.org.
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------- */

require_once UBAC_CLEANUP_PATH . 'includes/class-ubac-cleanup-settings.php';
require_once UBAC_CLEANUP_PATH . 'includes/class-ubac-cleanup-rules.php';
require_once UBAC_CLEANUP_PATH . 'includes/class-ubac-cleanup-paths.php';
require_once UBAC_CLEANUP_PATH . 'includes/class-ubac-cleanup-scanner.php';
require_once UBAC_CLEANUP_PATH . 'includes/class-ubac-cleanup-runner.php';
require_once UBAC_CLEANUP_PATH . 'includes/class-ubac-cleanup-log.php';

if ( is_admin() ) {
	require_once UBAC_CLEANUP_PATH . 'includes/class-ubac-cleanup-admin.php';
	Ubac_Cleanup_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once UBAC_CLEANUP_PATH . 'includes/class-ubac-cleanup-cli.php';
	Ubac_Cleanup_CLI::init();
}

/* -------------------------------------------------------------------------
 * The scheduled run
 *
 * The event is daily and always scheduled; what it does depends entirely on
 * which rules are enabled, and a fresh install has no rules at all. An event
 * that fires over an empty rule list costs one option read.
 * ---------------------------------------------------------------------- */

add_action(
	UBAC_CLEANUP_CRON,
	static function () {
		Ubac_Cleanup_Runner::run( 'scheduled' );
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
function ubac_cleanup_schedule() {

	if ( wp_next_scheduled( UBAC_CLEANUP_CRON ) ) {
		return;
	}

	// 03:00 site time, tomorrow: a run that walks large folders belongs
	// outside the hours when the site is being used.
	$now   = (int) current_time( 'timestamp' );
	$at    = (int) strtotime( 'tomorrow 03:00', $now );
	$delay = max( HOUR_IN_SECONDS, $at - $now );

	wp_schedule_event( time() + $delay, 'daily', UBAC_CLEANUP_CRON );
}

add_action( 'admin_init', 'ubac_cleanup_schedule' );

register_activation_hook(
	__FILE__,
	static function () {
		ubac_cleanup_schedule();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( UBAC_CLEANUP_CRON );
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
			esc_url( admin_url( 'tools.php?page=' . UBAC_CLEANUP_PAGE ) ),
			esc_html__( 'Rules', 'ubac-cleanup' )
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
			esc_html__( 'Hugo Vial-Jaime — Dahu-Concept', 'ubac-cleanup' )
		);

		return $links;
	},
	10,
	2
);

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once UBAC_CLEANUP_PATH . 'includes/ubac-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Ubac_Welcome::mark_activated( 'ubac-cleanup' );
	}
);

add_action(
	'init',
	static function () {
		Ubac_Welcome::register(
			array(
				'slug'       => 'ubac-cleanup',
				'name'       => __( 'File cleanup', 'ubac-cleanup' ),
				'tagline'    => __( 'Finds uploaded files no longer attached to anything.', 'ubac-cleanup' ),
				'file'       => UBAC_CLEANUP_FILE,
				'version'    => UBAC_CLEANUP_VERSION,
				'textdomain' => 'ubac-cleanup',
				'mark'       => array(
					'view'  => '0 0 112 112',
					'paths' => array(
						'M6 30 Q6 22 14 22 L44 22 L54 34 L92 34 Q100 34 100 42 L100 92 Q100 100 92 100 L14 100 Q6 100 6 92 Z',
						'M30 56 L76 56',
						'M70.0 22.0 a18.0 18.0 0 1 0 36.0 0 a18.0 18.0 0 1 0 -36.0 0',
						'M88 12 L88 22 L96 27',
					),
				),
				/*
				 * The welcome screen's words come from here: the shared file is
				 * copied byte for byte across the whole family, so it can carry
				 * no text domain of its own.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'ubac-cleanup' ),
					'done'   => __( 'Done', 'ubac-cleanup' ),
					'skip'   => __( 'Skip', 'ubac-cleanup' ),
					'of'     => __( 'of', 'ubac-cleanup' ),
					'denied' => __( 'You are not allowed to view this page.', 'ubac-cleanup' ),
				),
				'points'     => array(
				array(
					'title' => __( 'It looks first', 'ubac-cleanup' ),
					'text'  => __( 'Nothing is removed until you have seen the list.', 'ubac-cleanup' ),
				),
				array(
					'title' => __( 'It explains', 'ubac-cleanup' ),
					'text'  => __( 'Each file says why it is thought to be unused.', 'ubac-cleanup' ),
				),
				array(
					'title' => __( 'You choose', 'ubac-cleanup' ),
					'text'  => __( 'Deletion is yours, file by file.', 'ubac-cleanup' ),
				),
				),

				/*
				 * The one-time tour.
				 *
				 * Every selector below points at something this screen always
				 * renders, with no rule saved and no run behind it. The blank
				 * "New rule" panel, the limits field and the dry-run button are
				 * printed unconditionally; the rule list, the "Run the enabled
				 * rules now" button, the dry-run result and the log table are
				 * not, and are deliberately left out.
				 */
				'tour_screen' => 'tools_page_ubac-cleanup',
				'tour'        => array(
					array(
						'selector' => '#ubac-cleanup-__new-paths',
						'title'    => __( 'Name the folders', 'ubac-cleanup' ),
						'text'     => __( 'One per line. Only the folders you list are ever looked at.', 'ubac-cleanup' ),
					),
					array(
						'selector' => '#ubac-cleanup-ceiling',
						'title'    => __( 'A safety ceiling', 'ubac-cleanup' ),
						'text'     => __( 'A run stops after this many files, and says so in the log.', 'ubac-cleanup' ),
					),
					array(
						'selector' => '.ubac-cleanup__actions',
						'title'    => __( 'Look before deleting', 'ubac-cleanup' ),
						'text'     => __( 'The dry run lists what a rule would remove, and removes nothing.', 'ubac-cleanup' ),
					),
				),
			)
		);
	}
);
