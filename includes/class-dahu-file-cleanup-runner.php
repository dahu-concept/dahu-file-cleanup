<?php
/**
 * Running a rule for real.
 *
 * Two things live here, and only two: the loop over the enabled rules, and
 * the single function in this plugin allowed to call unlink().
 *
 * That function re-validates everything. The walk that selected a file may
 * have finished a second ago or a minute ago, the filesystem can have changed
 * underneath it, and a check performed at selection time is a check performed
 * on a different filesystem than the one being written to. So the file is
 * resolved again, its type is tested again, and its extension is tested
 * against the rule's list again, immediately before it is removed. If any of
 * that disagrees, the file stays.
 *
 * @package DahuFileCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Executes rules.
 */
class Dahu_File_Cleanup_Runner {

	/**
	 * Name of the best-effort lock.
	 */
	const LOCK = 'dahu_file_cleanup_running';

	/**
	 * How long the lock survives a run that died halfway.
	 */
	const LOCK_TTL = 900;

	/**
	 * Run every runnable rule, or one of them.
	 *
	 * @param string      $mode     'scheduled', 'manual' or 'cli'.
	 * @param string|null $only_key Run just this rule.
	 * @return array<string,mixed> Summary, keyed by rule, plus a 'locked' key when nothing ran.
	 */
	public static function run( $mode, $only_key = null ) {

		$mode = in_array( $mode, array( 'scheduled', 'manual', 'cli' ), true ) ? $mode : 'scheduled';

		if ( get_transient( self::LOCK ) ) {
			// Deleting the same file twice is harmless — the second unlink
			// simply fails — but walking a large tree twice at once on a
			// shared host is not, and a nightly run that collides with a
			// manual one is exactly when somebody is watching.
			return array( 'locked' => true );
		}

		set_transient( self::LOCK, time(), self::LOCK_TTL );

		$settings = Dahu_File_Cleanup_Settings::get();
		$rules    = Dahu_File_Cleanup_Settings::runnable_rules();
		$ceiling  = (int) $settings['max_per_run'];
		$summary  = array();

		if ( null !== $only_key ) {
			$only_key = sanitize_key( (string) $only_key );
			$rules    = isset( $rules[ $only_key ] ) ? array( $only_key => $rules[ $only_key ] ) : array();
		}

		foreach ( $rules as $key => $rule ) {
			$summary[ $key ] = self::run_rule( $key, $rule, $mode, $ceiling );
		}

		delete_transient( self::LOCK );

		/**
		 * Fires after a run, successful or empty.
		 *
		 * @param array<string,mixed> $summary Per-rule results.
		 * @param string              $mode    How the run was started.
		 */
		do_action( 'dahu_file_cleanup_after_run', $summary, $mode );

		return $summary;
	}

