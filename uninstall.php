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
 * @package UbacCleanup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 *
 * @return void
 */
function ubac_cleanup_uninstall_site() {

	// The welcome screen's own three flags.
	//
	// They are interface state, not the shop's data, which is why they sit
	// BEFORE any “remove data on uninstall” guard: whatever a shop chose to
	// keep, a reinstall has to greet it again. `includes/ubac-welcome.php`
	// writes them with update_option(), so they outlive the plugin unless
	// they are deleted here — and the welcome screen and the guided tour
	// would then stay silent for good, with nothing on screen to explain why.
	delete_option( 'ubac_welcome_seen_ubac-cleanup' );
	delete_option( 'ubac_welcome_pending_ubac-cleanup' );
	delete_option( 'ubac_welcome_tour_ubac-cleanup' );

	wp_clear_scheduled_hook( 'ubac_cleanup_daily' );
	delete_transient( 'ubac_cleanup_running' );

	$settings = get_option( 'ubac_cleanup_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	delete_option( 'ubac_cleanup_settings' );
	delete_option( 'ubac_cleanup_log' );
}

if ( is_multisite() ) {
	$ubac_cleanup_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ubac_cleanup_sites as $ubac_cleanup_site ) {
		switch_to_blog( $ubac_cleanup_site );
		ubac_cleanup_uninstall_site();
		restore_current_blog();
	}

	unset( $ubac_cleanup_sites, $ubac_cleanup_site );
} else {
	ubac_cleanup_uninstall_site();
}
