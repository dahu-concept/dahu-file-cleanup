<?php
/**
 * Uninstall routine.
 *
 * No file on disk is ever deleted here. That is worth saying plainly, because
 * this is a plugin whose whole job is deleting files, and an uninstall routine
 * that took a last pass over the rules would be the single most destructive
 * thing in it — running unattended, at the moment somebody decided they no
 * longer wanted the plugin, over rules nobody re-read.
 *
 * The rules and the log go only if that was asked for on the settings screen,
 * and the setting is off by default: uninstalling is often a step in
 * reinstalling, and the log is the only record of what was deleted.
 *
 * @package DahuFileCleanup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 *
 * @return void
 */
function dahu_file_cleanup_uninstall_site() {

	wp_clear_scheduled_hook( 'dahu_file_cleanup_daily' );
	delete_transient( 'dahu_file_cleanup_running' );

	$settings = get_option( 'dahu_file_cleanup_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	delete_option( 'dahu_file_cleanup_settings' );
	delete_option( 'dahu_file_cleanup_log' );
}

if ( is_multisite() ) {
	$dahu_file_cleanup_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $dahu_file_cleanup_sites as $dahu_file_cleanup_site ) {
		switch_to_blog( $dahu_file_cleanup_site );
		dahu_file_cleanup_uninstall_site();
		restore_current_blog();
	}

	unset( $dahu_file_cleanup_sites, $dahu_file_cleanup_site );
} else {
	dahu_file_cleanup_uninstall_site();
}
