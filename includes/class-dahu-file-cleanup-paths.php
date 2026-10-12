<?php
/**
 * Path validation.
 *
 * This is the part of the plugin that decides what a rule is allowed to point
 * at, and it is deliberately the most suspicious code in it. Everything else
 * assumes a validated root; nothing else is allowed to assume anything.
 *
 * Three ideas, in order of importance:
 *
 * 1. A path is judged on what the filesystem says it resolves to, never on
 *    what was typed. A symlink or a `..` can make `wp-content/uploads/logs`
 *    mean anything at all, so validation runs on realpath() and refuses a
 *    path it cannot resolve.
 * 2. The resolved path must sit inside a tree WordPress owns, and must not be
 *    one of that tree's structural directories, nor anywhere inside the
 *    directories that hold code and translations.
 * 3. No wildcards, ever. Selection is by file extension, declared on the
 *    rule; a path is a place, not a pattern.
 *
 * @package DahuFileCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates the folders a rule may target.
 */
class Dahu_File_Cleanup_Paths {

	/**
	 * Validate one path.
	 *
	 * The path may be relative to the WordPress root (what the screen asks
	 * for) or absolute.
	 *
	 * @param string $raw Path as typed.
	 * @return array{ok:bool,path:string,reason:string}
	 */
	public static function validate( $raw ) {

		$path = trim( (string) $raw );

		if ( '' === $path ) {
			return self::refuse( __( 'empty path', 'dahu-file-cleanup' ) );
		}

		// A null byte truncates a path for the C functions underneath PHP,
		// so `uploads/logs\0.png` could resolve as `uploads/logs`. PHP's own
		// stream wrappers reject it, but a path that reaches realpath() with
		// one in it is not a typo and does not get a second chance here.
		if ( false !== strpos( $path, "\0" ) ) {
			return self::refuse( __( 'the path contains a null byte', 'dahu-file-cleanup' ) );
		}

		if ( false !== strpos( $path, '*' ) || false !== strpos( $path, '?' ) ) {
			return self::refuse( __( 'wildcards (* ?) are not allowed: a rule targets a folder, and selects files by extension', 'dahu-file-cleanup' ) );
		}

		$path = wp_normalize_path( $path );

		if ( ! path_is_absolute( $path ) ) {
			$path = path_join( wp_normalize_path( ABSPATH ), ltrim( $path, '/' ) );
		}

		$real = realpath( $path );

		if ( false === $real ) {
			return self::refuse( __( 'no such folder on this server', 'dahu-file-cleanup' ) );
		}

		if ( ! is_dir( $real ) ) {
			return self::refuse( __( 'the target is not a folder', 'dahu-file-cleanup' ) );
		}

		$real = untrailingslashit( wp_normalize_path( $real ) );

		// --- Inside a tree WordPress owns -------------------------------
		//
		// Three roots rather than one. wp-content outside the document root
		// is a recommended hardening, and so is a separate uploads
		// directory; a plugin that only accepted paths under ABSPATH would
		// refuse the uploads folder of a correctly hardened site.
		if ( ! self::inside_any( $real, self::allowed_roots() ) ) {
			return self::refuse( __( 'the resolved path is outside this site: it is not under the WordPress root, wp-content or the uploads folder', 'dahu-file-cleanup' ) );
		}

		// --- Not inside a code, translation or update directory ---------
		//
		// The ban is on the whole subtree, not just its root. A plugin that
		// writes a cache under its own folder is a real thing, and cleaning
		// it is still not this tool's job: deleting files from a code
		// directory on a schedule, unattended, is how a site stops working
		// on a night nobody was watching. wp-content/upgrade is here for a
		// narrower reason — it holds a WordPress update while it is being
		// unpacked, and a scheduled run is not aware of one in progress.
		//
		// Checked before the equality test below so that the refusal names
		// the folder that was hit.
		foreach ( self::code_roots() as $label => $code_root ) {
			if ( $real === $code_root || self::inside( $real, $code_root ) ) {
				return self::refuse(
					sprintf(
						/* translators: %s: name of a WordPress directory, e.g. "plugins". */
						__( 'that path is inside the %s folder, which this plugin never deletes from', 'dahu-file-cleanup' ),
						$label
					)
				);
			}
		}

		// --- Not a structural directory ---------------------------------
		foreach ( self::protected_roots() as $protected ) {
			if ( $real === $protected ) {
				return self::refuse( __( 'that is a structural folder of the site (the WordPress root, wp-content or the uploads root): target a specific subfolder instead', 'dahu-file-cleanup' ) );
			}
		}

		return array(
			'ok'     => true,
			'path'   => $real,
			'reason' => '',
		);
	}

	/**
	 * The trees a rule may point inside.
	 *
	 * @return string[] Normalised, resolved, no trailing slash.
	 */
	public static function allowed_roots() {

		$roots   = array();
		$uploads = wp_get_upload_dir();

		$candidates = array(
			ABSPATH,
			WP_CONTENT_DIR,
			isset( $uploads['basedir'] ) ? $uploads['basedir'] : '',
		);

		foreach ( $candidates as $candidate ) {
			$resolved = self::resolve( $candidate );

			if ( '' !== $resolved ) {
				$roots[ $resolved ] = $resolved;
			}
		}

		return array_values( $roots );
	}

