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
	// Les memes clefs sous le nouveau slug : elle est construite au runtime
	// a partir du slug, donc elle a change avec lui. Sans ces trois lignes,
	// une mise a jour depuis la version Ubac laisse des orphelines que plus
	// rien ne nomme.
	delete_option( 'ubac_welcome_seen_dahuconcept-file-cleanup' );
	delete_option( 'ubac_welcome_pending_ubac-cleanup' );
	delete_option( 'ubac_welcome_pending_dahuconcept-file-cleanup' );
	delete_option( 'ubac_welcome_tour_ubac-cleanup' );
	delete_option( 'ubac_welcome_tour_dahuconcept-file-cleanup' );

	wp_clear_scheduled_hook( 'ubac_cleanup_daily' );
	delete_transient( 'ubac_cleanup_running' );

	$settings = get_option( 'ubac_cleanup_settings', array() );
	$wipe     = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

	/*
	 * The settings go whatever the box says: they are configuration, like the
	 * welcome flags above, and a reinstall sets them again. They were behind
	 * the guard until 06/11/2026, so a site that left the box unticked kept
	 * this row for ever after deleting the plugin -- the same leak that
	 * ubac-shipping had with its own flag.
	 *
	 * Read into $wipe first, because the guard lives inside the row being
	 * deleted.
	 */
	delete_option( 'ubac_cleanup_settings' );

	if ( ! $wipe ) {
		return;
	}

	// The log is the data the box is about: which files this plugin deleted,
	// when, and under which rule. That is a record of what was done.
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
