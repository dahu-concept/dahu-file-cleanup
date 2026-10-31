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
	 * Directory entries a rule may inspect, per file it is allowed to delete.
	 *
	 * The per-run ceiling counts deletions, which is what the screen asks for
	 * and what the log reports, and it stays that. But a ceiling on deletions
	 * bounds nothing at all in the case it was written for: a folder whose
	 * files are all too recent selects none of them, so no deletion is
	 * counted, so the ceiling is never reached, and the run walks the whole
	 * folder anyway. The guard-rail guarded the cheap case and let the
	 * expensive one through.
	 *
	 * So the deletion ceiling is turned into a work budget by this factor,
	 * and the walk stops on whichever comes first. The factor is what keeps
	 * the two from fighting: the budget is always this many times larger than
	 * the deletions allowed, so it cannot bite before the deletion ceiling in
	 * any folder where even one file in ten is selectable — and a folder
	 * sparser than that is, by itself, the folder this bound exists for.
	 *
	 * Set to 10 on 2026-10-30, down from 50. The factor was the open question
	 * when the budget was introduced, and 10 is the answer.
	 *
	 * What that means in real numbers, because a ratio on its own is easy to
	 * misread. The ceiling defaults to 2000, so the default budget is 20,000
	 * directory entries per rule, down from 100,000. At the highest ceiling
	 * the screen accepts, 100,000, it is a million entries, down from five.
	 * The bound therefore does not bite at all on an ordinary folder: it bites
	 * on a tree of tens of thousands of entries that yields almost no
	 * deletions, which is the case it was written for and the case nothing
	 * bounded before.
	 *
	 * A merchant who has lowered the ceiling gets the ratio applied to their
	 * own number — set it to 4 and the walk stops after 40 entries, where the
	 * old factor allowed 200 and the unbounded walk this bound replaced went
	 * through the whole folder, 400 entries in the case that was measured.
	 *
	 * Lowering the factor tightens the bound, and that is the point, but it is
	 * worth being explicit about who pays: a folder sparser than one in ten
	 * now needs several runs where one used to do. That is not a loss, because
	 * the bound is PER RUN and the run is scheduled — the next night continues
	 * where this one stopped, and the log says `capped` so the merchant can
	 * see it happening rather than guessing. A folder sparse enough to need
	 * many nights is a folder whose rule is pointed at the wrong place, which
	 * is a thing worth finding out.
	 *
	 * Deliberately NOT filterable, unlike the preview's
	 * PREVIEW_WORK_BUDGET: that one guards a screen the merchant is watching,
	 * this one guards an unattended run on someone else's shared host. If a
	 * real folder ever needs more, raising `max_per_run` raises the budget
	 * with it — that is why the budget is derived from the setting instead of
	 * being a second number to keep in sync.
	 */
	const WORK_PER_DELETION = 10;

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
	 * @param int                 $ceiling Files allowed to be DELETED per rule
	 *                                     per run. Also what the work budget
	 *                                     handed to the walk is derived from.
	 * @return array<string,mixed>
	 */
	private static function run_rule( $key, $rule, $mode, $ceiling ) {

		$deleted = 0;
		$bytes   = 0;
		$failed  = 0;
		$capped  = false;
		$ceiling = max( 1, (int) $ceiling );
		$budget  = $ceiling * self::WORK_PER_DELETION;

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
			},
			$budget
		);

		// Why it stopped, if it did. The walk reports a path line it gave up
		// on and says which bound ran out, so the two cases are told apart on
		// screen: "come back tomorrow for the rest" and "this folder is too
		// big to sweep at this ceiling" are different sentences and call for
		// different actions.
		$examined = 0;
		$reason   = '';

		foreach ( $diagnostics as $diagnostic ) {
			$examined += (int) $diagnostic['examined'];

			if ( '' === $reason && ! empty( $diagnostic['exhausted'] ) ) {
				$reason = 'work' === $diagnostic['exhausted'] ? 'work' : 'deletions';
			}
		}

		if ( '' === $reason && $capped ) {
			// The visitor asked to stop on the very last entry of the walk,
			// so the walk had nothing left to refuse. Still a cap.
			$reason = 'deletions';
		}

		$capped = '' !== $reason;

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
			'label'         => $rule['label'],
			'deleted'       => $deleted,
			'bytes'         => $bytes,
			'failed'        => $failed,
			'capped'        => $capped,
			'capped_reason' => $reason,
			'examined'      => $examined,
			'budget'        => $budget,
			'diagnostics'   => $diagnostics,
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