	/**
	 * Directories that may not themselves be targeted.
	 *
	 * @return string[]
	 */
	public static function protected_roots() {

		$uploads = wp_get_upload_dir();

		$candidates = array(
			ABSPATH,
			ABSPATH . 'wp-admin',
			ABSPATH . 'wp-includes',
			WP_CONTENT_DIR,
			isset( $uploads['basedir'] ) ? $uploads['basedir'] : '',
		);

		foreach ( self::code_roots() as $code_root ) {
			$candidates[] = $code_root;
		}

		$roots = array();

		foreach ( $candidates as $candidate ) {
			$resolved = self::resolve( $candidate );

			if ( '' !== $resolved ) {
				$roots[ $resolved ] = $resolved;
			}
		}

		return array_values( $roots );
	}

	/**
	 * Directories whose whole subtree is off limits.
	 *
	 * Keyed by the name shown in the refusal, so the message says which one
	 * was hit rather than listing all of them.
	 *
	 * @return array<string,string>
	 */
	public static function code_roots() {

		$candidates = array(
			'wp-admin'    => ABSPATH . 'wp-admin',
			'wp-includes' => ABSPATH . 'wp-includes',
			'plugins'     => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins',
			'mu-plugins'  => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
			'languages'   => WP_CONTENT_DIR . '/languages',
			'upgrade'     => WP_CONTENT_DIR . '/upgrade',
		);

		// Theme roots are a mess to pin down, and the mess matters: a theme
		// folder this missed would be deletable.
		//
		// get_theme_roots() returns values that are relative to wp-content
		// and start with a slash — `/themes` — which path_is_absolute()
		// quite correctly calls absolute, and which then resolves to a
		// `/themes` that does not exist, and the whole entry is silently
		// dropped. That is exactly what happened here, and the bench caught
		// it: wp-content/themes was accepted as a target.
		//
		// register_theme_directory() can also store a genuinely absolute
		// path, and WordPress's own get_theme_root() handles the two cases
		// differently depending on the version. So each candidate is tried
		// both ways and whichever resolves is kept.
		$theme_candidates = array( WP_CONTENT_DIR . '/themes' );

		if ( function_exists( 'get_theme_root' ) ) {
			$theme_candidates[] = get_theme_root();
		}

		if ( function_exists( 'get_theme_roots' ) ) {
			foreach ( (array) get_theme_roots() as $theme_root ) {
				$theme_candidates[] = (string) $theme_root;
			}
		}

		if ( ! empty( $GLOBALS['wp_theme_directories'] ) ) {
			foreach ( (array) $GLOBALS['wp_theme_directories'] as $theme_root ) {
				$theme_candidates[] = (string) $theme_root;
			}
		}

		$index = 0;

		foreach ( $theme_candidates as $theme_root ) {
			if ( '' === trim( $theme_root ) ) {
				continue;
			}

			$resolved = self::resolve( $theme_root );

			if ( '' === $resolved ) {
				$resolved = self::resolve( WP_CONTENT_DIR . '/' . ltrim( $theme_root, '/' ) );
			}

			if ( '' === $resolved ) {
				continue;
			}

			$candidates[ 0 === $index ? 'themes' : 'themes-' . $index ] = $resolved;
			++$index;
		}

		$roots = array();

		foreach ( $candidates as $label => $candidate ) {
			$resolved = self::resolve( $candidate );

			if ( '' !== $resolved && ! in_array( $resolved, $roots, true ) ) {
				$roots[ $label ] = $resolved;
			}
		}

		return $roots;
	}

	/**
	 * realpath() a directory and normalise it, or return ''.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function resolve( $path ) {

		$path = trim( (string) $path );

		if ( '' === $path ) {
			return '';
		}

		$real = realpath( $path );

		if ( false === $real ) {
			return '';
		}

		return untrailingslashit( wp_normalize_path( $real ) );
	}

	/**
	 * Is $path strictly inside $root?
	 *
	 * Compared with a trailing slash on both sides, so that
	 * `/var/www/uploads-old` is not read as being inside `/var/www/uploads`.
	 *
	 * @param string $path Resolved, normalised, no trailing slash.
	 * @param string $root Resolved, normalised, no trailing slash.
	 * @return bool
	 */
	public static function inside( $path, $root ) {

		if ( '' === $path || '' === $root ) {
			return false;
		}

		return 0 === strpos( trailingslashit( $path ), trailingslashit( $root ) ) && $path !== $root;
	}

	/**
	 * Is $path inside any of $roots, or equal to one of them?
	 *
	 * @param string   $path  Resolved path.
	 * @param string[] $roots Resolved roots.
	 * @return bool
	 */
	public static function inside_any( $path, $roots ) {

		foreach ( $roots as $root ) {
			if ( $path === $root || self::inside( $path, $root ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Shorten a resolved path for display.
	 *
	 * Relative to the WordPress root when it is under it, absolute otherwise
	 * — a site whose uploads folder lives outside the root would otherwise
	 * get a path with the root chopped off its front, which reads as a
	 * relative path and is not one.
	 *
	 * @param string $path Resolved path.
	 * @return string
	 */
	public static function shorten( $path ) {

		$path = wp_normalize_path( (string) $path );
		$root = self::resolve( ABSPATH );

		if ( '' !== $root && self::inside( $path, $root ) ) {
			return ltrim( substr( $path, strlen( $root ) ), '/' );
		}

		return $path;
	}

	/**
	 * Build a refusal.
	 *
	 * @param string $reason Human-readable reason.
	 * @return array{ok:bool,path:string,reason:string}
	 */
	private static function refuse( $reason ) {

		return array(
			'ok'     => false,
			'path'   => '',
			'reason' => $reason,
		);
	}
}
