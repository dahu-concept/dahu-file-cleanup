<?php
/**
 * Walking the folders a rule points at.
 *
 * One walk, two callers. The dry run collects a bounded list of what would be
 * deleted; the real run deletes as it goes and keeps no list at all. They use
 * the same traversal and the same filters on purpose — a preview produced by
 * different code from the deletion is a preview of something else.
 *
 * The traversal is written by hand rather than with RecursiveIteratorIterator,
 * for one reason: SPL's recursive iterator follows directory symlinks, and
 * nothing in its flags turns that off. A symlinked subfolder inside an uploads
 * directory would therefore be walked, and the dry run would list files that
 * live somewhere else entirely. The deletion itself was already safe — every
 * unlink re-resolves the file and refuses one outside the validated root — but
 * a dry run that lists files it will not delete is worse than useless, because
 * it is the screen people trust before switching a rule on.
 *
 * @package DahuFileCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds the files a rule selects.
 */
class Dahu_File_Cleanup_Scanner {

	/**
	 * Volume above which "many candidates, none old enough" stops looking
	 * like a quiet folder and starts looking like reset timestamps.
	 */
	const CLONE_HINT_THRESHOLD = 50;

	/**
	 * Directory entries the dry run may inspect, in total, before it stops.
	 *
	 * The dry run had no budget at all, and that was deliberate: a preview
	 * that stops early counts fewer files than the rule would delete, and a
	 * merchant reading "12 files will be deleted" off a count that stopped at
	 * 12 out of 3,000 would decide on a false figure. A wrong number is worse
	 * than a wait.
	 *
	 * But the preview is an administration page somebody is sitting in front
	 * of, and the work it did was the size of the folder, whatever that was:
	 * measured on a folder of 100,000 entries, it inspected 100,000 of them.
	 * On the kind of storage a shared host gives a site, one stat() is not
	 * free, and a request that long does not return at all — it hits the
	 * execution limit, and the merchant gets a blank page instead of a wrong
	 * number, which is no better.
	 *
	 * So the dry run is bounded too, and the honesty is bought back in words
	 * rather than by removing the bound: when the budget runs out, the preview
	 * says so, and says that its count is a floor and not the total. The two
	 * cases — "this is everything" and "this is as far as I got" — are two
	 * different sentences on screen, and this budget is what makes the second
	 * one possible.
	 *
	 * Set much higher than a run's budget: nothing is deleted here, the cost
	 * is one request and not a nightly job's, and the number has to be large
	 * enough that an ordinary folder is never truncated.
	 */
	const PREVIEW_WORK_BUDGET = 25000;

