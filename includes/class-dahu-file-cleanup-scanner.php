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
	 * Walk a rule's folders, handing each selected file to a visitor.
	 *
	 * The visitor may return false to stop the walk — that is how the
	 * per-run ceiling is enforced without the walk knowing what a ceiling
	 * is.
	 *
	 * @param array<string,mixed> $rule    Rule.
	 * @param callable            $visitor Receives one file array.
	 * @return array<int,array<string,mixed>> One diagnostic per path line.
	 */
	public static function walk( $rule, $visitor ) {

		$rule        = Dahu_File_Cleanup_Rules::normalise( $rule );
		$diagnostics = array();
		$extensions  = $rule['extensions'];

		if ( ! $extensions || '' === $rule['days'] ) {
			// Nothing to walk. Both cases are reported by the screen as
			// blockers on the rule itself, so there is no diagnostic to add
			// here that would not be a second copy of the same sentence.
			return $diagnostics;
		}

		$cutoff    = time() - ( (int) $rule['days'] * DAY_IN_SECONDS );
		$recursive = ! empty( $rule['recursive'] );
		$stopped   = false;

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
						$stopped = true;
						$stack   = array();
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
	 * The file list is bounded because it is built in memory and printed to
	 * a page. The totals are not: they count every selected file, so a
	 * folder with fifty thousand candidates reports fifty thousand and
	 * shows the first few hundred.
	 *
	 * @param array<string,mixed> $rule  Rule.
	 * @param int                 $limit How many files to keep for display.
	 * @return array<string,mixed>
	 */
	public static function preview( $rule, $limit = 200 ) {

		$limit = max( 1, (int) $limit );
		$files = array();
		$count = 0;
		$bytes = 0;

		$diagnostics = self::walk(
			$rule,
			static function ( $file ) use ( &$files, &$count, &$bytes, $limit ) {

				++$count;
				$bytes += $file['size'];

				if ( count( $files ) < $limit ) {
					$files[] = $file;
				}

				return true;
			}
		);

		return array(
			'files'       => $files,
			'count'       => $count,
			'bytes'       => $bytes,
			'truncated'   => $count > count( $files ),
			'diagnostics' => $diagnostics,
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