	/**
	 * Run one rule.
	 *
	 * @param string              $key     Rule key.
	 * @param array<string,mixed> $rule    Rule.
	 * @param string              $mode    Run mode.
	 * @param int                 $ceiling Files allowed per rule per run.
	 * @return array<string,mixed>
	 */
	private static function run_rule( $key, $rule, $mode, $ceiling ) {

		$deleted = 0;
		$bytes   = 0;
		$failed  = 0;
		$capped  = false;
		$ceiling = max( 1, (int) $ceiling );

		$diagnostics = Dahu_File_Cleanup_Scanner::walk(
			$rule,
			static function ( $file ) use ( $key, $rule, $ceiling, &$deleted, &$bytes, &$failed, &$capped ) {

				/**
				 * Veto the deletion of one file.
				 *
				 * Returning false keeps it. Called for every file the rule
				 * selected, during a real run only — the dry run never
				 * reaches here, so a filter that keeps files makes the
				 * preview optimistic and should say so on screen.
				 *
				 * @param bool                $delete Whether to delete.
				 * @param array<string,mixed> $file   The file.
				 * @param string              $key    Rule key.
				 * @param array<string,mixed> $rule   The rule.
				 */
				$allowed = apply_filters( 'dahu_file_cleanup_delete_file', true, $file, $key, $rule );

				if ( $allowed ) {
					if ( self::delete_file( $file['path'], $file['root'], $rule['extensions'] ) ) {
						++$deleted;
						$bytes += $file['size'];
					} else {
						++$failed;
					}
				}

				if ( $deleted >= $ceiling ) {
					$capped = true;

					return false;
				}

				return true;
			}
		);

		Dahu_File_Cleanup_Log::add(
			array(
				'mode'    => $mode,
				'rule'    => $key,
				'label'   => $rule['label'],
				'files'   => $deleted,
				'bytes'   => $bytes,
				'failed'  => $failed,
				'capped'  => $capped,
			)
		);

		return array(
			'label'       => $rule['label'],
			'deleted'     => $deleted,
			'bytes'       => $bytes,
			'failed'      => $failed,
			'capped'      => $capped,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * Delete one file. The only unlink() in this plugin.
	 *
	 * @param string   $path       File path as the walk found it.
	 * @param string   $root       The validated root it was found under.
	 * @param string[] $extensions The rule's extension list.
	 * @return bool Whether the file is gone.
	 */
	public static function delete_file( $path, $root, $extensions ) {

		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		if ( ! is_array( $extensions ) || ! $extensions ) {
			return false;
		}

		// Checked on the path as given, before resolving: realpath() would
		// have followed the link and hidden it.
		if ( is_link( $path ) ) {
			return false;
		}

		$real = realpath( $path );

		if ( false === $real ) {
			return false;
		}

		$real = wp_normalize_path( $real );

		if ( ! is_file( $real ) ) {
			return false;
		}

		$root = untrailingslashit( wp_normalize_path( (string) $root ) );

		if ( '' === $root || 0 !== strpos( $real, trailingslashit( $root ) ) ) {
			return false;
		}

		// The root is re-validated too, so that this function is safe to
		// call on its own — it is public, the CLI command uses it, and a
		// caller that passed an unvalidated root would otherwise get a
		// deletion anywhere under it.
		//
		// Memoised per root, because the alternative is a realpath() and a
		// handful of directory resolutions for every one of what may be
		// fifty thousand files, and the answer cannot differ between two
		// files in the same folder in the same request.
		if ( ! self::root_is_valid( $root ) ) {
			return false;
		}

		$extension = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, $extensions, true ) ) {
			return false;
		}

		// An extension on the never list cannot reach here through a rule —
		// the settings refuse it — but this function is public API for the
		// CLI command and for anything the filters above hand it, and the
		// last line of defence is worth more than the microsecond.
		if ( in_array( $extension, Dahu_File_Cleanup_Rules::forbidden_extensions(), true ) ) {
			return false;
		}

		return (bool) @unlink( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted by another process between the walk and here is an ordinary outcome, reported as a failure count, not a PHP warning on a cron run.
	}

	/**
	 * Is this root a folder a rule is allowed to delete from?
	 *
	 * Memoised for the request. Reset by flush_root_cache(), which exists
	 * for the test bench: a validation answer that outlived the filesystem
	 * it was given for would make a test pass over a folder that had since
	 * been turned into something the plugin must refuse.
	 *
	 * @param string $root Normalised, resolved root.
	 * @return bool
	 */
	private static function root_is_valid( $root ) {

		if ( ! isset( self::$valid_roots[ $root ] ) ) {
			$validation                 = Dahu_File_Cleanup_Paths::validate( $root );
			self::$valid_roots[ $root ] = ( $validation['ok'] && $validation['path'] === $root );
		}

		return self::$valid_roots[ $root ];
	}

	/**
	 * Memoised root validations, for this request.
	 *
	 * @var array<string,bool>
	 */
	private static $valid_roots = array();

	/**
	 * Forget the memoised root validations.
	 *
	 * @return void
	 */
	public static function flush_root_cache() {

		self::$valid_roots = array();
	}
}
