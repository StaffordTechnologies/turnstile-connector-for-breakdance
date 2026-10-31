<?php
/**
 * Turnstile Connector for Breakdance — core plugin class.
 *
 * @package TurnstileConnectorForBreakdance
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class STBD_Plugin
 *
 * Option name, token field, cron hook, and STBD_* constants are kept stable so
 * sites migrating from breakdance-turnstile-connector keep their settings.
 */
final class STBD_Plugin {

	const VERSION = '1.1.0';
	const OPTION  = 'stbd_settings';
	const TOKEN   = 'stbd_turnstile_token';
	const PAGE    = 'turnstile-connector-for-breakdance';

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private static $file;

	/**
	 * Per-request verification cache (never persists across requests).
	 *
	 * @var array
	 */
	private static $checked = array();

	/**
	 * Whether the Breakdance validation filter ran during a diagnostic test.
	 *
	 * @var bool
	 */
	private static $hook_ran = false;

	/**
	 * Bootstrap hooks.
	 *
	 * @param string $file Main plugin file path.
	 * @return void
	 */
	public static function boot( $file ) {
		self::$file = $file;
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_head', array( __CLASS__, 'menu_icon_css' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $file ), array( __CLASS__, 'links' ) );
		add_filter( 'breakdance_form_validate_field', array( __CLASS__, 'validate_field' ), 10, 4 );
		// Covers forms with no ordinary fields, for which field filters do not run.
		add_action( 'wp_ajax_breakdance_form_custom', array( __CLASS__, 'guard_ajax' ), 0 );
		add_action( 'wp_ajax_nopriv_breakdance_form_custom', array( __CLASS__, 'guard_ajax' ), 0 );
		add_action( 'wp_ajax_stbd_test', array( __CLASS__, 'test_request' ) );
		add_action( 'wp_ajax_stbd_test_connection', array( __CLASS__, 'test_connection' ) );
		add_action( 'init', array( __CLASS__, 'schedule_cleanup' ) );
		add_action( 'stbd_cleanup_tokens', array( __CLASS__, 'cleanup_tokens' ) );
		register_deactivation_hook( $file, array( __CLASS__, 'deactivate' ) );
	}

	/**
	 * Settings with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'enabled'    => false,
				'source'     => 'existing',
				'site_key'   => '',
				'secret_key' => '',
				'theme'      => 'auto',
			)
		);
	}

	/**
	 * Resolve site key and secret key.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function keys() {
		if ( defined( 'STBD_SITE_KEY' ) && defined( 'STBD_SECRET_KEY' ) ) {
			return array( (string) STBD_SITE_KEY, (string) STBD_SECRET_KEY );
		}
		$s = self::settings();
		if ( 'existing' === $s['source'] ) {
			return array(
				(string) get_option( 'cfturnstile_key', '' ),
				(string) get_option( 'cfturnstile_secret', '' ),
			);
		}
		return array( (string) $s['site_key'], (string) $s['secret_key'] );
	}

	/**
	 * True when both a site key and a secret key are available.
	 *
	 * @return bool
	 */
	public static function keys_ready() {
		list( $site, $secret ) = self::keys();
		return '' !== $site && '' !== $secret;
	}

	/**
	 * Whether the admin has enabled protection.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return ! empty( self::settings()['enabled'] );
	}

	/**
	 * Protection only runs when enabled AND both keys are present.
	 * Missing keys = no widget and no server check (forms submit normally).
	 *
	 * @return bool
	 */
	public static function protection_active() {
		return self::enabled() && self::keys_ready();
	}

	/**
	 * Form-specific Turnstile action name.
	 *
	 * @param int $post_id Post ID.
	 * @param int $form_id Form ID.
	 * @return string
	 */
	public static function action_name( $post_id, $form_id ) {
		return 'stbd_p' . absint( $post_id ) . '_f' . absint( $form_id );
	}

