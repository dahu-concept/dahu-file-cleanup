<?php
/**
 * WP-CLI commands.
 *
 * These exist for one practical reason. A great many hosts run WordPress with
 * DISABLE_WP_CRON and a system cron that calls wp-cron.php — and a great many
 * others run neither, in which case a "daily" event fires whenever somebody
 * next visits the site, which for a quiet shop can be days. A retention policy
 * that only runs when a visitor happens by is not a policy.
 *
 * `wp file-cleanup run` makes the schedule somebody else's job, where it
 * belongs. `--dry-run` is the default: a command that deletes files on a
 * server by default, when its name is a verb somebody might type to see what
 * it does, is a bad command.
 *
 * @package DahuFileCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the CLI commands.
 */
class Dahu_File_Cleanup_CLI {

	/**
	 * Hook the command up.
	 *
	 * @return void
	 */
	public static function init() {

		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'file-cleanup', __CLASS__ );
	}

	/**
	 * List the rules and whether each one would run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp file-cleanup rules
	 *
	 * @return void
	 */
	public function rules() {

		$rules = Dahu_File_Cleanup_Settings::rules();

		if ( ! $rules ) {
			WP_CLI::log( 'No rules. Nothing would be deleted.' );

			return;
		}

		$table = array();

		foreach ( $rules as $key => $rule ) {
			$blockers = Dahu_File_Cleanup_Rules::blockers( $rule );

			$table[] = array(
				'key'        => $key,
				'label'      => $rule['label'],
				'folders'    => count( Dahu_File_Cleanup_Rules::path_lines( $rule ) ),
				'extensions' => implode( ' ', $rule['extensions'] ),
				'days'       => '' === $rule['days'] ? '-' : $rule['days'],
				'recursive'  => $rule['recursive'] ? 'yes' : 'no',
				'state'      => self::state_of( $rule, $blockers ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $table, array( 'key', 'label', 'folders', 'extensions', 'days', 'recursive', 'state' ) );
	}

	/**
	 * Show what a run would delete, without deleting anything.
	 *
	 * ## OPTIONS
	 *
	 * [--rule=<key>]
	 * : Only this rule. Defaults to every rule, enabled or not.
	 *
	 * [--files]
	 * : Also list the files, up to 200 per rule.
	 *
	 * ## EXAMPLES
	 *
	 *     wp file-cleanup preview
	 *     wp file-cleanup preview --rule=staging-backups-and-logs --files
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function preview( $args, $assoc_args ) {

		$rules = $this->chosen_rules( $assoc_args );

		if ( ! $rules ) {
			WP_CLI::warning( 'No matching rule.' );

			return;
		}

		$show_files = isset( $assoc_args['files'] );

		foreach ( $rules as $key => $rule ) {
			$preview = Dahu_File_Cleanup_Scanner::preview( $rule );

			WP_CLI::log( '' );
			WP_CLI::log( sprintf( '%s  [%s]  %s', $rule['label'], $key, $rule['enabled'] ? 'enabled' : 'disabled' ) );
			WP_CLI::log( str_repeat( '-', 60 ) );

			foreach ( Dahu_File_Cleanup_Rules::blockers( $rule ) as $blocker ) {
				WP_CLI::log( '  cannot run: ' . $blocker );
			}

			foreach ( $preview['diagnostics'] as $diagnostic ) {
				if ( ! $diagnostic['ok'] ) {
					WP_CLI::log( sprintf( '  %s  REFUSED: %s', $diagnostic['line'], $diagnostic['reason'] ) );
					continue;
				}

				WP_CLI::log(
					sprintf(
						'  %s  files %d, matching extension %d, selected %d%s',
						$diagnostic['resolved'],
						$diagnostic['files'],
						$diagnostic['matching'],
						$diagnostic['selected'],
						$diagnostic['symlinks'] ? sprintf( ', %d symbolic link(s) skipped', $diagnostic['symlinks'] ) : ''
					)
				);
			}

			WP_CLI::log( sprintf( '  => %d file(s), %s', $preview['count'], size_format( $preview['bytes'] ) ) );

			if ( Dahu_File_Cleanup_Scanner::looks_like_reset_timestamps( $preview['diagnostics'] ) ) {
				WP_CLI::warning( 'Many candidate files, none old enough. Modification times may have been reset by a clone or a restore.' );
			}

			if ( $show_files ) {
				foreach ( $preview['files'] as $file ) {
					WP_CLI::log( sprintf( '     %s  %s  %s', Dahu_File_Cleanup_Paths::shorten( $file['path'] ), size_format( $file['size'] ), gmdate( 'Y-m-d H:i', $file['mtime'] ) ) );
				}

				if ( $preview['truncated'] ) {
					WP_CLI::log( sprintf( '     ... and %d more', $preview['count'] - count( $preview['files'] ) ) );
				}
			}
		}
	}

	/**
	 * Run the enabled rules.
	 *
	 * ## OPTIONS
	 *
	 * [--rule=<key>]
	 * : Only this rule. It must still be enabled and complete.
	 *
	 * [--execute]
	 * : Actually delete. Without it the command previews and deletes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp file-cleanup run
	 *     wp file-cleanup run --execute
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {

		if ( ! isset( $assoc_args['execute'] ) ) {
			WP_CLI::log( 'Dry run. Nothing will be deleted. Add --execute to delete.' );

			$rules  = Dahu_File_Cleanup_Settings::runnable_rules();
			$chosen = array();
			$only   = isset( $assoc_args['rule'] ) ? sanitize_key( (string) $assoc_args['rule'] ) : '';

			foreach ( $rules as $key => $rule ) {
				if ( '' !== $only && $key !== $only ) {
					continue;
				}

				$chosen[ $key ] = $rule;
			}

			if ( ! $chosen ) {
				WP_CLI::warning( 'No rule is both enabled and complete, so a real run would delete nothing.' );

				return;
			}

			foreach ( $chosen as $key => $rule ) {
				$preview = Dahu_File_Cleanup_Scanner::preview( $rule, 1 );

				WP_CLI::log( sprintf( '%s [%s]: %d file(s), %s', $rule['label'], $key, $preview['count'], size_format( $preview['bytes'] ) ) );
			}

			return;
		}

		$only    = isset( $assoc_args['rule'] ) ? sanitize_key( (string) $assoc_args['rule'] ) : null;
		$summary = Dahu_File_Cleanup_Runner::run( 'cli', $only );

		if ( isset( $summary['locked'] ) ) {
			WP_CLI::warning( 'Another run is in progress. Nothing was done.' );

			return;
		}

		if ( ! $summary ) {
			WP_CLI::warning( 'No rule is both enabled and complete. Nothing was deleted.' );

			return;
		}

		$files = 0;
		$bytes = 0;

		foreach ( $summary as $key => $result ) {
			$files += $result['deleted'];
			$bytes += $result['bytes'];

			WP_CLI::log(
				sprintf(
					'%s [%s]: %d deleted, %s freed%s%s',
					$result['label'],
					$key,
					$result['deleted'],
					size_format( $result['bytes'] ),
					$result['failed'] ? sprintf( ', %d could not be deleted', $result['failed'] ) : '',
					$result['capped'] ? ' (ceiling reached, more remain)' : ''
				)
			);
		}

		WP_CLI::success( sprintf( '%d file(s) deleted, %s freed.', $files, size_format( $bytes ) ) );
	}

	/**
	 * The rules a command was pointed at.
	 *
	 * @param array<string,string> $assoc_args Flags.
	 * @return array<string,array<string,mixed>>
	 */
	private function chosen_rules( $assoc_args ) {

		$rules = Dahu_File_Cleanup_Settings::rules();

		if ( ! isset( $assoc_args['rule'] ) ) {
			return $rules;
		}

		$key = sanitize_key( (string) $assoc_args['rule'] );

		return isset( $rules[ $key ] ) ? array( $key => $rules[ $key ] ) : array();
	}

	/**
	 * One word for a rule's state.
	 *
	 * @param array<string,mixed> $rule     Rule.
	 * @param string[]            $blockers Blockers.
	 * @return string
	 */
	private static function state_of( $rule, $blockers ) {

		if ( $blockers ) {
			return 'incomplete';
		}

		return empty( $rule['enabled'] ) ? 'off' : 'on';
	}
}
