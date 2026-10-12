<?php
/**
 * Rule shape, keys, extension lists and presets.
 *
 * A rule is five answers: what to call it, which folders, which file
 * extensions, how old a file has to be, and whether it is on. Nothing in a
 * rule is a pattern — the extension list is the whole of the selection — and
 * that is what keeps "delete files older than N days in this folder" from
 * ever meaning "delete everything in this folder".
 *
 * @package DahuFileCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rules: their shape, and what a valid one is.
 */
class Dahu_File_Cleanup_Rules {

	/**
	 * Longest rule key, to stay well inside any option-size concern.
	 */
	const KEY_MAX_LENGTH = 60;

	/**
	 * Most extensions one rule may list.
	 */
	const MAX_EXTENSIONS = 20;

	/**
	 * Longest extension accepted, in characters.
	 */
	const EXTENSION_MAX_LENGTH = 16;

	/**
	 * How deep a recursive rule walks.
	 *
	 * Not a security measure — the path checks are — but a stop against a
	 * pathological tree turning a nightly run into a hang.
	 */
	const MAX_DEPTH = 12;

	/**
	 * Extensions no rule may ever target.
	 *
	 * Code, scripts, stylesheets, markup, server configuration and
	 * translations. The folders that normally hold these are refused by
	 * Dahu_File_Cleanup_Paths already; this is the second half of the same
	 * idea, for a code file that has found its way into an uploads folder —
	 * which, when it happens, is either a mistake worth seeing or a shell
	 * worth keeping as evidence, and in neither case something to delete on
	 * a schedule.
	 *
	 * Archives and dumps are deliberately absent: `zip`, `sql`, `gz` and
	 * `wpstg` are exactly what a backup plugin leaves behind, and rotating
	 * those is most of the reason this plugin exists.
	 *
	 * @return string[]
	 */
	public static function forbidden_extensions() {

		return array(
			// PHP, in the spellings a server may still execute.
			'php',
			'php2',
			'php3',
			'php4',
			'php5',
			'php6',
			'php7',
			'php8',
			'phps',
			'phtml',
			'pht',
			'phar',
			'inc',
			// Other interpreted code.
			'js',
			'mjs',
			'cjs',
			'jsx',
			'ts',
			'py',
			'pl',
			'pm',
			'cgi',
			'rb',
			'sh',
			'bash',
			'bat',
			'cmd',
			'ps1',
			// Binaries.
			'exe',
			'dll',
			'so',
			'com',
			'jar',
			// Stylesheets, markup and templates.
			'css',
			'scss',
			'sass',
			'less',
			'html',
			'htm',
			'xhtml',
			'shtml',
			'tpl',
			'twig',
			'vue',
			'svelte',
			// Server configuration.
			'htaccess',
			'htpasswd',
			'ini',
			'conf',
			'config',
			'env',
			// Translations.
			'mo',
			'po',
			'pot',
		);
	}

	/**
	 * A rule with nothing in it.
	 *
	 * `days` is an empty string rather than a number on purpose: a retention
	 * this plugin invented would be a retention nobody chose, and a rule
	 * without one cannot be enabled.
	 *
	 * @return array<string,mixed>
	 */
	public static function blank() {

		return array(
			'label'      => '',
			'paths'      => '',
			'extensions' => array(),
			'days'       => '',
			'recursive'  => false,
			'enabled'    => false,
		);
	}

	/**
	 * Coerce anything into the rule shape.
	 *
	 * @param mixed $rule Stored or submitted rule.
	 * @return array<string,mixed>
	 */
	public static function normalise( $rule ) {

		$blank = self::blank();

		if ( ! is_array( $rule ) ) {
			return $blank;
		}

		return array(
			'label'      => isset( $rule['label'] ) ? sanitize_text_field( (string) $rule['label'] ) : $blank['label'],
			'paths'      => isset( $rule['paths'] ) ? self::normalise_paths( $rule['paths'] ) : $blank['paths'],
			'extensions' => isset( $rule['extensions'] ) ? self::normalise_extensions( $rule['extensions'] )['extensions'] : $blank['extensions'],
			'days'       => isset( $rule['days'] ) ? self::normalise_days( $rule['days'] ) : $blank['days'],
			'recursive'  => ! empty( $rule['recursive'] ),
			'enabled'    => ! empty( $rule['enabled'] ),
		);
	}

