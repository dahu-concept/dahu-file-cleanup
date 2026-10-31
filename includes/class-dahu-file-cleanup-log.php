<?php
/**
 * The log.
 *
 * One row per rule per run, newest first, in a single non-autoloaded option,
 * capped at a fixed number of rows. A plugin that deletes files without
 * leaving a record of what it deleted is asking to be blamed for every file
 * anyone cannot find, so the log is not optional and not a setting.
 *
 * Rows record counts, not filenames. A list of every deleted file would be
 * the most useful log imaginable and also an option that grows without bound
 * on a site that deletes thousands of files a night — and an option that
 * large makes every page load on the site slower. Sites that need per-file
 * accounting have `dahu_file_cleanup_delete_file`, which sees each one.
 *
 * @package DahuFileCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run history.
 */
class Dahu_File_Cleanup_Log {

	/**
	 * Option name.
	 */
	const OPTION = 'dahu_file_cleanup_log';

	/**
	 * Rows kept.
	 */
	const MAX_ROWS = 200;

	/**
	 * Add a row.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @return void
	 */
	public static function add( $entry ) {

		$rows = self::all();

		array_unshift(
			$rows,
			array(
				'time'   => time(),
				'mode'   => sanitize_key( (string) ( isset( $entry['mode'] ) ? $entry['mode'] : '' ) ),
				'rule'   => sanitize_key( (string) ( isset( $entry['rule'] ) ? $entry['rule'] : '' ) ),
				'label'  => sanitize_text_field( (string) ( isset( $entry['label'] ) ? $entry['label'] : '' ) ),
				'files'  => (int) ( isset( $entry['files'] ) ? $entry['files'] : 0 ),
				'bytes'  => (int) ( isset( $entry['bytes'] ) ? $entry['bytes'] : 0 ),
				'failed' => (int) ( isset( $entry['failed'] ) ? $entry['failed'] : 0 ),
				'capped' => ! empty( $entry['capped'] ),
			)
		);

		if ( count( $rows ) > self::MAX_ROWS ) {
			$rows = array_slice( $rows, 0, self::MAX_ROWS );
		}

		update_option( self::OPTION, $rows, false );
	}

	/**
	 * Every row, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {

		$rows = get_option( self::OPTION, array() );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Totals over the whole log.
	 *
	 * @return array{runs:int,files:int,bytes:int}
	 */
	public static function totals() {

		$totals = array(
			'runs'  => 0,
			'files' => 0,
			'bytes' => 0,
		);

		foreach ( self::all() as $row ) {
			++$totals['runs'];
			$totals['files'] += (int) ( isset( $row['files'] ) ? $row['files'] : 0 );
			$totals['bytes'] += (int) ( isset( $row['bytes'] ) ? $row['bytes'] : 0 );
		}

		return $totals;
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function clear() {

		update_option( self::OPTION, array(), false );
	}
}
