<?php
/**
 * The one option, and what sanitising it means.
 *
 * Everything the plugin remembers lives in a single option: the rules, the
 * per-run ceiling, and whether uninstalling should take the settings with it.
 * One option means one read per request and one atomic write per save, and it
 * means the uninstall routine has one thing to delete.
 *
 * A fresh install has NO RULES. Activating this plugin therefore changes
 * nothing about the site: the scheduled event fires nightly over an empty
 * list. A plugin that deletes files has no business guessing which ones.
 *
 * @package DahuFileCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage.
 */
class Dahu_File_Cleanup_Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'dahu_file_cleanup_settings';

	/**
	 * Option group, for the Settings API.
	 */
	const GROUP = 'dahu_file_cleanup';

	/**
	 * Default ceiling on files deleted per rule per run.
	 */
	const DEFAULT_MAX_PER_RUN = 2000;

	/**
	 * Highest ceiling the screen accepts.
	 */
	const MAX_PER_RUN_LIMIT = 100000;

	/**
	 * Memoised settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {

		return array(
			'rules'                    => array(),
			'max_per_run'              => self::DEFAULT_MAX_PER_RUN,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Read the settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get() {

		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored   = get_option( self::OPTION, array() );
		$defaults = self::defaults();

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array(
			'rules'                    => array(),
			'max_per_run'              => isset( $stored['max_per_run'] ) ? self::clamp_max_per_run( $stored['max_per_run'] ) : $defaults['max_per_run'],
			'delete_data_on_uninstall' => ! empty( $stored['delete_data_on_uninstall'] ),
		);

		if ( isset( $stored['rules'] ) && is_array( $stored['rules'] ) ) {
			foreach ( $stored['rules'] as $key => $rule ) {
				$key = sanitize_key( (string) $key );

				if ( '' === $key ) {
					continue;
				}

				$settings['rules'][ $key ] = Dahu_File_Cleanup_Rules::normalise( $rule );
			}
		}

		self::$cache = $settings;

		return $settings;
	}

	/**
	 * Forget the memoised copy.
	 *
	 * @return void
	 */
	public static function flush() {

		self::$cache = null;
	}

	/**
	 * Write the settings.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	public static function save( $settings ) {

		update_option( self::OPTION, $settings );
		self::flush();
	}

	/**
	 * The rules, keyed.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function rules() {

		$settings = self::get();

		return $settings['rules'];
	}

	/**
	 * One rule, or null.
	 *
	 * @param string $key Rule key.
	 * @return array<string,mixed>|null
	 */
	public static function rule( $key ) {

		$rules = self::rules();
		$key   = sanitize_key( (string) $key );

		return isset( $rules[ $key ] ) ? $rules[ $key ] : null;
	}

	/**
	 * The rules that would run.
	 *
	 * Enabled is not enough: a rule can be enabled and then edited into
	 * something that cannot run, and the runner asks this rather than
	 * trusting the flag.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function runnable_rules() {

		$runnable = array();

		foreach ( self::rules() as $key => $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}

			if ( Dahu_File_Cleanup_Rules::blockers( $rule ) ) {
				continue;
			}

			$runnable[ $key ] = $rule;
		}

		return $runnable;
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public static function register() {

		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitise a submitted settings array.
	 *
	 * Rule keys are handled in two passes, and the reason is worth stating
	 * because getting it wrong loses data silently. A rule's key is what its
	 * log entries were written against and what a filter targets, so an
	 * existing rule keeps the key it was created with for ever, whatever its
	 * name becomes. Only a row that arrives without a key — a new rule — has
	 * one derived, and it is made unique against the keys that already
	 * exist, including the ones in this same submission.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {

		$defaults = self::defaults();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$output = array(
			'rules'                    => array(),
			'max_per_run'              => isset( $input['max_per_run'] ) ? self::clamp_max_per_run( $input['max_per_run'] ) : $defaults['max_per_run'],
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
		);

		$submitted = isset( $input['rules'] ) && is_array( $input['rules'] ) ? $input['rules'] : array();

		// --- Pass one: rows that already have a key keep it ---------------
		$new_rows = array();

		foreach ( $submitted as $submitted_key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( ! empty( $row['__deleted'] ) ) {
				continue;
			}

			$key = sanitize_key( (string) $submitted_key );

			// A row whose key is the new-row marker, or which lost its key
			// in transit, is a new rule and waits for pass two.
			if ( '' === $key || '__new' === $key || 0 === strpos( $key, '__new' ) ) {
				$new_rows[] = $row;
				continue;
			}

			$output['rules'][ $key ] = self::sanitize_rule( $row );
		}

		// --- Pass two: new rows get a key, unique against pass one --------
		foreach ( $new_rows as $row ) {
			$label = isset( $row['label'] ) ? (string) $row['label'] : '';

			if ( '' === trim( $label ) && empty( $row['paths'] ) ) {
				// An untouched empty row is not a rule.
				continue;
			}

			$key = Dahu_File_Cleanup_Rules::make_key( $label, array_keys( $output['rules'] ) );

			$output['rules'][ $key ] = self::sanitize_rule( $row );
		}

		// --- A rule that cannot run cannot be enabled ---------------------
		//
		// Refused rather than invented. The screen says which answer is
		// missing; an enabled flag on a rule with no retention would be a
		// promise the runner could not keep.
		foreach ( $output['rules'] as $key => $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}

			if ( Dahu_File_Cleanup_Rules::blockers( $rule ) ) {
				$output['rules'][ $key ]['enabled'] = false;
			}
		}

		self::flush();

		return $output;
	}

	/**
	 * Sanitise one submitted rule row.
	 *
	 * @param array<string,mixed> $row Submitted row.
	 * @return array<string,mixed>
	 */
	private static function sanitize_rule( $row ) {

		$extensions = Dahu_File_Cleanup_Rules::normalise_extensions( isset( $row['extensions'] ) ? $row['extensions'] : '' );

		if ( $extensions['refused'] ) {
			self::remember_refused_extensions( $extensions['refused'] );
		}

		return array(
			'label'      => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
			'paths'      => Dahu_File_Cleanup_Rules::normalise_paths( isset( $row['paths'] ) ? $row['paths'] : '' ),
			'extensions' => $extensions['extensions'],
			'days'       => Dahu_File_Cleanup_Rules::normalise_days( isset( $row['days'] ) ? $row['days'] : '' ),
			'recursive'  => ! empty( $row['recursive'] ),
			'enabled'    => ! empty( $row['enabled'] ),
		);
	}

	/**
	 * Queue a settings error naming each extension that was thrown out.
	 *
	 * Sanitising happens before the redirect, so add_settings_error() is the
	 * way to carry a message across it. Without this, a person who typed
	 * `pdf, php` would see "Settings saved" and a rule listing only `pdf`,
	 * with nothing anywhere to say why.
	 *
	 * @param array<string,string> $refused Extension => reason.
	 * @return void
	 */
	private static function remember_refused_extensions( $refused ) {

		foreach ( $refused as $extension => $reason ) {
			add_settings_error(
				self::OPTION,
				'dahu-file-cleanup-extension-' . sanitize_key( $extension ),
				sprintf(
					/* translators: 1: file extension, 2: reason it was refused. */
					__( 'Extension “%1$s” was not kept: %2$s', 'dahu-file-cleanup' ),
					$extension,
					$reason
				),
				'error'
			);
		}
	}

	/**
	 * Clamp the per-run ceiling.
	 *
	 * @param mixed $raw Submitted value.
	 * @return int
	 */
	public static function clamp_max_per_run( $raw ) {

		$value = (int) $raw;

		if ( $value < 1 ) {
			return self::DEFAULT_MAX_PER_RUN;
		}

		return min( self::MAX_PER_RUN_LIMIT, $value );
	}
}