	/**
	 * Standard verification failure.
	 *
	 * @return WP_Error
	 */
	private static function failure() {
		return new WP_Error(
			'stbd_turnstile',
			__( 'Security verification failed. Please wait for a fresh check and try again.', 'turnstile-connector-for-breakdance' )
		);
	}

	/**
	 * URL for a bundled PNG icon.
	 *
	 * @param int $size 128 or 256.
	 * @return string
	 */
	public static function icon_url( $size = 128 ) {
		$size = ( 256 === (int) $size ) ? 256 : 128;
		return plugins_url( 'assets/icon-' . $size . 'x' . $size . '.png', self::$file );
	}

	/**
	 * URL for the SVG icon used in the admin header.
	 *
	 * @return string
	 */
	public static function icon_svg_url() {
		return plugins_url( 'assets/icon.svg', self::$file );
	}

	/**
	 * Data URI for the admin menu icon (no external request).
	 *
	 * @return string
	 */
	public static function icon_data_uri() {
		$path = STBD_PATH . 'assets/icon.svg';
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$svg = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		if ( ! is_string( $svg ) || '' === $svg ) {
			return '';
		}
		return 'data:image/svg+xml,' . rawurlencode( $svg );
	}

	/**
	 * Verify a Turnstile token once per request. Fail closed on any error.
	 *
	 * @param string|null $token   Token from the form.
	 * @param int         $post_id Post ID.
	 * @param int         $form_id Form ID.
	 * @return true|WP_Error
	 */
	public static function verify( $token, $post_id, $form_id ) {
		$action    = self::action_name( $post_id, $form_id );
		$cache_key = $action . ':' . ( is_string( $token ) ? hash( 'sha256', $token ) : 'malformed' );
		if ( array_key_exists( $cache_key, self::$checked ) ) {
			return self::$checked[ $cache_key ];
		}
		$result                      = self::failure();
		self::$checked[ $cache_key ] = $result;
		list( $site, $secret )       = self::keys();
		if ( ! $site || ! $secret || ! is_string( $token ) || '' === $token || strlen( $token ) > 2048 ) {
			return $result;
		}
		// No plaintext tokens are retained. A unique database key provides an
		// atomic replay lock, including requests on separate PHP workers.
		$used_key = 'stbd_used_' . hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
		if ( false !== get_option( $used_key, false ) ) {
			return $result;
		}
		$body = array(
			'secret'   => $secret,
			'response' => $token,
		);
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REMOTE_ADDR used only as optional hint to Cloudflare; cast to string.
		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		if ( $remote_ip && filter_var( $remote_ip, FILTER_VALIDATE_IP ) ) {
			$body['remoteip'] = $remote_ip;
		}
		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Cloudflare Turnstile siteverify CAPTCHA API (External services).
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => true,
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $result;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$host    = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if (
			is_array( $decoded )
			&& true === ( $decoded['success'] ?? false )
			&& is_string( $decoded['hostname'] ?? null )
			&& '' !== $host
			&& strtolower( $decoded['hostname'] ) === $host
			&& ( $decoded['action'] ?? '' ) === $action
			&& self::claim_token( $used_key )
		) {
			self::$checked[ $cache_key ] = true;
			return true;
		}
		return $result;
	}

	/**
	 * Atomically claim a replay-lock option row.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function claim_token( $name ) {
		global $wpdb;
		$expires = (string) ( time() + 600 );
		// INSERT IGNORE returns 1 only for a new row. A concurrent duplicate
		// cannot update/refresh the existing lock, unlike an options upsert.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional atomic claim.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$expires
			)
		);
		if ( 1 !== $inserted ) {
			return false;
		}
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_set( $name, $expires, 'options' );
		return true;
	}

	/**
	 * Schedule hourly cleanup of expired replay locks.
	 *
	 * @return void
	 */
	public static function schedule_cleanup() {
		if ( self::protection_active() && ! wp_next_scheduled( 'stbd_cleanup_tokens' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'stbd_cleanup_tokens' );
		}
	}

