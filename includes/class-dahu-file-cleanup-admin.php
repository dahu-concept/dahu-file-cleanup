<?php
/**
 * The rules screen, under Tools.
 *
 * Under Tools rather than Settings because this is a thing you do, repeatedly,
 * and come back to look at — the dry run and the log are half the screen. And
 * not under WooCommerce: nothing in this plugin knows what an order is.
 *
 * The screen has one job beyond editing rules, and it is the dry run. A rule
 * is a sentence about deleting files on a server, written by someone who
 * cannot see the server; the only honest way to let them switch one on is to
 * show them, first, exactly which files it picks, where those files resolve
 * to, and how many were looked at before the filters ran. That is why the
 * preview reports counts before filtering as well as after: an empty preview
 * has several causes, and guessing between them over somebody's invoices is
 * not acceptable.
 *
 * @package DahuFileCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen.
 */
class Dahu_File_Cleanup_Admin {

	/**
	 * admin_post action that deletes a rule.
	 */
	const DELETE_ACTION = 'dahu_file_cleanup_delete_rule';

	/**
	 * admin_post action that adds a preset.
	 */
	const PRESET_ACTION = 'dahu_file_cleanup_add_preset';

	/**
	 * Capability for everything on this screen.
	 *
	 * manage_options, not manage_woocommerce: this deletes files from the
	 * server's filesystem, which is not a shop manager's decision.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Dry-run result for this request, if one was asked for.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $preview = null;

	/**
	 * Result of a manual run, if one happened.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $summary = null;

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {

		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( 'Dahu_File_Cleanup_Settings', 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_screen_actions' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_' . self::PRESET_ACTION, array( __CLASS__, 'handle_preset' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Add the Tools page.
	 *
	 * @return void
	 */
	public static function add_page() {

		add_management_page(
			__( 'File cleanup', 'dahu-file-cleanup' ),
			__( 'File cleanup', 'dahu-file-cleanup' ),
			self::CAPABILITY,
			DAHU_FILE_CLEANUP_PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the stylesheet, on this screen only.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {

		if ( 'tools_page_' . DAHU_FILE_CLEANUP_PAGE !== $hook ) {
			return;
		}

		// The family sheet first, and its own second: the plugin's own rules
		// are written to sit on top of the shared components, so the order is
		// a dependency and not a hope. One handle across the family -- the
		// file is byte-identical everywhere, so whichever plugin loads it
		// first serves all of them, and a shop with six of these installed
		// downloads one copy.
		wp_enqueue_style(
			'dahu-ui',
			DAHU_FILE_CLEANUP_URL . 'assets/css/dahu-ui.css',
			array(),
			DAHU_FILE_CLEANUP_VERSION
		);

		wp_enqueue_style(
			'dahu-file-cleanup-admin',
			DAHU_FILE_CLEANUP_URL . 'assets/css/admin.css',
			array( 'dahu-ui' ),
			DAHU_FILE_CLEANUP_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * Actions posted to the screen itself
	 * ------------------------------------------------------------------ */

	/**
	 * Handle the dry run, the manual run and clearing the log.
	 *
	 * These post back to the screen rather than to admin-post.php, because
	 * each produces output the screen then prints. Each has its own nonce:
	 * one nonce for three buttons of which one deletes files would make the
	 * cheapest of them as dangerous as the most expensive.
	 *
	 * @return void
	 */
	public static function handle_screen_actions() {

		if ( ! isset( $_POST['dahu_file_cleanup_action'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['dahu_file_cleanup_action'] ) );

		if ( 'preview' === $action ) {
			check_admin_referer( 'dahu_file_cleanup_preview' );
			self::$preview = self::build_preview();

			return;
		}

		if ( 'run' === $action ) {
			check_admin_referer( 'dahu_file_cleanup_run' );
			self::$summary = Dahu_File_Cleanup_Runner::run( 'manual' );

			return;
		}

		if ( 'clear-log' === $action ) {
			check_admin_referer( 'dahu_file_cleanup_clear_log' );
			Dahu_File_Cleanup_Log::clear();
		}
	}

	/**
	 * Dry-run every rule, enabled or not.
	 *
	 * Disabled rules are included on purpose: the point of a dry run is to
	 * check a rule before switching it on.
	 *
	 * @return array<string,mixed>
	 */
	private static function build_preview() {

		$preview = array();

		foreach ( Dahu_File_Cleanup_Settings::rules() as $key => $rule ) {
			$preview[ $key ] = Dahu_File_Cleanup_Scanner::preview( $rule );

			$preview[ $key ]['label']     = $rule['label'];
			$preview[ $key ]['enabled']   = ! empty( $rule['enabled'] );
			$preview[ $key ]['recursive'] = ! empty( $rule['recursive'] );
			$preview[ $key ]['blockers']  = Dahu_File_Cleanup_Rules::blockers( $rule );
		}

		return $preview;
	}

	/**
	 * Delete one rule.
	 *
	 * @return void
	 */
	public static function handle_delete() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these rules.', 'dahu-file-cleanup' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::DELETE_ACTION );

		$key      = isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : '';
		$settings = Dahu_File_Cleanup_Settings::get();
		$deleted  = false;

		if ( '' !== $key && isset( $settings['rules'][ $key ] ) ) {
			unset( $settings['rules'][ $key ] );
			Dahu_File_Cleanup_Settings::save( $settings );
			$deleted = true;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                    => DAHU_FILE_CLEANUP_PAGE,
					'dahu-file-cleanup-notice' => $deleted ? 'rule-deleted' : 'rule-missing',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Add a preset as a new rule.
	 *
	 * @return void
	 */
	public static function handle_preset() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these rules.', 'dahu-file-cleanup' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::PRESET_ACTION );

		$wanted  = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : '';
		$presets = Dahu_File_Cleanup_Rules::presets();
		$notice  = 'preset-missing';

		if ( isset( $presets[ $wanted ] ) ) {
			$settings = Dahu_File_Cleanup_Settings::get();
			$preset   = $presets[ $wanted ];

			unset( $preset['note'] );

			$key = Dahu_File_Cleanup_Rules::make_key(
				isset( $preset['label'] ) ? $preset['label'] : $wanted,
				array_keys( $settings['rules'] )
			);

			$settings['rules'][ $key ] = Dahu_File_Cleanup_Rules::normalise( $preset );

			// Belt and braces: a preset arrives switched off whatever it
			// says, so that adding one can never start deleting files.
			$settings['rules'][ $key ]['enabled'] = false;

			Dahu_File_Cleanup_Settings::save( $settings );
			$notice = 'preset-added';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                     => DAHU_FILE_CLEANUP_PAGE,
					'dahu-file-cleanup-notice' => $notice,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Print the screen.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = Dahu_File_Cleanup_Settings::get();
		$rules    = $settings['rules'];

		echo '<div class="wrap dahu-ui dahu-file-cleanup">';

		echo '<div class="dahu-ui-head">';
		printf( '<h1 class="dahu-ui-head__title">%s</h1>', esc_html__( 'File cleanup', 'dahu-file-cleanup' ) );
		printf( '<p class="dahu-ui-head__sub">%s</p>', esc_html__( 'Finds uploaded files no longer attached to anything.', 'dahu-file-cleanup' ) );
		echo '</div>';

		// WordPress moves every admin notice to just after the first heading
		// of the wrap, unless the screen says where it wants them. Without
		// this marker, the one sentence that says what this screen is ends up
		// below whatever any other plugin had to announce today — and on a
		// shop running half a dozen of them, below the fold.
		echo '<hr class="wp-header-end">';

		self::render_notices();
		self::render_standing_warning();
		self::render_cron_warning();
		self::render_status( $settings, $rules );

		settings_errors( Dahu_File_Cleanup_Settings::OPTION );

		self::render_form( $settings, $rules );
		self::render_delete_forms( $rules );
		self::render_presets();
		self::render_actions( $rules );
		self::render_preview();
		self::render_summary();
		self::render_log();

		echo '</div>';
	}

	/**
	 * One-off notices after a redirect.
	 *
	 * @return void
	 */
	private static function render_notices() {

		if ( ! isset( $_GET['dahu-file-cleanup-notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['dahu-file-cleanup-notice'] ) );

		$messages = array(
			'rule-deleted'   => array( 'success', __( 'Rule deleted. No files were touched: deleting a rule only removes the rule.', 'dahu-file-cleanup' ) ),
			'rule-missing'   => array( 'warning', __( 'That rule no longer exists.', 'dahu-file-cleanup' ) ),
			'preset-added'   => array( 'success', __( 'Rule added, switched off. Check its folders with the dry run, set a retention, then enable it.', 'dahu-file-cleanup' ) ),
			'preset-missing' => array( 'warning', __( 'That ready-made rule is not available.', 'dahu-file-cleanup' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * The warning that is always on the screen.
	 *
	 * The plugin this one grew out of carried a per-category "these may be
	 * legally required records" flag, set by hand for the two categories
	 * that held invoices. With rules somebody else writes, about folders
	 * nobody here has seen, there is no honest way to decide which rule
	 * deserves the flag — so every rule gets it, once, at the top.
	 *
	 * @return void
	 */
	private static function render_standing_warning() {

		printf(
			'<div class="notice notice-warning dahu-file-cleanup__standing"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Deleted files are gone.', 'dahu-file-cleanup' ),
			esc_html__( 'There is no undo and no trash. Generated files are often business records as well: invoices, delivery notes, quotes and accounting exports have minimum retention periods in most countries, and a backup archive is sometimes the only copy of something. Use the dry run, and set a retention you can defend.', 'dahu-file-cleanup' )
		);
	}

	/**
	 * Say so when WP-Cron cannot fire on its own.
	 *
	 * @return void
	 */
	private static function render_cron_warning() {

		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p><p><code>wp file-cleanup run --execute</code></p></div>',
			esc_html__( 'WP-Cron is disabled on this site.', 'dahu-file-cleanup' ),
			esc_html__( 'The daily run only happens if a real server cron calls wp-cron.php. If it does not, use the button below, or put this command on a schedule:', 'dahu-file-cleanup' )
		);
	}

	/**
	 * The state of things, as label and value pairs.
	 *
	 * @param array<string,mixed>               $settings Settings.
	 * @param array<string,array<string,mixed>> $rules    Rules.
	 * @return void
	 */
	private static function render_status( $settings, $rules ) {

		$enabled = count( Dahu_File_Cleanup_Settings::runnable_rules() );
		$next    = wp_next_scheduled( DAHU_FILE_CLEANUP_CRON );
		$totals  = Dahu_File_Cleanup_Log::totals();

		$lines = array(
			__( 'Rules', 'dahu-file-cleanup' )              => sprintf(
				/* translators: 1: total rules, 2: how many would run. */
				_n( '%1$s rule, %2$s of them ready to run', '%1$s rules, %2$s of them ready to run', count( $rules ), 'dahu-file-cleanup' ),
				number_format_i18n( count( $rules ) ),
				number_format_i18n( $enabled )
			),
			__( 'Next scheduled run', 'dahu-file-cleanup' ) => $next
				? wp_date( 'j F Y, H:i', $next )
				: __( 'not scheduled — it will be rescheduled on the next admin page load', 'dahu-file-cleanup' ),
			__( 'Ceiling', 'dahu-file-cleanup' )            => sprintf(
				/* translators: %s: number of files. */
				__( '%s files per rule per run', 'dahu-file-cleanup' ),
				number_format_i18n( (int) $settings['max_per_run'] )
			),
			__( 'In the log', 'dahu-file-cleanup' )         => sprintf(
				/* translators: 1: number of files, 2: formatted size. */
				__( '%1$s files deleted, %2$s freed', 'dahu-file-cleanup' ),
				number_format_i18n( $totals['files'] ),
				size_format( $totals['bytes'] )
			),
		);

		echo '<div class="dahu-ui-card"><table class="widefat dahu-file-cleanup__status"><tbody>';

		foreach ( $lines as $label => $value ) {
			printf(
				'<tr><th scope="row">%1$s</th><td class="dahu-file-cleanup__num">%2$s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * The settings form: every rule, plus one empty row.
	 *
	 * @param array<string,mixed>               $settings Settings.
	 * @param array<string,array<string,mixed>> $rules    Rules.
	 * @return void
	 */
	private static function render_form( $settings, $rules ) {

		$option = Dahu_File_Cleanup_Settings::OPTION;

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'options.php' ) ) );

		settings_fields( Dahu_File_Cleanup_Settings::GROUP );

		printf( '<h2>%s</h2>', esc_html__( 'Rules', 'dahu-file-cleanup' ) );

		if ( ! $rules ) {
			printf(
				'<p class="dahu-ui-hint">%s</p>',
				esc_html__( 'No rules yet, so nothing is deleted. Fill in the empty rule below, or add a ready-made one.', 'dahu-file-cleanup' )
			);
		}

		foreach ( $rules as $key => $rule ) {
			self::render_rule( $option, $key, $rule );
		}

		self::render_rule( $option, '__new', Dahu_File_Cleanup_Rules::blank(), true );

		printf( '<div class="dahu-ui-card"><div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title">%s</h2></div>', esc_html__( 'Limits', 'dahu-file-cleanup' ) );

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="dahu-file-cleanup-ceiling">%1$s</label></th><td><input type="number" min="1" max="%2$s" step="1" class="small-text" id="dahu-file-cleanup-ceiling" name="%3$s[max_per_run]" value="%4$s"> <span class="dahu-file-cleanup__muted">%5$s</span><p class="description">%6$s</p></td></tr>',
			esc_html__( 'Files per rule per run', 'dahu-file-cleanup' ),
			esc_attr( Dahu_File_Cleanup_Settings::MAX_PER_RUN_LIMIT ),
			esc_attr( $option ),
			esc_attr( (string) $settings['max_per_run'] ),
			esc_html__( 'files', 'dahu-file-cleanup' ),
			esc_html__( 'A run stops a rule once it has deleted this many files, and says so in the log. It also stops a rule that has looked at far more files than this without finding that many to delete, so a folder full of files none of which are old enough costs a bounded walk rather than the whole folder. It is there for the day a folder is widened by one line and suddenly matches forty thousand files: a run that stops and reports is recoverable, a run that does not is not. The remainder goes on the next run.', 'dahu-file-cleanup' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[delete_data_on_uninstall]" value="1" %3$s> %4$s</label><p class="description">%5$s</p></td></tr>',
			esc_html__( 'On uninstall', 'dahu-file-cleanup' ),
			esc_attr( $option ),
			checked( ! empty( $settings['delete_data_on_uninstall'] ), true, false ),
			esc_html__( 'Also delete the rules and the log', 'dahu-file-cleanup' ),
			esc_html__( 'Off by default, because uninstalling is often a step in reinstalling, and the log is the record of what this plugin deleted. Files on disk are never touched by an uninstall either way.', 'dahu-file-cleanup' )
		);

		echo '</tbody></table></div>';

		submit_button( __( 'Save rules', 'dahu-file-cleanup' ) );

		echo '</form>';
	}

	/**
	 * One rule, as a panel.
	 *
	 * @param string              $option Option name.
	 * @param string              $key    Rule key, or '__new'.
	 * @param array<string,mixed> $rule   Rule.
	 * @param bool                $is_new Whether this is the empty row.
	 * @return void
	 */
	private static function render_rule( $option, $key, $rule, $is_new = false ) {

		$blockers = $is_new ? array() : Dahu_File_Cleanup_Rules::blockers( $rule );
		$name     = sprintf( '%s[rules][%s]', $option, $key );
		$id       = 'dahu-file-cleanup-' . sanitize_html_class( $key );

		echo '<div class="dahu-ui-card dahu-file-cleanup__panel">';

		echo '<div class="dahu-file-cleanup__panel-header">';

		printf(
			'<span class="dahu-file-cleanup__panel-title">%s</span>',
			esc_html(
				$is_new
					? __( 'New rule', 'dahu-file-cleanup' )
					: ( '' !== $rule['label'] ? $rule['label'] : __( '(unnamed)', 'dahu-file-cleanup' ) )
			)
		);

		if ( ! $is_new ) {
			printf(
				'<span class="dahu-file-cleanup__panel-state">%s</span>',
				esc_html(
					$blockers
						? __( 'incomplete', 'dahu-file-cleanup' )
						: ( ! empty( $rule['enabled'] ) ? __( 'on', 'dahu-file-cleanup' ) : __( 'off', 'dahu-file-cleanup' ) )
				)
			);

			printf(
				'<button type="submit" form="dahu-file-cleanup-delete-%1$s" class="button-link dahu-file-cleanup__delete">%2$s</button>',
				esc_attr( sanitize_html_class( $key ) ),
				esc_html__( 'Delete rule', 'dahu-file-cleanup' )
			);
		}

		echo '</div>';

		if ( $blockers ) {
			echo '<ul class="dahu-file-cleanup__blockers">';

			foreach ( $blockers as $blocker ) {
				printf( '<li>%s</li>', esc_html( $blocker ) );
			}

			echo '</ul>';
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="%1$s-label">%2$s</label></th><td><input type="text" class="regular-text" id="%1$s-label" name="%3$s[label]" value="%4$s"><p class="description">%5$s</p></td></tr>',
			esc_attr( $id ),
			esc_html__( 'Name', 'dahu-file-cleanup' ),
			esc_attr( $name ),
			esc_attr( $rule['label'] ),
			esc_html__( 'Shown in the log and on this screen. Renaming a rule keeps its history: the name is a label, not the identifier.', 'dahu-file-cleanup' )
		);

		printf(
			'<tr><th scope="row"><label for="%1$s-paths">%2$s</label></th><td><textarea class="large-text code" rows="3" id="%1$s-paths" name="%3$s[paths]">%4$s</textarea><p class="description">%5$s</p></td></tr>',
			esc_attr( $id ),
			esc_html__( 'Folders', 'dahu-file-cleanup' ),
			esc_attr( $name ),
			esc_textarea( $rule['paths'] ),
			esc_html__( 'One per line, relative to the WordPress root. No wildcards. The root folders of the site, the uploads root, and anything inside the plugin, theme, language or update folders are refused — point at a specific subfolder.', 'dahu-file-cleanup' )
		);

		printf(
			'<tr><th scope="row"><label for="%1$s-extensions">%2$s</label></th><td><input type="text" class="regular-text" id="%1$s-extensions" name="%3$s[extensions]" value="%4$s" placeholder="%5$s"><p class="description">%6$s</p></td></tr>',
			esc_attr( $id ),
			esc_html__( 'File extensions', 'dahu-file-cleanup' ),
			esc_attr( $name ),
			esc_attr( implode( ', ', $rule['extensions'] ) ),
			esc_attr__( 'pdf, zip, log', 'dahu-file-cleanup' ),
			esc_html__( 'Separated by commas. This is the whole of the selection — a rule with no extensions matches nothing, and there is no way to say "everything". Code, stylesheet, configuration and translation extensions are refused.', 'dahu-file-cleanup' )
		);

		printf(
			'<tr><th scope="row"><label for="%1$s-days">%2$s</label></th><td><input type="number" min="1" step="1" class="small-text" id="%1$s-days" name="%3$s[days]" value="%4$s"> <span class="dahu-file-cleanup__muted">%5$s</span><p class="description">%6$s</p></td></tr>',
			esc_attr( $id ),
			esc_html__( 'Delete files older than', 'dahu-file-cleanup' ),
			esc_attr( $name ),
			esc_attr( (string) $rule['days'] ),
			esc_html__( 'days', 'dahu-file-cleanup' ),
			esc_html__( 'Measured on the file modification time. Leave it empty and the rule cannot be switched on: an empty field is not a way of saying zero.', 'dahu-file-cleanup' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[recursive]" value="1" %3$s> %4$s</label><p class="description">%5$s</p></td></tr>',
			esc_html__( 'Subfolders', 'dahu-file-cleanup' ),
			esc_attr( $name ),
			checked( ! empty( $rule['recursive'] ), true, false ),
			esc_html__( 'Also look in subfolders', 'dahu-file-cleanup' ),
			esc_html__( 'Off means the listed folders only. Symbolic links are never followed, in either mode, and never deleted.', 'dahu-file-cleanup' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[enabled]" value="1" %3$s> %4$s</label></td></tr>',
			esc_html__( 'Enabled', 'dahu-file-cleanup' ),
			esc_attr( $name ),
			checked( ! empty( $rule['enabled'] ), true, false ),
			esc_html__( 'Include this rule in the daily run', 'dahu-file-cleanup' )
		);

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * The hidden forms the delete links submit.
	 *
	 * A rule's delete control is a link, because a table of rules each
	 * carrying a framed button is a wall of buttons and nothing on it reads
	 * as more important than anything else. A link that posts needs a form,
	 * and a form cannot be nested inside the settings form — hence these,
	 * printed after it and bound by the button's form attribute.
	 *
	 * @param array<string,array<string,mixed>> $rules Rules.
	 * @return void
	 */
	private static function render_delete_forms( $rules ) {

		foreach ( $rules as $key => $rule ) {
			printf(
				'<form id="dahu-file-cleanup-delete-%1$s" method="post" action="%2$s" class="dahu-file-cleanup__delete-form">',
				esc_attr( sanitize_html_class( $key ) ),
				esc_url( admin_url( 'admin-post.php' ) )
			);

			wp_nonce_field( self::DELETE_ACTION );

			printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::DELETE_ACTION ) );
			printf( '<input type="hidden" name="rule" value="%s">', esc_attr( $key ) );

			echo '</form>';
		}
	}

	/**
	 * The ready-made rules.
	 *
	 * @return void
	 */
	private static function render_presets() {

		$presets = Dahu_File_Cleanup_Rules::presets();

		if ( ! $presets ) {
			return;
		}

		printf( '<div class="dahu-ui-card"><div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title">%s</h2></div>', esc_html__( 'Ready-made rules', 'dahu-file-cleanup' ) );

		printf(
			'<p class="dahu-file-cleanup__muted">%s</p>',
			esc_html__( 'The default folders of plugins known to leave files behind. Added switched off, with the folder editable: a site that moved the folder, or does not have that plugin, sees the dry run say so.', 'dahu-file-cleanup' )
		);

		printf(
			'<form method="post" action="%s" class="dahu-file-cleanup__presets">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( self::PRESET_ACTION );

		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::PRESET_ACTION ) );

		echo '<select name="preset">';

		foreach ( $presets as $slug => $preset ) {
			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( $slug ),
				esc_html( isset( $preset['label'] ) ? $preset['label'] : $slug )
			);
		}

		echo '</select> ';

		printf(
			'<button type="submit" class="dahu-ui-btn">%s</button>',
			esc_html__( 'Add as a new rule', 'dahu-file-cleanup' )
		);

		echo '</form>';

		echo '<ul class="dahu-file-cleanup__preset-notes">';

		foreach ( $presets as $preset ) {
			if ( empty( $preset['note'] ) ) {
				continue;
			}

			printf(
				'<li><strong>%1$s</strong> %2$s</li>',
				esc_html( isset( $preset['label'] ) ? $preset['label'] : '' ),
				esc_html( $preset['note'] )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * The dry-run and run-now buttons.
	 *
	 * @param array<string,array<string,mixed>> $rules Rules.
	 * @return void
	 */
	private static function render_actions( $rules ) {

		printf( '<div class="dahu-ui-card"><div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title">%s</h2></div>', esc_html__( 'Actions', 'dahu-file-cleanup' ) );

		echo '<div class="dahu-file-cleanup__actions">';

		printf( '<form method="post" action="%s">', esc_url( self::screen_url() ) );
		wp_nonce_field( 'dahu_file_cleanup_preview' );
		echo '<input type="hidden" name="dahu_file_cleanup_action" value="preview">';
		printf(
			'<button type="submit" class="dahu-ui-btn dahu-ui-btn--go">%s</button>',
			esc_html__( 'Dry run — deletes nothing', 'dahu-file-cleanup' )
		);
		echo '</form>';

		if ( $rules ) {
			printf( '<form method="post" action="%s">', esc_url( self::screen_url() ) );
			wp_nonce_field( 'dahu_file_cleanup_run' );
			echo '<input type="hidden" name="dahu_file_cleanup_action" value="run">';
			printf(
				'<button type="submit" class="dahu-ui-btn">%s</button>',
				esc_html__( 'Run the enabled rules now', 'dahu-file-cleanup' )
			);
			echo '</form>';
		}

		echo '</div>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The dry run covers every rule, switched on or not. Running now covers only the rules that are on and complete, exactly as the daily run does.', 'dahu-file-cleanup' )
		);

		echo '</div>';
	}

	/**
	 * The dry-run result.
	 *
	 * @return void
	 */
	private static function render_preview() {

		if ( null === self::$preview ) {
			return;
		}

		printf( '<div class="dahu-ui-card"><div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title">%s</h2></div>', esc_html__( 'Dry run — nothing was deleted', 'dahu-file-cleanup' ) );

		if ( ! self::$preview ) {
			printf( '<p class="dahu-file-cleanup__muted">%s</p>', esc_html__( 'There are no rules to run.', 'dahu-file-cleanup' ) );
			echo '</div>';

			return;
		}

		foreach ( self::$preview as $key => $block ) {
			echo '<div class="dahu-file-cleanup__preview">';

			printf(
				'<h3>%1$s <span class="dahu-file-cleanup__panel-state">%2$s</span></h3>',
				esc_html( '' !== $block['label'] ? $block['label'] : $key ),
				esc_html(
					$block['blockers']
						? __( 'incomplete — would not run', 'dahu-file-cleanup' )
						: ( $block['enabled'] ? __( 'on', 'dahu-file-cleanup' ) : __( 'off — would not run', 'dahu-file-cleanup' ) )
				)
			);

			foreach ( $block['blockers'] as $blocker ) {
				printf( '<p class="dahu-file-cleanup__warn">%s</p>', esc_html( $blocker ) );
			}

			// One sentence when the scan saw the whole folder, two when it
			// stopped short — the second saying so, because a figure that is
			// only a floor read as a total is a decision made on a false
			// number. The wording is the scanner's, so every surface that
			// reports a dry run says the same thing.
			$headline = Dahu_File_Cleanup_Scanner::preview_headline( $block );

			printf(
				'<p class="dahu-file-cleanup__num"><strong>%1$s</strong></p>',
				esc_html( array_shift( $headline ) )
			);

			foreach ( $headline as $sentence ) {
				printf( '<p class="dahu-file-cleanup__warn">%s</p>', esc_html( $sentence ) );
			}

			self::render_diagnostics( $block['diagnostics'] );

			if ( $block['count'] && $block['recursive'] ) {
				self::render_subfolders( $block );
			}

			if ( $block['files'] ) {
				self::render_preview_files( $block );
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * The per-folder diagnostic table.
	 *
	 * This table is the reason the dry run can be trusted. An empty preview
	 * has at least four causes — the folder is not where you think, it holds
	 * no file with that extension, every file is newer than the retention,
	 * or the folder cannot be read — and they are indistinguishable from the
	 * outside. So each is counted separately and the resolved absolute path
	 * is printed, because "wp-content/uploads/logs" on a site with a
	 * symlinked uploads folder is not the folder you pictured.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return void
	 */
	private static function render_diagnostics( $diagnostics ) {

		if ( ! $diagnostics ) {
			return;
		}

		echo '<table class="widefat striped dahu-file-cleanup__diagnostics"><thead><tr>';

		$headings = array(
			__( 'Folder as typed', 'dahu-file-cleanup' ),
			__( 'Resolved to', 'dahu-file-cleanup' ),
			__( 'Files', 'dahu-file-cleanup' ),
			__( 'Right extension', 'dahu-file-cleanup' ),
			__( 'Oldest', 'dahu-file-cleanup' ),
			__( 'Newest', 'dahu-file-cleanup' ),
			__( 'Selected', 'dahu-file-cleanup' ),
		);

		foreach ( $headings as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}

		echo '</tr></thead><tbody>';

		foreach ( $diagnostics as $diagnostic ) {
			echo '<tr>';

			printf( '<td><code class="dahu-file-cleanup__path">%s</code></td>', esc_html( $diagnostic['line'] ) );

			if ( $diagnostic['ok'] ) {
				printf(
					'<td><code class="dahu-file-cleanup__path">%1$s</code>%2$s</td>',
					esc_html( $diagnostic['resolved'] ),
					$diagnostic['reason'] ? '<br><span class="dahu-file-cleanup__warn">' . esc_html( $diagnostic['reason'] ) . '</span>' : ''
				);
			} else {
				printf( '<td><span class="dahu-file-cleanup__warn">%s</span></td>', esc_html( $diagnostic['reason'] ) );
			}

			printf(
				'<td class="dahu-file-cleanup__num">%1$s%2$s</td>',
				esc_html( number_format_i18n( $diagnostic['files'] ) ),
				$diagnostic['symlinks']
					? '<br><span class="dahu-file-cleanup__muted">' . esc_html(
						sprintf(
							/* translators: %s: number of symbolic links. */
							_n( '%s link skipped', '%s links skipped', $diagnostic['symlinks'], 'dahu-file-cleanup' ),
							number_format_i18n( $diagnostic['symlinks'] )
						)
					) . '</span>'
					: ''
			);

			printf( '<td class="dahu-file-cleanup__num">%s</td>', esc_html( number_format_i18n( $diagnostic['matching'] ) ) );
			printf( '<td class="dahu-file-cleanup__num">%s</td>', esc_html( null === $diagnostic['oldest'] ? '—' : wp_date( 'Y-m-d H:i', $diagnostic['oldest'] ) ) );
			printf( '<td class="dahu-file-cleanup__num">%s</td>', esc_html( null === $diagnostic['newest'] ? '—' : wp_date( 'Y-m-d H:i', $diagnostic['newest'] ) ) );
			printf( '<td class="dahu-file-cleanup__num">%s</td>', esc_html( number_format_i18n( $diagnostic['selected'] ) ) );

			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( Dahu_File_Cleanup_Scanner::looks_like_reset_timestamps( $diagnostics ) ) {
			printf(
				'<p class="dahu-file-cleanup__warn">%s</p>',
				esc_html__( 'Worth a look: plenty of files carry an extension this rule selects, and not one of them is old enough. If that folder is supposed to hold old files, their modification times have probably been reset by a clone or a restore of this environment — in which case no retention can find anything old here, however old the files really are elsewhere.', 'dahu-file-cleanup' )
			);
		}
	}

	/**
	 * Counts per subfolder, for a recursive rule.
	 *
	 * @param array<string,mixed> $block Preview block.
	 * @return void
	 */
	private static function render_subfolders( $block ) {

		$folders = Dahu_File_Cleanup_Scanner::by_subfolder( $block['files'] );

		if ( count( $folders ) < 2 ) {
			return;
		}

		printf(
			'<h4>%s</h4>',
			esc_html(
				( $block['truncated'] || empty( $block['complete'] ) )
					? __( 'By subfolder, over the files listed below only', 'dahu-file-cleanup' )
					: __( 'By subfolder', 'dahu-file-cleanup' )
			)
		);

		echo '<table class="widefat striped dahu-file-cleanup__subfolders"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Subfolder', 'dahu-file-cleanup' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Files', 'dahu-file-cleanup' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Size', 'dahu-file-cleanup' ) );
		echo '</tr></thead><tbody>';

		foreach ( $folders as $folder => $totals ) {
			printf(
				'<tr><td><code class="dahu-file-cleanup__path">%1$s</code></td><td class="dahu-file-cleanup__num">%2$s</td><td class="dahu-file-cleanup__num">%3$s</td></tr>',
				esc_html( $folder ),
				esc_html( number_format_i18n( $totals['count'] ) ),
				esc_html( size_format( $totals['bytes'] ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The file list of a dry run.
	 *
	 * @param array<string,mixed> $block Preview block.
	 * @return void
	 */
	private static function render_preview_files( $block ) {

		echo '<table class="widefat striped dahu-file-cleanup__files"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'File', 'dahu-file-cleanup' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Size', 'dahu-file-cleanup' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Last modified', 'dahu-file-cleanup' ) );
		echo '</tr></thead><tbody>';

		foreach ( $block['files'] as $file ) {
			printf(
				'<tr><td><code class="dahu-file-cleanup__path">%1$s</code></td><td class="dahu-file-cleanup__num">%2$s</td><td class="dahu-file-cleanup__num">%3$s</td></tr>',
				esc_html( Dahu_File_Cleanup_Paths::shorten( $file['path'] ) ),
				esc_html( size_format( $file['size'] ) ),
				esc_html( wp_date( 'Y-m-d H:i', $file['mtime'] ) )
			);
		}

		if ( $block['truncated'] ) {
			printf(
				'<tr><td colspan="3" class="dahu-file-cleanup__muted">%s</td></tr>',
				esc_html(
					sprintf(
						/* translators: %s: number of further files. */
						__( '… and %s more, not listed', 'dahu-file-cleanup' ),
						number_format_i18n( $block['count'] - count( $block['files'] ) )
					)
				)
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * What a manual run did.
	 *
	 * @return void
	 */
	private static function render_summary() {

		if ( null === self::$summary ) {
			return;
		}

		printf( '<div class="dahu-ui-card"><div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title">%s</h2></div>', esc_html__( 'Manual run', 'dahu-file-cleanup' ) );

		if ( isset( self::$summary['locked'] ) ) {
			printf(
				'<p class="dahu-file-cleanup__warn">%s</p>',
				esc_html__( 'Another run was already in progress, so nothing was done. Try again in a few minutes.', 'dahu-file-cleanup' )
			);
			echo '</div>';

			return;
		}

		if ( ! self::$summary ) {
			printf(
				'<p class="dahu-file-cleanup__muted">%s</p>',
				esc_html__( 'No rule is both switched on and complete, so nothing was deleted.', 'dahu-file-cleanup' )
			);
			echo '</div>';

			return;
		}

		echo '<table class="dahu-ui-table"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Rule', 'dahu-file-cleanup' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Deleted', 'dahu-file-cleanup' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Freed', 'dahu-file-cleanup' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Notes', 'dahu-file-cleanup' ) );
		echo '</tr></thead><tbody>';

		foreach ( self::$summary as $result ) {
			$notes = array();

			if ( $result['failed'] ) {
				$notes[] = sprintf(
					/* translators: %s: number of files. */
					_n( '%s file could not be deleted', '%s files could not be deleted', $result['failed'], 'dahu-file-cleanup' ),
					number_format_i18n( $result['failed'] )
				);
			}

			if ( $result['capped'] ) {
				$notes[] = 'work' === $result['capped_reason']
					? __( 'this rule looked at as many files as its ceiling allows without finding that many to delete, and stopped; the rest goes on the next run', 'dahu-file-cleanup' )
					: __( 'the ceiling was reached; the rest goes on the next run', 'dahu-file-cleanup' );
			}

			printf(
				'<tr><td>%1$s</td><td class="dahu-file-cleanup__num">%2$s</td><td class="dahu-file-cleanup__num">%3$s</td><td>%4$s</td></tr>',
				esc_html( $result['label'] ),
				esc_html( number_format_i18n( $result['deleted'] ) ),
				esc_html( size_format( $result['bytes'] ) ),
				esc_html( implode( ' — ', $notes ) )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * The log.
	 *
	 * @return void
	 */
	private static function render_log() {

		$rows  = Dahu_File_Cleanup_Log::all();
		$shown = array_slice( $rows, 0, 50 );

		printf( '<div class="dahu-ui-card"><div class="dahu-ui-card__head"><h2 class="dahu-ui-card__title">%s</h2></div>', esc_html__( 'Log', 'dahu-file-cleanup' ) );

		if ( ! $rows ) {
			printf(
				'<p class="dahu-ui-hint">%s</p>',
				esc_html__( 'Nothing has run yet. A row is written for every rule on every run, including runs that deleted nothing.', 'dahu-file-cleanup' )
			);
			echo '</div>';

			return;
		}

		$modes = array(
			'scheduled' => __( 'scheduled', 'dahu-file-cleanup' ),
			'manual'    => __( 'manual', 'dahu-file-cleanup' ),
			'cli'       => __( 'WP-CLI', 'dahu-file-cleanup' ),
		);

		echo '<table class="widefat striped dahu-file-cleanup__log"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'When', 'dahu-file-cleanup' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Started by', 'dahu-file-cleanup' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Rule', 'dahu-file-cleanup' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Deleted', 'dahu-file-cleanup' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Freed', 'dahu-file-cleanup' ) );
		echo '</tr></thead><tbody>';

		foreach ( $shown as $row ) {
			$mode = isset( $row['mode'] ) && isset( $modes[ $row['mode'] ] ) ? $modes[ $row['mode'] ] : (string) ( isset( $row['mode'] ) ? $row['mode'] : '' );

			printf(
				'<tr><td class="dahu-file-cleanup__num">%1$s</td><td>%2$s</td><td>%3$s</td><td class="dahu-file-cleanup__num">%4$s%5$s</td><td class="dahu-file-cleanup__num">%6$s</td></tr>',
				esc_html( wp_date( 'Y-m-d H:i', (int) ( isset( $row['time'] ) ? $row['time'] : 0 ) ) ),
				esc_html( $mode ),
				esc_html( (string) ( isset( $row['label'] ) && '' !== $row['label'] ? $row['label'] : ( isset( $row['rule'] ) ? $row['rule'] : '' ) ) ),
				esc_html( number_format_i18n( (int) ( isset( $row['files'] ) ? $row['files'] : 0 ) ) ),
				empty( $row['capped'] ) ? '' : ' <span class="dahu-file-cleanup__warn">' . esc_html__( '(ceiling)', 'dahu-file-cleanup' ) . '</span>',
				esc_html( size_format( (int) ( isset( $row['bytes'] ) ? $row['bytes'] : 0 ) ) )
			);
		}

		echo '</tbody></table>';

		if ( count( $rows ) > count( $shown ) ) {
			printf(
				'<p class="dahu-file-cleanup__muted">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: rows shown, 2: rows kept in total. */
						__( 'Showing the %1$s most recent of %2$s rows kept.', 'dahu-file-cleanup' ),
						number_format_i18n( count( $shown ) ),
						number_format_i18n( count( $rows ) )
					)
				)
			);
		}

		printf( '<form method="post" action="%s" class="dahu-file-cleanup__clear">', esc_url( self::screen_url() ) );
		wp_nonce_field( 'dahu_file_cleanup_clear_log' );
		echo '<input type="hidden" name="dahu_file_cleanup_action" value="clear-log">';
		printf( '<button type="submit" class="button-link dahu-file-cleanup__delete">%s</button>', esc_html__( 'Clear the log', 'dahu-file-cleanup' ) );
		echo '</form></div>';
	}

	/**
	 * This screen's URL.
	 *
	 * @return string
	 */
	private static function screen_url() {

		return admin_url( 'tools.php?page=' . DAHU_FILE_CLEANUP_PAGE );
	}
}