	/**
	 * Clean a multi-line path field.
	 *
	 * Lines are kept in order, trimmed, de-duplicated, and empty lines
	 * dropped. The paths themselves are not validated here: validation needs
	 * the filesystem, it has a reason to give for every refusal, and the
	 * screen shows those reasons next to the dry run rather than silently
	 * dropping a line somebody typed.
	 *
	 * @param mixed $raw Textarea contents.
	 * @return string
	 */
	public static function normalise_paths( $raw ) {

		$raw   = (string) $raw;
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$kept  = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );

			if ( '' === $line || in_array( $line, $kept, true ) ) {
				continue;
			}

			$kept[] = $line;
		}

		return implode( "\n", $kept );
	}

	/**
	 * Split the path field into lines.
	 *
	 * @param array<string,mixed> $rule Rule.
	 * @return string[]
	 */
	public static function path_lines( $rule ) {

		$paths = isset( $rule['paths'] ) ? (string) $rule['paths'] : '';

		if ( '' === trim( $paths ) ) {
			return array();
		}

		$lines = preg_split( '/\r\n|\r|\n/', $paths );
		$kept  = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );

			if ( '' !== $line ) {
				$kept[] = $line;
			}
		}

		return $kept;
	}

	/**
	 * Clean an extension list and say what was thrown out.
	 *
	 * Accepts anything a person might type: `pdf, .zip  log` or an array.
	 * Returns the kept extensions plus the refused ones with a reason, so
	 * the screen can say "`php` is never allowed" instead of quietly
	 * dropping it — a rule that silently lost an extension would look
	 * saved and select nothing.
	 *
	 * @param mixed $raw Submitted value.
	 * @return array{extensions:string[],refused:array<string,string>}
	 */
	public static function normalise_extensions( $raw ) {

		if ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			$parts = preg_split( '/[\s,;|]+/', (string) $raw );
		}

		$kept      = array();
		$refused   = array();
		$forbidden = self::forbidden_extensions();

		foreach ( (array) $parts as $part ) {
			$part = strtolower( trim( (string) $part ) );
			$part = ltrim( $part, '.' );

			if ( '' === $part ) {
				continue;
			}

			if ( ! ctype_alnum( $part ) ) {
				$refused[ $part ] = __( 'letters and digits only', 'dahu-file-cleanup' );
				continue;
			}

			if ( strlen( $part ) > self::EXTENSION_MAX_LENGTH ) {
				$refused[ $part ] = __( 'too long to be a file extension', 'dahu-file-cleanup' );
				continue;
			}

			if ( in_array( $part, $forbidden, true ) ) {
				$refused[ $part ] = __( 'code, configuration and translation files are never deleted by this plugin', 'dahu-file-cleanup' );
				continue;
			}

			if ( in_array( $part, $kept, true ) ) {
				continue;
			}

			if ( count( $kept ) >= self::MAX_EXTENSIONS ) {
				$refused[ $part ] = sprintf(
					/* translators: %d: maximum number of extensions per rule. */
					__( 'a rule lists at most %d extensions', 'dahu-file-cleanup' ),
					self::MAX_EXTENSIONS
				);
				continue;
			}

			$kept[] = $part;
		}

		return array(
			'extensions' => $kept,
			'refused'    => $refused,
		);
	}

	/**
	 * Normalise a retention in days.
	 *
	 * An empty value stays empty and means "not set", which is not the same
	 * as zero: zero would mean "delete everything, whatever its age", and
	 * that is not an answer a blank field should be read as giving.
	 *
	 * @param mixed $raw Submitted value.
	 * @return int|string
	 */
	public static function normalise_days( $raw ) {

		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		return max( 1, (int) $raw );
	}

	/**
	 * Why this rule cannot be enabled, or an empty array.
	 *
	 * Only the answers a rule must have. A folder that does not exist on
	 * this server is not one of them: a rule may legitimately be written
	 * before the folder appears, and the dry run reports it.
	 *
	 * @param array<string,mixed> $rule Rule.
	 * @return string[]
	 */
	public static function blockers( $rule ) {

		$blockers = array();

		if ( '' === trim( (string) ( isset( $rule['label'] ) ? $rule['label'] : '' ) ) ) {
			$blockers[] = __( 'the rule has no name', 'dahu-file-cleanup' );
		}

		if ( ! self::path_lines( $rule ) ) {
			$blockers[] = __( 'no folder is listed', 'dahu-file-cleanup' );
		}

		if ( empty( $rule['extensions'] ) ) {
			$blockers[] = __( 'no file extension is listed, so the rule would select nothing', 'dahu-file-cleanup' );
		}

		if ( '' === $rule['days'] ) {
			$blockers[] = __( 'no retention in days is set', 'dahu-file-cleanup' );
		}

		return $blockers;
	}

	/**
	 * Derive a storage key from a rule name.
	 *
	 * Whitespace becomes a hyphen before sanitize_key(), which strips spaces
	 * outright: without that step "Old invoices" and "Oldinvoices" would
	 * both key as `oldinvoices`.
	 *
	 * @param string   $label Rule name.
	 * @param string[] $taken Keys already in use.
	 * @return string
	 */
	public static function make_key( $label, $taken = array() ) {

		$key = sanitize_key( preg_replace( '/\s+/', '-', trim( (string) $label ) ) );
		$key = substr( $key, 0, self::KEY_MAX_LENGTH );

		if ( '' === $key ) {
			$key = 'rule';
		}

		if ( ! in_array( $key, $taken, true ) ) {
			return $key;
		}

		$suffix = 2;
		$base   = substr( $key, 0, self::KEY_MAX_LENGTH - 3 );

		while ( in_array( $base . '-' . $suffix, $taken, true ) && $suffix < 1000 ) {
			++$suffix;
		}

		return $base . '-' . $suffix;
	}

	/**
	 * Ready-made rules, for the folders well-known plugins write into.
	 *
	 * Every preset arrives disabled, and the two that hold business records
	 * arrive with no retention, so neither can run until somebody has
	 * decided how long those records are kept. The paths are the defaults
	 * those plugins use; a site that moved them edits the rule, and a site
	 * that does not have the plugin sees "no such folder" in the dry run.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function presets() {

		$presets = array(
			'invoices' => array(
				'label'       => __( 'Invoice and packing-slip PDFs', 'dahu-file-cleanup' ),
				'paths'       => 'wp-content/uploads/wpo_wcpdf/attachments',
				'extensions'  => array( 'pdf' ),
				'days'        => '',
				'recursive'   => false,
				'enabled'     => false,
				'note'        => __( 'Written by PDF Invoices & Packing Slips for WooCommerce. These are accounting records: in several countries they must be kept for years, so this rule arrives with no retention and cannot run until you set one.', 'dahu-file-cleanup' ),
			),
			'quotes'   => array(
				'label'       => __( 'Quote PDFs', 'dahu-file-cleanup' ),
				'paths'       => 'wp-content/uploads/yith_ywraq',
				'extensions'  => array( 'pdf' ),
				'days'        => '',
				'recursive'   => true,
				'enabled'     => false,
				'note'        => __( 'Written by YITH Request a Quote, in year and month subfolders — hence the recursive scan. A quote that became an order is part of that order\'s history.', 'dahu-file-cleanup' ),
			),
			'staging'  => array(
				'label'       => __( 'Staging backups and logs', 'dahu-file-cleanup' ),
				'paths'       => "wp-content/uploads/wp-staging/backups\nwp-content/uploads/wp-staging/logs",
				'extensions'  => array( 'wpstg', 'zip', 'sql', 'log' ),
				'days'        => 30,
				'recursive'   => true,
				'enabled'     => false,
				'note'        => __( 'Backups and logs left by WP Staging. Only the backups and logs folders: its working folders hold a clone being built, and a rule pointed at those would delete files it is still writing.', 'dahu-file-cleanup' ),
			),
			'logs'     => array(
				'label'       => __( 'WordPress debug logs', 'dahu-file-cleanup' ),
				'paths'       => 'wp-content/uploads/logs',
				'extensions'  => array( 'log', 'txt' ),
				'days'        => 30,
				'recursive'   => false,
				'enabled'     => false,
				'note'        => __( 'A folder several plugins use for their own logs. Check what is actually in yours before enabling this: a log is also the only record of what went wrong last week.', 'dahu-file-cleanup' ),
			),
		);

		/**
		 * Filter the presets offered on the rules screen.
		 *
		 * @param array<string,array<string,mixed>> $presets Presets.
		 */
		return (array) apply_filters( 'dahu_file_cleanup_presets', $presets );
	}
}