	/**
	 * Delete expired replay-lock options in bounded batches.
	 *
	 * @return void
	 */
	public static function cleanup_tokens() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cleanup of ephemeral locks.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 1000",
				$wpdb->esc_like( 'stbd_used_' ) . '%',
				time()
			)
		);
		if ( ! is_array( $names ) ) {
			return;
		}
		foreach ( $names as $name ) {
			delete_option( $name );
		}
		if ( 1000 === count( $names ) ) {
			wp_schedule_single_event( time() + 60, 'stbd_cleanup_tokens' );
		}
	}

	/**
	 * Clear scheduled cleanup on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'stbd_cleanup_tokens' );
	}

	/**
	 * Read the posted Turnstile token.
	 *
	 * Breakdance's form AJAX carries its own request authentication; this value
	 * is verified with Cloudflare, not as a WordPress nonce.
	 *
	 * @return string|null
	 */
	private static function posted_token() {
		// Breakdance form AJAX: token is verified with Cloudflare siteverify, not a WP nonce.
		if ( ! isset( $_POST[ self::TOKEN ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return null;
		}
		$raw = wp_unslash( $_POST[ self::TOKEN ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized next; Turnstile token is the challenge.

		if ( ! is_string( $raw ) ) {
			return null;
		}
		return sanitize_text_field( $raw );
	}

	/**
	 * Early AJAX guard on Breakdance's custom form submission route.
	 *
	 * @return void
	 */
	public static function guard_ajax() {
		if ( ! self::protection_active() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Breakdance form route; Turnstile token is the challenge.
		$post = isset( $_POST['post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$form = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_METHOD used only for equality check after cast.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if (
			'POST' !== $method
			|| ! is_scalar( $post )
			|| ! is_scalar( $form )
			|| ! ctype_digit( (string) $post )
			|| ! ctype_digit( (string) $form )
			|| ! absint( $post )
			|| ! absint( $form )
		) {
			$result = self::failure();
		} else {
			$result = self::verify( self::posted_token(), absint( $post ), absint( $form ) );
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				),
				400
			);
		}
	}

	/**
	 * Breakdance field validation filter — additional server check.
	 *
	 * @param WP_Error $errors  Error bag.
	 * @param array    $field   Field config.
	 * @param int      $form_id Form ID.
	 * @param int      $post_id Post ID.
	 * @return WP_Error
	 */
	public static function validate_field( $errors, $field, $form_id, $post_id ) {
		unset( $field );
		if ( ! self::protection_active() ) {
			return $errors;
		}
		self::$hook_ran = true;
		$result         = self::verify( self::posted_token(), $post_id, $form_id );
		if ( is_wp_error( $result ) ) {
			$errors->add( 'stbd_turnstile', $result->get_error_message() );
		}
		return $errors;
	}

	/**
	 * Front-end assets when protection is active.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::protection_active() ) {
			return;
		}
		$s            = self::settings();
		list( $site ) = self::keys();
		wp_enqueue_script(
			'stbd-forms',
			plugins_url( 'assets/forms.js', self::$file ),
			array(),
			self::VERSION,
			true
		);
		wp_localize_script(
			'stbd-forms',
			'STBDConfig',
			array(
				'siteKey' => $site,
				'theme'   => $s['theme'],
			)
		);
		wp_enqueue_style(
			'stbd-forms',
			plugins_url( 'assets/forms.css', self::$file ),
			array(),
			self::VERSION
		);
	}

	/**
	 * Settings page assets.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public static function admin_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		$deps = array();
		if ( self::protection_active() ) {
			self::enqueue();
			$deps[] = 'stbd-forms';
		}
		wp_enqueue_script(
			'stbd-admin',
			plugins_url( 'assets/admin.js', self::$file ),
			$deps,
			self::VERSION,
			true
		);
		wp_localize_script(
			'stbd-admin',
			'STBDAdmin',
			array(
				'url'             => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'stbd_test' ),
				'connectionNonce' => wp_create_nonce( 'stbd_test_connection' ),
			)
		);
		wp_add_inline_style(
			'wp-admin',
			'.stbd-admin-header{display:flex;align-items:center;gap:14px;margin:8px 0 18px}'
			. '.stbd-admin-header img{width:48px;height:48px;border-radius:10px;flex:0 0 auto}'
			. '.stbd-admin-header h1{margin:0;padding:0;line-height:1.2}'
			. '.stbd-setup-steps{background:#fff;border:1px solid #c3c4c7;border-left:4px solid #1B4F72;padding:12px 16px;margin:16px 0 20px;max-width:760px}'
			. '.stbd-setup-steps ol{margin:8px 0 0 1.2em}'
			. '.stbd-setup-steps li{margin:0 0 6px}'
			. '#stbd-connection-results,#stbd-test-results{margin-top:10px}'
		);
	}

	/**
	 * Inject a small menu icon next to the settings submenu label.
	 *
	 * @return void
	 */
	public static function menu_icon_css() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$uri = self::icon_data_uri();
		if ( '' === $uri ) {
			return;
		}
		$href = 'options-general.php?page=' . self::PAGE;
		printf(
			'<style id="stbd-menu-icon">#adminmenu a[href="%1$s"] .wp-menu-name:before,#adminmenu .wp-submenu a[href="%1$s"]:before{content:"";display:inline-block;width:16px;height:16px;margin-right:6px;vertical-align:-3px;background:url("%2$s") center/contain no-repeat;}</style>',
			esc_attr( $href ),
			esc_url( $uri, array( 'data' ) )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public static function links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
				esc_html__( 'Settings', 'turnstile-connector-for-breakdance' )
			)
		);
		return $links;
	}

	/**
	 * Register the settings page.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page(
			__( 'Turnstile Connector for Breakdance', 'turnstile-connector-for-breakdance' ),
			__( 'Turnstile Connector', 'turnstile-connector-for-breakdance' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Register the settings option.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'stbd',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize settings on save. Blank key fields keep previously saved values.
	 * The secret is never echoed back into the form.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old            = self::settings();
		$input          = is_array( $input ) ? $input : array();
		$clean          = array(
			'enabled' => ! empty( $input['enabled'] ),
			'source'  => ( isset( $input['source'] ) && 'own' === $input['source'] ) ? 'own' : 'existing',
		);
		$theme          = isset( $input['theme'] ) ? $input['theme'] : '';
		$clean['theme'] = in_array( $theme, array( 'auto', 'light', 'dark' ), true ) ? $theme : 'auto';

		$site_in             = ( isset( $input['site_key'] ) && is_string( $input['site_key'] ) ) ? trim( $input['site_key'] ) : '';
		$secret_in           = ( isset( $input['secret_key'] ) && is_string( $input['secret_key'] ) ) ? trim( $input['secret_key'] ) : '';
		$clean['site_key']   = $old['site_key'];
		$clean['secret_key'] = $old['secret_key'];

		if ( '' !== $site_in ) {
			if ( ! preg_match( '/\A0x[A-Za-z0-9_-]{10,100}\z/', $site_in ) ) {
				add_settings_error(
					self::OPTION,
					'invalid_site_key',
					__( 'That site key does not look valid. The previous site key was kept.', 'turnstile-connector-for-breakdance' )
				);
			} else {
				$clean['site_key'] = $site_in;
			}
		}
		if ( '' !== $secret_in ) {
			if ( ! preg_match( '/\A0x[A-Za-z0-9_-]{10,100}\z/', $secret_in ) ) {
				add_settings_error(
					self::OPTION,
					'invalid_secret_key',
					__( 'That secret key does not look valid. The previous secret was kept.', 'turnstile-connector-for-breakdance' )
				);
			} else {
				$clean['secret_key'] = $secret_in;
			}
		}

		// Enabling without keys is allowed for template installs; protection stays inert until keys exist.
		return $clean;
	}

	/**
	 * Admin notices for missing keys / disabled protection.
	 *
	 * @return void
	 */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen       = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_settings  = $screen && isset( $screen->id ) && ( 'settings_page_' . self::PAGE ) === $screen->id;
		$settings_url = admin_url( 'options-general.php?page=' . self::PAGE );

		if ( ! self::keys_ready() ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Turnstile Connector for Breakdance:', 'turnstile-connector-for-breakdance' ) . '</strong> ';
			echo esc_html__( "Turnstile isn't set up yet. Add your Cloudflare site key and secret key under", 'turnstile-connector-for-breakdance' ) . ' ';
			echo '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings → Turnstile Connector', 'turnstile-connector-for-breakdance' ) . '</a>. ';
			echo esc_html__( 'Until both keys are saved, Breakdance forms submit normally with no Turnstile widget or server check.', 'turnstile-connector-for-breakdance' );
			echo '</p></div>';
			return;
		}

		if ( $on_settings ) {
			return;
		}

		if ( ! self::enabled() || ! function_exists( 'Breakdance\\Forms\\validateFormData' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Turnstile Connector for Breakdance:', 'turnstile-connector-for-breakdance' ) . ' ';
			if ( ! self::enabled() ) {
				echo esc_html__( 'protection is not enabled.', 'turnstile-connector-for-breakdance' );
			} else {
				echo esc_html__( 'check that Breakdance is installed. Enabled submissions remain blocked if verification is unavailable.', 'turnstile-connector-for-breakdance' );
			}
			echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Review settings', 'turnstile-connector-for-breakdance' ) . '</a>.</p></div>';
		}
	}

	/**
	 * Settings page markup.
	 *
	 * @return void
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s                     = self::settings();
		list( $site, $secret ) = self::keys();
		unset( $site, $secret ); // Used only via keys_ready() / protection_active() below; never print secret.
		$own_site     = (string) $s['site_key'];
		$secret_saved = ( '' !== (string) $s['secret_key'] );
		$host         = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		settings_errors( self::OPTION );
		?>
		<div class="wrap stbd-admin">
			<div class="stbd-admin-header">
				<img src="<?php echo esc_url( self::icon_svg_url() ); ?>" width="48" height="48" alt="">
				<h1><?php echo esc_html__( 'Turnstile Connector for Breakdance', 'turnstile-connector-for-breakdance' ); ?></h1>
			</div>
			<p>
				<?php
				echo esc_html__(
					'Protect native Breakdance Form Builder forms with Cloudflare Turnstile before submission actions run.',
					'turnstile-connector-for-breakdance'
				);
				?>
				<?php
				/* translators: %s: plugin version */
				printf( esc_html__( 'Version %s.', 'turnstile-connector-for-breakdance' ), esc_html( self::VERSION ) );
				?>
			</p>

			<div class="stbd-setup-steps">
				<strong><?php echo esc_html__( 'Set up a Cloudflare Turnstile widget', 'turnstile-connector-for-breakdance' ); ?></strong>
				<ol>
					<li><?php echo wp_kses_post( __( 'Open the <a href="https://dash.cloudflare.com/" target="_blank" rel="noopener noreferrer">Cloudflare dashboard</a>.', 'turnstile-connector-for-breakdance' ) ); // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Help link to Cloudflare Turnstile dashboard (service setup), not an offloaded asset. ?></li>
					<li><?php echo wp_kses_post( __( 'Go to <strong>Turnstile</strong>.', 'turnstile-connector-for-breakdance' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Click <strong>Add widget</strong>.', 'turnstile-connector-for-breakdance' ) ); ?></li>
					<li><?php echo esc_html__( 'Enter a name for the widget (for example, this site’s name).', 'turnstile-connector-for-breakdance' ); ?></li>
					<li>
						<?php echo esc_html__( 'Under Hostnames, add this site’s exact hostname:', 'turnstile-connector-for-breakdance' ); ?>
						<code><?php echo esc_html( $host ); ?></code>
						<?php echo esc_html__( '(add any staging or alternate hostnames separately).', 'turnstile-connector-for-breakdance' ); ?>
					</li>
					<li><?php echo wp_kses_post( __( 'Choose <strong>Managed</strong> mode.', 'turnstile-connector-for-breakdance' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Click <strong>Create</strong>.', 'turnstile-connector-for-breakdance' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Copy the <strong>site key</strong> and <strong>secret key</strong>.', 'turnstile-connector-for-breakdance' ) ); ?></li>
					<li><?php echo esc_html__( 'Paste them below (or keep “Use keys saved in Simple CAPTCHA…” if those options already hold the pair), then click Save settings.', 'turnstile-connector-for-breakdance' ); ?></li>
					<li><?php echo esc_html__( 'Use Test connection, then submit a public Breakdance form to confirm the widget appears and the form still sends.', 'turnstile-connector-for-breakdance' ); ?></li>
				</ol>
			</div>

			<form method="post" action="options.php" autocomplete="off">
				<?php settings_fields( 'stbd' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Protection', 'turnstile-connector-for-breakdance' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="stbd_settings[enabled]" value="1" <?php checked( $s['enabled'] ); ?>>
								<?php echo esc_html__( 'Enable on all Breakdance Form Builder forms', 'turnstile-connector-for-breakdance' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'If either key is missing, the plugin does nothing: no widget and no server check, so forms submit normally.', 'turnstile-connector-for-breakdance' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stbd-source"><?php echo esc_html__( 'Cloudflare keys', 'turnstile-connector-for-breakdance' ); ?></label></th>
						<td>
							<select id="stbd-source" name="stbd_settings[source]">
								<option value="existing" <?php selected( $s['source'], 'existing' ); ?>><?php echo esc_html__( 'Use keys saved in Simple CAPTCHA with Cloudflare Turnstile', 'turnstile-connector-for-breakdance' ); ?></option>
								<option value="own" <?php selected( $s['source'], 'own' ); ?>><?php echo esc_html__( 'Use this plugin’s own keys', 'turnstile-connector-for-breakdance' ); ?></option>
							</select>
							<p class="description"><?php echo wp_kses_post( __( 'Both <code>STBD_SITE_KEY</code> and <code>STBD_SECRET_KEY</code> constants, when defined in <code>wp-config.php</code>, override this choice.', 'turnstile-connector-for-breakdance' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stbd-site"><?php echo esc_html__( 'Site key', 'turnstile-connector-for-breakdance' ); ?></label></th>
						<td>
							<input id="stbd-site" class="regular-text" name="stbd_settings[site_key]" autocomplete="off" value="" placeholder="<?php echo '' !== $own_site ? esc_attr__( 'Saved — leave blank to keep', 'turnstile-connector-for-breakdance' ) : ''; ?>">
							<p class="description">
								<?php
								if ( '' !== $own_site ) {
									printf(
										/* translators: %s: first characters of the saved public site key */
										esc_html__( 'A site key is saved for this plugin (%s…). Leave blank to keep it, or paste a new key to replace it.', 'turnstile-connector-for-breakdance' ),
										esc_html( substr( $own_site, 0, 6 ) )
									);
								} else {
									echo esc_html__( 'Paste the Cloudflare site key when using this plugin’s own keys. Leave blank to keep any previously saved value.', 'turnstile-connector-for-breakdance' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stbd-secret"><?php echo esc_html__( 'Secret key', 'turnstile-connector-for-breakdance' ); ?></label></th>
						<td>
							<input id="stbd-secret" class="regular-text" type="password" name="stbd_settings[secret_key]" autocomplete="new-password" value="">
							<p class="description">
								<?php
								if ( $secret_saved ) {
									echo esc_html__( 'A secret key is saved. It is never shown. Leave blank to keep it. Paste a new secret only when replacing it.', 'turnstile-connector-for-breakdance' );
								} else {
									echo esc_html__( 'No secret saved yet. Paste the Cloudflare secret key, then save. The saved secret is never shown after that.', 'turnstile-connector-for-breakdance' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stbd-theme"><?php echo esc_html__( 'Widget appearance', 'turnstile-connector-for-breakdance' ); ?></label></th>
						<td>
							<select id="stbd-theme" name="stbd_settings[theme]">
								<?php
								foreach ( array(
									'auto'  => __( 'Automatic', 'turnstile-connector-for-breakdance' ),
									'light' => __( 'Light', 'turnstile-connector-for-breakdance' ),
									'dark'  => __( 'Dark', 'turnstile-connector-for-breakdance' ),
								) as $value => $label ) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr( $value ),
										selected( $s['theme'], $value, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'turnstile-connector-for-breakdance' ) ); ?>
			</form>

			<h2><?php echo esc_html__( 'Status', 'turnstile-connector-for-breakdance' ); ?></h2>
			<p>
				<?php echo esc_html__( 'Protection toggle:', 'turnstile-connector-for-breakdance' ); ?>
				<strong><?php echo self::enabled() ? esc_html__( 'On', 'turnstile-connector-for-breakdance' ) : esc_html__( 'Off', 'turnstile-connector-for-breakdance' ); ?></strong>.
				<?php echo esc_html__( 'Keys:', 'turnstile-connector-for-breakdance' ); ?>
				<strong><?php echo self::keys_ready() ? esc_html__( 'Ready', 'turnstile-connector-for-breakdance' ) : esc_html__( 'Not set up yet', 'turnstile-connector-for-breakdance' ); ?></strong>.
				<?php echo esc_html__( 'Active on forms:', 'turnstile-connector-for-breakdance' ); ?>
				<strong><?php echo self::protection_active() ? esc_html__( 'Yes', 'turnstile-connector-for-breakdance' ) : esc_html__( 'No', 'turnstile-connector-for-breakdance' ); ?></strong>.
			</p>
			<p>
				<?php echo esc_html__( 'Current hostname:', 'turnstile-connector-for-breakdance' ); ?>
				<code><?php echo esc_html( $host ); ?></code>.
				<?php echo esc_html__( 'Add this exact hostname to the Cloudflare widget.', 'turnstile-connector-for-breakdance' ); ?>
			</p>

			<h2><?php echo esc_html__( 'Test connection', 'turnstile-connector-for-breakdance' ); ?></h2>
			<p><?php echo esc_html__( 'Calls Cloudflare’s siteverify endpoint with the configured secret and a dummy token, then reports the error codes. A response of invalid-input-response (and not invalid-input-secret) means the secret is accepted.', 'turnstile-connector-for-breakdance' ); ?></p>
			<p><button type="button" class="button" id="stbd-test-connection"><?php echo esc_html__( 'Test connection', 'turnstile-connector-for-breakdance' ); ?></button></p>
			<div id="stbd-connection-results" role="status" aria-live="polite"></div>

			<h2><?php echo esc_html__( 'Connection and security test', 'turnstile-connector-for-breakdance' ); ?></h2>
			<p><?php echo esc_html__( 'Checks missing, invalid, valid, and reused tokens through Breakdance’s installed field validator. This test does not send email or store form entries.', 'turnstile-connector-for-breakdance' ); ?></p>
			<?php if ( self::protection_active() ) : ?>
			<form id="stbd-diagnostic" data-stbd-diagnostic="true">
				<input type="hidden" name="post_id" value="0"><input type="hidden" name="form_id" value="0">
				<div class="stbd-test-footer"><button type="submit" class="button button-primary"><?php echo esc_html__( 'Run security tests', 'turnstile-connector-for-breakdance' ); ?></button></div>
			</form>
			<div id="stbd-test-results" role="status" aria-live="polite"></div>
			<?php else : ?>
			<p><em><?php echo esc_html__( 'Enable protection and save both keys to unlock the four security checks.', 'turnstile-connector-for-breakdance' ); ?></em></p>
			<?php endif; ?>

			<p><?php echo esc_html__( 'Protects Breakdance Form Builder only; configure other form plugins separately. No license activation or site-count limit.', 'turnstile-connector-for-breakdance' ); ?></p>
			<p>
				<?php
				printf(
					/* translators: 1: docs URL, 2: support email */
					wp_kses_post( __( 'Docs and support: <a href="%1$s">%1$s</a> · %2$s', 'turnstile-connector-for-breakdance' ) ),
					esc_url( 'https://staffordtechnologies.net/turnstile-connector-for-breakdance/' ),
					esc_html( STBD_SUPPORT_EMAIL )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Admin AJAX: probe Cloudflare with a dummy token to validate the secret.
	 *
	 * @return void
	 */
	public static function test_connection() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'stbd_test_connection', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not authorized.', 'turnstile-connector-for-breakdance' ) ), 403 );
		}
		list( $site, $secret ) = self::keys();
		if ( '' === $secret ) {
			wp_send_json_error( array( 'message' => __( 'No secret key is configured yet.', 'turnstile-connector-for-breakdance' ) ), 400 );
		}
		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Cloudflare Turnstile siteverify CAPTCHA API (External services).
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => true,
				'body'        => array(
					'secret'   => $secret,
					'response' => 'XXXX.DUMMY.TOKEN',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					/* translators: %s: error message */
					'message' => sprintf( __( 'Could not reach Cloudflare: %s', 'turnstile-connector-for-breakdance' ), $response->get_error_message() ),
				),
				502
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			wp_send_json_error(
				array(
					/* translators: %d: HTTP status code */
					'message' => sprintf( __( 'Unexpected Cloudflare response (HTTP %d).', 'turnstile-connector-for-breakdance' ), $code ),
				),
				502
			);
		}
		$errors = array();
		if ( isset( $body['error-codes'] ) && is_array( $body['error-codes'] ) ) {
			foreach ( $body['error-codes'] as $err ) {
				if ( is_string( $err ) ) {
					$errors[] = $err;
				}
			}
		}
		$secret_ok = ! in_array( 'invalid-input-secret', $errors, true );
		if ( $secret_ok ) {
			$message = sprintf(
				/* translators: %s: comma-separated Cloudflare error codes */
				__( 'Secret key accepted by Cloudflare. Error codes from the dummy token: %s.', 'turnstile-connector-for-breakdance' ),
				$errors ? implode( ', ', $errors ) : __( '(none)', 'turnstile-connector-for-breakdance' )
			);
		} else {
			$message = sprintf(
				/* translators: %s: comma-separated Cloudflare error codes */
				__( 'Cloudflare rejected the secret key (%s). Check the key and try again.', 'turnstile-connector-for-breakdance' ),
				implode( ', ', $errors )
			);
		}
		wp_send_json_success(
			array(
				'secret_ok'        => $secret_ok,
				'site_key_present' => '' !== $site,
				'error_codes'      => $errors,
				'message'          => $message,
			)
		);
	}

	/**
	 * Admin AJAX: run the four security checks via Breakdance's validator.
	 *
	 * @return void
	 */
	public static function test_request() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'stbd_test', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not authorized.', 'turnstile-connector-for-breakdance' ) ), 403 );
		}
		if ( ! self::protection_active() || ! function_exists( 'Breakdance\\Forms\\validateFormData' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Enable protection with both keys saved, and check that Breakdance is active.', 'turnstile-connector-for-breakdance' ),
				),
				400
			);
		}
		$field  = array(
			'type'     => 'text',
			'label'    => 'Connection test',
			'value'    => 'test',
			'advanced' => array(
				'id'       => 'stbd_test',
				'required' => true,
			),
		);
		$result = \Breakdance\Forms\validateFormData( array( $field ), array(), 0, 0 );
		wp_send_json_success(
			array(
				'accepted'           => ! is_wp_error( $result ),
				'hook_ran'           => self::$hook_ran,
				'turnstile_rejected' => is_wp_error( $result ) && in_array( 'stbd_turnstile', $result->get_error_codes(), true ),
			)
		);
	}
}