	/**
	 * Walk a rule's folders, handing each selected file to a visitor.
	 *
	 * The visitor may return false to stop the walk — that is how the
	 * per-run deletion ceiling is enforced without the walk knowing what a
	 * deletion is.
	 *
	 * A ceiling the visitor enforces can only ever count what reached the
	 * visitor, and what reaches the visitor is the SELECTED files. The work
	 * of a walk is not its selections: a folder of forty thousand files of
	 * which none is old enough selects nothing, calls the visitor never, and
	 * still costs forty thousand stat() calls on a shared host. That is the
	 * case a per-run limit exists for, and it is exactly the case a
	 * visitor-side limit cannot see. So the walk is given its own budget,
	 * counted in directory entries inspected, and it is the walk that stops
	 * when the budget is gone.
	 *
	 * The budget is shared across the rule's path lines, because the cost it
	 * bounds is the cost of the whole run of the rule.
	 *
	 * @param array<string,mixed> $rule         Rule.
	 * @param callable            $visitor      Receives one file array.
	 * @param int                 $work_ceiling Directory entries this walk may
	 *                                          inspect in total; 0 for no
	 *                                          budget. The dry run passes a
	 *                                          budget of its own, far larger
	 *                                          than a run's, and reports in
	 *                                          words when it ran out — see
	 *                                          preview() — because a truncated
	 *                                          preview of an unknown remainder
	 *                                          is a lie unless it says it is
	 *                                          truncated.
	 * @return array<int,array<string,mixed>> One diagnostic per path line.
	 */
	public static function walk( $rule, $visitor, $work_ceiling = 0 ) {

		$rule        = Dahu_File_Cleanup_Rules::normalise( $rule );
		$diagnostics = array();
		$extensions  = $rule['extensions'];

		if ( ! $extensions || '' === $rule['days'] ) {
			// Nothing to walk. Both cases are reported by the screen as
			// blockers on the rule itself, so there is no diagnostic to add
			// here that would not be a second copy of the same sentence.
			return $diagnostics;
		}

		$cutoff       = time() - ( (int) $rule['days'] * DAY_IN_SECONDS );
		$recursive    = ! empty( $rule['recursive'] );
		$stopped      = false;
		$work_ceiling = max( 0, (int) $work_ceiling );
		$examined     = 0;

		foreach ( Dahu_File_Cleanup_Rules::path_lines( $rule ) as $line ) {

			$diagnostic = array(
				'line'       => $line,
				'ok'         => false,
				'resolved'   => '',
				'reason'     => '',
				'files'      => 0,
				'matching'   => 0,
				'symlinks'   => 0,
				'unreadable' => 0,
				'oldest'     => null,
				'newest'     => null,
				'selected'   => 0,
				'examined'   => 0,
				'exhausted'  => '',
			);

			$validation = Dahu_File_Cleanup_Paths::validate( $line );

			if ( ! $validation['ok'] ) {
				$diagnostic['reason'] = $validation['reason'];
				$diagnostics[]        = $diagnostic;
				continue;
			}

			$root                   = $validation['path'];
			$diagnostic['ok']       = true;
			$diagnostic['resolved'] = $root;

			if ( $stopped ) {
				// The visitor asked to stop during an earlier path line. The
				// remaining lines are still reported, with their validation
				// result, so the screen does not look as though they were
				// never configured.
				$diagnostic['reason'] = __( 'not walked: the ceiling for this run was reached first', 'dahu-file-cleanup' );
				$diagnostics[]        = $diagnostic;
				continue;
			}

			$stack = array( array( $root, 0 ) );

			while ( $stack ) {
				list( $directory, $depth ) = array_pop( $stack );

				// Throwable, not Exception. An unreadable directory raises
				// an UnexpectedValueException, which is an Exception — but a
				// folder whose name the filesystem hands back in a shape this
				// constructor refuses raises an Error instead, and an Error
				// escaping here would end a nightly run in the middle of a
				// walk, leaving no diagnostic and no log line for the folders
				// that came after it. One unreadable folder is a counter, not
				// the end of the run.
				try {
					$entries = new FilesystemIterator( $directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO );
				} catch ( Throwable $e ) {
					++$diagnostic['unreadable'];
					continue;
				}

				foreach ( $entries as $entry ) {

					// Counted before anything is decided about the entry:
					// the budget bounds the work, and looking at an entry
					// in order to skip it is the work.
					++$examined;
					++$diagnostic['examined'];

					if ( $work_ceiling && $examined >= $work_ceiling ) {
						$diagnostic['exhausted'] = 'work';
						$stopped                 = true;
						$stack                   = array();
						break;
					}

					// A symbolic link is never followed and never deleted,
					// whether it points at a file or a directory. Counted so
					// the diagnostic can say so rather than leaving a gap
					// between "files in folder" and what the shell shows.
					if ( $entry->isLink() ) {
						++$diagnostic['symlinks'];
						continue;
					}

					if ( $entry->isDir() ) {
						if ( $recursive && $depth < Dahu_File_Cleanup_Rules::MAX_DEPTH ) {
							$stack[] = array( $entry->getPathname(), $depth + 1 );
						}
						continue;
					}

					if ( ! $entry->isFile() ) {
						continue;
					}

					++$diagnostic['files'];

					$extension = strtolower( $entry->getExtension() );

					if ( ! in_array( $extension, $extensions, true ) ) {
						continue;
					}

					++$diagnostic['matching'];

					$mtime = (int) $entry->getMTime();

					if ( null === $diagnostic['oldest'] || $mtime < $diagnostic['oldest'] ) {
						$diagnostic['oldest'] = $mtime;
					}

					if ( null === $diagnostic['newest'] || $mtime > $diagnostic['newest'] ) {
						$diagnostic['newest'] = $mtime;
					}

					if ( $mtime > $cutoff ) {
						continue;
					}

					$pathname = wp_normalize_path( $entry->getPathname() );
					$resolved = Dahu_File_Cleanup_Paths::resolve( dirname( $pathname ) );

					// Belt to the symlink braces: even reached through a
					// link this plugin does not follow, a file whose own
					// directory resolves outside the validated root is not
					// this rule's business.
					if ( '' === $resolved || ( $resolved !== $root && ! Dahu_File_Cleanup_Paths::inside( $resolved, $root ) ) ) {
						continue;
					}

					++$diagnostic['selected'];

					$file = array(
						'path'      => $pathname,
						'root'      => $root,
						'subfolder' => ltrim( (string) substr( $resolved, strlen( $root ) ), '/' ),
						'size'      => (int) $entry->getSize(),
						'mtime'     => $mtime,
					);

					if ( false === call_user_func( $visitor, $file ) ) {
						$diagnostic['exhausted'] = 'visitor';
						$stopped                 = true;
						$stack                   = array();
						break;
					}
				}
			}

			$diagnostics[] = $diagnostic;
		}

		return $diagnostics;
	}

	/**
	 * Dry run: what this rule would delete right now.
	 *
	 * Two bounds, and they mean different things.
	 *
	 * `$limit` bounds the file LIST only. The list is built in memory and
	 * printed to a page, so it is cut; the totals are not affected, and
	 * `truncated` says the list is shorter than the count.
	 *
	 * `$work_ceiling` bounds the WALK. When it runs out the walk stops where
	 * it is, so `count` and `bytes` are no longer totals but floors — and
	 * `complete` is false to say exactly that. A caller that prints `count`
	 * without looking at `complete` prints a number the merchant will read as
	 * the total when it is not; `preview_headline()` exists so no caller has
	 * to get that right by hand.
	 *
	 * @param array<string,mixed> $rule         Rule.
	 * @param int                 $limit        How many files to keep for display.
	 * @param int|null            $work_ceiling Directory entries the walk may
	 *                                          inspect; null for the default
	 *                                          budget, 0 for no budget at all.
	 * @return array<string,mixed>
	 */
	public static function preview( $rule, $limit = 200, $work_ceiling = null ) {

		$limit = max( 1, (int) $limit );

		if ( null === $work_ceiling ) {
			/**
			 * Filters the directory entries a dry run may inspect.
			 *
			 * Raising this makes the preview more often complete and the page
			 * slower; 0 removes the bound, which is what the command line can
			 * afford and an administration page cannot.
			 *
			 * @param int                 $budget Entries the dry run may inspect.
			 * @param array<string,mixed> $rule   The rule being previewed.
			 */
			$work_ceiling = apply_filters( 'dahu_file_cleanup_preview_work_budget', self::PREVIEW_WORK_BUDGET, $rule );
		}

		$work_ceiling = max( 0, (int) $work_ceiling );
		$files        = array();
		$count        = 0;
		$bytes        = 0;

		$diagnostics = self::walk(
			$rule,
			static function ( $file ) use ( &$files, &$count, &$bytes, $limit ) {

				++$count;
				$bytes += $file['size'];

				if ( count( $files ) < $limit ) {
					$files[] = $file;
				}

				return true;
			},
			$work_ceiling
		);

		// The walk reports which bound ran out, per path line. Only 'work'
		// can appear here: this visitor never returns false, so the walk is
		// never stopped by the visitor during a dry run.
		$examined = 0;
		$stopped  = false;

		foreach ( $diagnostics as $diagnostic ) {
			$examined += (int) $diagnostic['examined'];

			if ( 'work' === $diagnostic['exhausted'] ) {
				$stopped = true;
			}
		}

		return array(
			'files'       => $files,
			'count'       => $count,
			'bytes'       => $bytes,
			'truncated'   => $count > count( $files ),
			'complete'    => ! $stopped,
			'examined'    => $examined,
			'budget'      => $work_ceiling,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * What the screen says about a dry run's figures, in one or two sentences.
	 *
	 * The whole point of bounding the dry run is here. A complete preview gets
	 * one sentence and it states a total. A preview that stopped gets two: the
	 * first states the figure as a FLOOR, in different words, so it cannot be
	 * misread as the total; the second says the scan stopped and why, so the
	 * merchant knows the decision in front of them is being made on partial
	 * information and can act on that — raise the bound, or narrow the rule.
	 *
	 * Built here rather than in the screen so that it is one function with one
	 * answer, used by every surface that reports a dry run, and so that it can
	 * be measured without a browser.
	 *
	 * @param array<string,mixed> $preview A preview as returned above.
	 * @return string[] One sentence, or two.
	 */
	public static function preview_headline( $preview ) {

		$count    = isset( $preview['count'] ) ? (int) $preview['count'] : 0;
		$bytes    = isset( $preview['bytes'] ) ? (int) $preview['bytes'] : 0;
		$examined = isset( $preview['examined'] ) ? (int) $preview['examined'] : 0;

		// Absent means an older caller that never had the key: treated as
		// complete, which is what it was.
		if ( ! array_key_exists( 'complete', $preview ) || $preview['complete'] ) {
			return array(
				sprintf(
					/* translators: 1: number of files, 2: formatted size. */
					__( '%1$s file(s) selected, %2$s', 'dahu-file-cleanup' ),
					number_format_i18n( $count ),
					size_format( $bytes )
				),
			);
		}

		return array(
			sprintf(
				/* translators: 1: number of files found so far, 2: formatted size. */
				__( 'At least %1$s file(s) selected, at least %2$s — this is not the total.', 'dahu-file-cleanup' ),
				number_format_i18n( $count ),
				size_format( $bytes )
			),
			sprintf(
				/* translators: %s: number of directory entries inspected. */
				__( 'The scan stopped after looking at %s items, so it has not seen the whole folder and the real figures are higher. Narrow the rule to a smaller folder to get a complete count.', 'dahu-file-cleanup' ),
				number_format_i18n( $examined )
			),
		);
	}

	/**
	 * Count files per subfolder, for a recursive rule's preview.
	 *
	 * Built from the displayed files only, and the caller is told so: a
	 * truncated preview cannot honestly present a per-folder breakdown of
	 * everything.
	 *
	 * @param array<int,array<string,mixed>> $files Files.
	 * @return array<string,array{count:int,bytes:int}>
	 */
	public static function by_subfolder( $files ) {

		$folders = array();

		foreach ( $files as $file ) {
			$key = '' === $file['subfolder'] ? '.' : $file['subfolder'];

			if ( ! isset( $folders[ $key ] ) ) {
				$folders[ $key ] = array(
					'count' => 0,
					'bytes' => 0,
				);
			}

			++$folders[ $key ]['count'];
			$folders[ $key ]['bytes'] += $file['size'];
		}

		ksort( $folders );

		return $folders;
	}

	/**
	 * Do these diagnostics look like a folder whose timestamps were reset?
	 *
	 * A clone or a restore can rewrite every modification time to the day it
	 * ran, after which a retention rule finds nothing old anywhere, however
	 * old the files really are. The signature is a large number of files at
	 * the right extension of which none is old enough.
	 *
	 * The volume threshold is what keeps this from crying wolf: a small
	 * folder that is legitimately all recent — a log folder a few days into
	 * its life — has the same shape and nothing wrong with it.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return bool
	 */
	public static function looks_like_reset_timestamps( $diagnostics ) {

		foreach ( (array) $diagnostics as $diagnostic ) {
			if ( empty( $diagnostic['ok'] ) ) {
				continue;
			}

			if ( (int) $diagnostic['matching'] >= self::CLONE_HINT_THRESHOLD && 0 === (int) $diagnostic['selected'] ) {
				return true;
			}
		}

		return false;
	}
}
