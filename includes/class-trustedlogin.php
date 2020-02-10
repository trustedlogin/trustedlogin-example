<?php

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
	exit;
}

class TrustedLogin {

	/**
	 * @var string $version - the current drop-in file version
	 * @since 0.1.0
	 */
	const version = '0.4.2';

	/**
	 * @var string self::saas_api_url - the API url for the TrustedLogin SaaS Platform (with trailing slash)
	 * @since 0.4.0
	 */
	const saas_api_url = 'https://app.trustedlogin.com/api/';

	/**
	 * @var array $settings - instance of the initialised plugin config object
	 * @since 0.1.0
	 */
	private $settings;

	/**
	 * @var string $support_role - the namespaced name of the new Role to be created for Support Agents
	 * @example '{vendor/namespace}-support'
	 * @since 0.1.0
	 */
	private $support_role;

	/**
	 * @var string $endpoint_option - the namespaced setting name for storing part of the auto-login endpoint
	 * @example 'tl_{vendor/namespace}_endpoint'
	 * @since 0.3.0
	 */
	private $endpoint_option;

	/**
	 * @var string $key_storage_option - The namespaced setting name for storing the vaultToken and deleteKey
	 * @example 'tl_{vendor/namespace}_slt
	 * @since 0.7.0
	 */
	private $key_storage_option;

	/**
	 * @var string $identifier_meta_key - The namespaced setting name for storing the unique identifier hash in user meta
	 * @example tl_{vendor/namespace}_id
	 * @since 0.7.0
	 */
	private $identifier_meta_key;

	/**
	 * @var int $expires_meta_key - [Currently not used] The namespaced setting name for storing the timestamp the user expires
	 * @example tl_{vendor/namespace}_expires
	 * @since 0.7.0
	 */
	private $expires_meta_key;

	/**
	 * @var bool $debug_mode - whether to output debug information to a debug text file
	 * @since 0.1.0
	 */
	private $debug_mode;

	/**
	 * @var bool $is_initialized - if the settings have been initialized from the config object
	 * @since 0.1.0
	 */
	private $is_initialized = false;

	/**
	 * @var string $ns - plugin's namespace for use in namespacing variables and strings
	 * @since 0.4.0
	 */
	private $ns;

	/**
	 * @var string $public_key_option - where the plugin should store the public key for encrypting data
	 * @since 0.5.0
	 */
	private $public_key_option;

	public function __construct( $config ) {

		/**
		 * Filter: Whether debug logging is enabled in trustedlogin drop-in
		 *
		 * @param bool
		 *
		 * @since 0.4.2
		 *
		 */
		$this->debug_mode = apply_filters( 'trustedlogin_debug_enabled', true );

		// TODO: Show error when config hasn't happened.
		if ( empty( $config ) ) {

			$this->log( 'No config settings passed to constructor', __METHOD__, 'critical' );

			return;
		}

		$this->is_initialized = $this->init_settings( $config );

		$this->init_hooks();

	}

	/**
	 * @param string $text Message to log
	 * @param string $method Method where the log was called
	 * @param string $level PSR-3 log level
	 *
	 * @see https://github.com/php-fig/log/blob/master/Psr/Log/LogLevel.php for log levels
	 */
	private function log( $text = '', $method = '', $level = 'notice' ) {

		if ( ! $this->debug_mode ) {
			return;
		}

		$levels = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );

		if ( ! in_array( $level, $levels ) ) {

			$this->log( sprintf( 'Invalid level passed by %s method: %s', $method, $level ), __METHOD__, 'error' );

			$level = 'notice'; // Continue processing original log
		}

		do_action( 'trustedlogin/log', $text, $method, $level );
		do_action( 'trustedlogin/log/' . $level, $text, $method );

		// If logging is in place, don't use the error_log
		if ( has_action( 'trustedlogin/log' ) || has_action( 'trustedlogin/log/' . $level ) ) {
			#    return;
		}

		if ( in_array( $level, array( 'emergency', 'alert', 'critical', 'error', 'warning' ) ) ) {
			// If WP_DEBUG and WP_DEBUG_LOG are enabled, by default, errors will be logged to that log file.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( $method . ' (' . $level . '): ' . $text );
			}
		}
	}

	/**
	 * Returns whether class has been initialized
	 *
	 * @since 0.7.0
	 *
	 * @return bool Whether the class has initialized successfully (configuration settings were set)
	 */
	public function is_initialized() {
		return $this->is_initialized;
	}

	/**
	 * Initialise the action hooks required
	 *
	 * @since 0.2.0
	 */
	public function init_hooks() {

		add_action( 'trustedlogin_revoke_access', array( $this, 'support_user_decay' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );

		add_action( 'wp_ajax_tl_gen_support', array( $this, 'ajax_generate_support' ) );

		if ( is_admin() ) {

			add_action( 'trustedlogin_button', array( $this, 'output_tl_button' ), 10, 2 );

			add_filter( 'user_row_actions', array( $this, 'user_row_action_revoke' ), 10, 2 );

			add_action( 'trustedlogin_users_table', array( $this, 'output_support_users' ), 20 );
		}

		add_action( 'admin_bar_menu', array( $this, 'adminbar_add_toolbar_items' ), 100 );

		add_action( 'admin_init', array( $this, 'admin_maybe_revoke_support' ), 100 );

		// Endpoint Hooks
		add_action( 'init', array( $this, 'add_support_endpoint' ), 10 );
		add_action( 'template_redirect', array( $this, 'maybe_login_support' ), 99 );

		add_action( 'trustedlogin/access/created', array( $this, 'maybe_send_webhook' ) );
		add_action( 'trustedlogin/access/revoked', array( $this, 'maybe_send_webhook' ) );
	}

	/**
	 * Hooked Action: Add a unique endpoint to WP if a support agent exists
	 *
	 * @since 0.3.0
	 */
	public function add_support_endpoint() {

		$endpoint = get_option( $this->endpoint_option );

		if ( ! $endpoint ) {
			return;
		}

		add_rewrite_endpoint( $endpoint, EP_ROOT );

		$this->log( "Endpoint {$endpoint} added.", __METHOD__, 'debug' );

		if ( $endpoint && ! get_option( 'tl_permalinks_flushed' ) ) {

			flush_rewrite_rules( false );

			update_option( 'tl_permalinks_flushed', 1 );

			$this->log( "Rewrite rules flushed.", __METHOD__, 'info' );
		}
	}

	/**
	 * Check if the endpoint is hit and has a valid identifier before automatically logging in support agent
	 *
	 * @since 0.3.0
	 */
	public function maybe_login_support() {

		$endpoint = get_option( $this->endpoint_option );

		$identifier = get_query_var( $endpoint, false );

		if ( empty( $identifier ) ) {
			return;
		}

		$users = $this->get_support_user( $identifier );

		if ( empty( $users ) ) {
			return;
		}

		$support_user = $users[0];

		$expires = get_user_meta( $support_user->ID, $this->expires_meta_key, true );

		// This user has expired, but the cron didn't run...
		if ( $expires && time() > (int) $expires ) {
			$this->log( 'The user was supposed to expire on ' . $expires . '; revoking now.', __METHOD__, 'warning' );

			$identifier = get_user_meta( $support_user->ID, $this->identifier_meta_key, true );

			$this->remove_support_user( $identifier );

			return;
		}

		wp_set_current_user( $support_user->ID, $support_user->user_login );
		wp_set_auth_cookie( $support_user->ID );

		do_action( 'wp_login', $support_user->user_login, $support_user );

		wp_redirect( admin_url() );
		exit();
	}

	/**
	 * AJAX handler for maybe generating a Support User
	 *
	 * @since 0.2.0
	 *
	 * @return void Sends a JSON success or error message based on what happens
	 */
	public function ajax_generate_support() {

		if ( empty( $_POST['vendor'] ) ) {
			wp_send_json_error( array( 'message' => 'Vendor not defined in TrustedLogin configuration.' ) );
		}

		// There are multiple TrustedLogin instances, and this is not the one being called.
		// TODO: Needs more testing!
		if ( $this->get_setting( 'vendor/namespace' ) !== $_POST['vendor'] ) {
			return;
		}

		if ( empty( $_POST['_nonce'] ) ) {
			wp_send_json_error( array( 'message' => 'Nonce not sent in the request.' ) );
		}

		if ( ! check_ajax_referer( 'tl_nonce-' . get_current_user_id(), '_nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Verification issue: Request could not be verified. Please reload the page.' ) );
		}

		if ( ! current_user_can( 'create_users' ) ) {
			wp_send_json_error( array( 'message' => 'Permissions issue: You do not have the ablility to create users.' ) );
		}


		$support_user_id = $this->create_support_user();

		if ( is_wp_error( $support_user_id ) ) {

			$this->log( sprintf( 'Support user not created: %s (%s)', $support_user_id->get_error_message(), $support_user_id->get_error_code() ), __METHOD__, 'error' );

			wp_send_json_error( array( 'message' => $support_user_id->get_error_message() ), 409 );
		}

		$identifier_hash = $this->get_identifier_hash();

		$endpoint = $this->get_endpoint_hash( $identifier_hash );

		$this->update_endpoint( $endpoint );

		$expiration_timestamp = $this->get_expiration_timestamp();

		// Add user meta, configure decay
		$did_setup = $this->support_user_setup( $support_user_id, $identifier_hash, $expiration_timestamp );

		if ( empty( $did_setup ) ) {
			wp_send_json_error( array( 'message' => 'Error updating user with identifier.' ), 503 );
		}

		$return_data = array(
			'siteurl'    => get_site_url(),
			'endpoint'   => $endpoint,
			'identifier' => $identifier_hash,
			'user_id'    => $support_user_id,
			'expiry'     => $expiration_timestamp,
		);

		try {

			$synced = $this->create_access( $identifier_hash, $return_data );

		} catch ( Exception $e ) {

			$return_data['message'] = $e->getMessage();

			wp_send_json_error( $return_data, 503 );
		}

		if ( is_wp_error( $synced ) ) {

			$return_data['message'] = $synced->get_error_message();

			wp_send_json_error( $return_data, 503 );
		}

		wp_send_json_success( $return_data, 201 );
	}

	/**
	 * Returns a timestamp that is the current time + decay time setting
	 *
	 * Note: This is a server timestamp, not a WordPress timestamp
	 *
	 * @param int $decay If passed, override the `decay` setting
	 *
	 * @return int Default: time() + 300
	 */
	public function get_expiration_timestamp( $decay_time = null ) {

		if ( is_null( $decay_time ) ) {
			$decay_time = $this->get_setting( 'decay', 3 * DAY_IN_SECONDS );
		}

		$expiration_timestamp = time() + (int) $decay_time;

		return $expiration_timestamp;
	}

	/**
	 * Updates the site's endpoint to listen for logins
	 *
	 * @param string $endpoint
	 *
	 * @return bool True: updated; False: didn't change, or didn't update
	 */
	public function update_endpoint( $endpoint ) {
		return update_option( $this->endpoint_option, $endpoint, true );
	}

	/**
	 * Generate a hash that is used to add two levels of security to the login URL:
	 * The hash is stored as usermeta, but then also used to generate the Vault keyStoreID.
	 * Both parts are required to access the site.
	 *
	 * @return string
	 */
	public function get_identifier_hash() {
		return wp_generate_password( 64, false, false );
	}

	/**
	 * Schedules cron job to auto-revoke, adds user meta with unique ids
	 *
	 * @param int $user_id ID of generated support user
	 * @param string $identifier_hash Unique ID used by
	 *
	 * @return string Value of $identifier_meta_key if worked; empty string if not.
	 */
	public function support_user_setup( $user_id, $identifier_hash, $expiration_timestamp = null ) {

		if ( $expiration_timestamp ) {

			$scheduled_expiration = wp_schedule_single_event(
				$expiration_timestamp,
				'trustedlogin_revoke_access',
				array( md5( $identifier_hash ) )
			);

			$this->log( 'Scheduled Expiration: ' . var_export( $scheduled_expiration, true ) . '; identifier: ' . $identifier_hash, __METHOD__, 'info' );

			add_user_meta( $user_id, $this->expires_meta_key, $expiration_timestamp );
		}

		add_user_meta( $user_id, $this->identifier_meta_key, md5( $identifier_hash ), true );
		add_user_meta( $user_id, 'tl_created_by', get_current_user_id() );

		// Make extra sure that the identifier was saved. Otherwise, things won't work!
		return get_user_meta( $user_id, $this->identifier_meta_key, true );
	}

	/**
	 * Register the required scripts and styles
	 *
	 * @since 0.2.0
	 */
	public function register_assets() {

		$jquery_confirm_version = '3.3.2';

		wp_register_style(
			'jquery-confirm',
			'https://cdnjs.cloudflare.com/ajax/libs/jquery-confirm/' . $jquery_confirm_version . '/jquery-confirm.min.css',
			array(),
			$jquery_confirm_version,
			'all'
		);

		wp_register_script(
			'jquery-confirm',
			'https://cdnjs.cloudflare.com/ajax/libs/jquery-confirm/' . $jquery_confirm_version . '/jquery-confirm.min.js',
			array( 'jquery' ),
			$jquery_confirm_version,
			true
		);

		wp_register_script(
			'trustedlogin',
			trailingslashit( $this->get_setting( 'path/js_dir_url' ) ) . 'trustedlogin.js',
			array( 'jquery', 'jquery-confirm' ),
			self::version,
			true
		);

		wp_register_style(
			'trustedlogin',
			trailingslashit( $this->get_setting( 'path/css_dir_url' ) ) . 'trustedlogin.css',
			array( 'jquery-confirm' ),
			self::version,
			'all'
		);

	}

	/**
	 * Output the TrustedLogin Button and required scripts
	 *
	 * @since 0.2.0
	 *
	 * @param bool $print - whether to print results or return them
	 *
	 * @return string the HTML output
	 */
	public function output_tl_button( $atts = array(), $print = true ) {

		if ( ! current_user_can( 'create_users' ) ) {
			return;
		}

		if ( ! wp_script_is( 'trustedlogin', 'registered' ) ) {
			$this->log( 'JavaScript is not registered. Make sure `trustedlogin` handle is added to "no-conflict" plugin settings.', __METHOD__, 'error' );
		}

		if ( ! wp_style_is( 'trustedlogin', 'registered' ) ) {
			$this->log( 'Style is not registered. Make sure `trustedlogin` handle is added to "no-conflict" plugin settings.', __METHOD__, 'error' );
		}

		wp_enqueue_style( 'trustedlogin' );

		$button_settings = array(
			'vendor'   => $this->get_setting( 'vendor' ),
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			'_nonce'   => wp_create_nonce( 'tl_nonce-' . get_current_user_id() ),
			'lang'     => array_merge( $this->output_tl_alert(), $this->output_secondary_alerts() ),
			'debug'    => $this->debug_mode,
			'selector' => '.trustedlogin–grant-access',
		);

		wp_localize_script( 'trustedlogin', 'tl_obj', $button_settings );

		wp_enqueue_script( 'trustedlogin' );

		$return = $this->get_button( $atts );

		if ( $print ) {
			echo $return;
		}

		return $return;
	}

	/**
	 *
	 * @param array $atts
	 *
	 * @return string
	 */
	public function get_button( $atts = array() ) {

		$defaults = array(
			'text'        => sprintf( esc_html__( 'Grant %s Support Access', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ),
			'exists_text' => sprintf( esc_html__( '✅ %s Support Has An Account', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ),
			'size'        => 'hero',
			'class'       => 'button-primary',
			'tag'         => 'a', // "a", "button", "span"
			'id'          => null,
			'powered_by'  => true,
			'support_url' => $this->get_setting( 'vendor/support_url' ),
		);

		$sizes = array( 'small', 'normal', 'large', 'hero' );

		$atts = wp_parse_args( $atts, $defaults );

		switch ( $atts['size'] ) {
			case '':
				$css_class = '';
				break;
			case 'normal':
				$css_class = 'button';
				break;
			default:
				if ( ! in_array( $atts['size'], $sizes ) ) {
					$atts['size'] = 'hero';
				}

				$css_class = 'button button-' . $atts['size'];
		}

		$tags = array( 'a', 'button', 'span' );

		if ( ! in_array( $atts['tag'], $tags ) ) {
			$atts['tag'] = 'a';
		}

		$tag = empty( $atts['tag'] ) ? 'a' : $atts['tag'];

		if ( $this->get_support_users() ) {
			$text = esc_html( $atts['exists_text'] );
			$href = admin_url( 'users.php?role=' . $this->support_role );
		} else {
			$text      = esc_html( $atts['text'] );
			$css_class .= ' trustedlogin–grant-access'; // Tell JS to grant access on click
			$href      = esc_html( $atts['support_url'] );
		}

		$css_class = implode( ' ', array( $css_class, $atts['class'] ) );

		$powered_by  = $atts['powered_by'] ? '<small><span class="trustedlogin-logo"></span>Powered by TrustedLogin</small>' : false;
		$anchor_html = $text . $powered_by;

		$button = sprintf( '<%s href="%s" class="%s button-trustedlogin">%s</%s>', $tag, esc_url( $href ), esc_attr( $css_class ), $anchor_html, $tag );

		return $button;
	}

	/**
	 * Hooked Action to Output the List of Support Users Created
	 *
	 * @since 0.2.1
	 *
	 * @param bool $return - whether to echo (vs return) the results [default:true]
	 *
	 * @return mixed - echoed HTML or returned string of HTML
	 */
	public function output_support_users( $print = true ) {

		if ( ! is_admin() || ! current_user_can( 'create_users' ) ) {
			return;
		}

		// The `trustedlogin_button` action passes an empty string
		if ( '' === $print ) {
			$print = true;
		}

		$support_users = $this->get_support_users();

		if ( empty( $support_users ) ) {

			$return = '<h3>' . sprintf( esc_html__( 'No %s users exist.', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ) . '</h3>';

			if ( $print ) {
				echo $return;
			}

			return $return;
		}

		$return = '';

		$return .= '<h3>' . sprintf( esc_html__( '%s users:', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ) . '</h3>';

		$return .= '<table class="wp-list-table widefat plugins">';

		$table_header =
			sprintf( '
                <thead>
                    <tr>
                        <th scope="col">%1$s</th>
                        <th scope="col">%2$s</th>
                        <th scope="col">%3$s</th>
                        <th scope="col">%4$s</td>
                        <th scope="col">%5$s</th>
                    </tr>
                </thead>',
				esc_html__( 'User', 'trustedlogin' ),
				esc_html__( 'Created', 'trustedlogin' ),
				esc_html__( 'Expires', 'trustedlogin' ),
				esc_html__( 'Created By', 'trustedlogin' ),
				esc_html__( 'Revoke Access', 'trustedlogin' )
			);

		$return .= $table_header;

		$return .= '<tbody>';

		foreach ( $support_users as $support_user ) {

			$_user_creator = get_user_by( 'id', get_user_meta( $support_user->ID, 'tl_created_by', true ) );

			$return .= '<tr>';
			$return .= '<th scope="row"><a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $support_user->ID ) ) . '">';
			$return .= sprintf( '%s (#%d)', esc_html( $support_user->display_name ), $support_user->ID );
			$return .= '</th>';

			$return .= '<td>' . sprintf( esc_html__( '%s ago', 'trustedlogin' ), human_time_diff( strtotime( $support_user->user_registered ) ) ) . '</td>';
			$return .= '<td>' . sprintf( esc_html__( 'In %s', 'trustedlogin' ), human_time_diff( get_user_meta( $support_user->ID, $this->expires_meta_key, true ) ) ) . '</td>';

			if ( $_user_creator && $_user_creator->exists() ) {
				$return .= '<td>' . ( $_user_creator->exists() ? esc_html( $_user_creator->display_name ) : esc_html__( 'Unknown', 'trustedlogin' ) ) . '</td>';
			} else {
				$return .= '<td>' . esc_html__( 'Unknown', 'trustedlogin' ) . '</td>';
			}

			if ( $revoke_url = $this->helper_get_user_revoke_url( $support_user, true ) ) {
				$return .= '<td><a class="trustedlogin tl-revoke submitdelete" href="' . esc_url( $revoke_url ) . '">' . esc_html__( 'Revoke Access', 'trustedlogin' ) . '</a></td>';
			} else {
				$return .= '<td><a href="' . esc_url( admin_url( 'users.php?role=' . $this->support_role ) ) . '">' . esc_html__( 'Manage from Users list', 'trustedlogin' ) . '</a></td>';
			}
			$return .= '</tr>';

		}

		$return .= '</tbody></table>';

		if ( $print ) {
			echo $return;
		}


		return $return;
	}

	/**
	 * Generate the HTML strings for the Confirmation dialogues
	 *
	 * @since 0.2.0
	 *
	 * @return string[] array containing 'intro', 'description' and 'detail' keys.
	 */
	public function output_tl_alert() {

		$result = array();

		$result['intro'] = sprintf(
			__( 'Grant %1$s Support access to your site.', 'trustedlogin' ),
			$this->get_setting( 'vendor/title' )
		);

		$result['description'] = sprintf( '<p class="description">%1$s</p>',
			__( 'By clicking Confirm, the following will happen automatically:', 'trustedlogin' )
		);

		$details = '<ul class="tl-details">';

		// Roles
		foreach ( $this->get_setting( 'role' ) as $role => $reason ) {
			$details .= sprintf( '<li class="role"> %1$s <br /><small>%2$s</small></li>',
				sprintf( esc_html__( 'A new user will be created with a custom role \'%1$s\' (with the same capabilities as %2$s).', 'trustedlogin' ),
					$this->support_role,
					$role
				),
				$reason
			);
		}

		// Extra Caps
		foreach ( $this->get_setting( 'extra_caps' ) as $cap => $reason ) {
			$details .= sprintf( '<li class="extra-caps"> %1$s <br /><small>%2$s</small></li>',
				sprintf( esc_html__( 'With the additional \'%1$s\' Capability.', 'trustedlogin' ),
					$cap
				),
				$reason
			);
		}
		$details .= '</ul>';

		// Decay
		if ( $this->get_setting( 'decay' ) ) {

			$decay_time = $this->get_setting( 'decay' );

			if ( ! is_int( $decay_time ) ) {
				$this->log( 'Error: Decay time should be an integer. Instead: ' . var_export( $decay_time, true ), __METHOD__, 'error' );
				$decay_time = intval( $decay_time );
			}

			$decay_diff = human_time_diff( time() + $decay_time, time() );

			$details .= '<h4>' . sprintf( esc_html__( 'Access will be granted for %1$s and can be revoked at any time.', 'trustedlogin' ), $decay_diff ) . '</h4>';
		}


		$result['details'] = $details;

		return $result;

	}

	/**
	 * Helper function: Build translate-able strings for alert messages
	 *
	 * @since 0.4.3
	 *
	 * @return array of Translations and strings to be localized to JS variables
	 */
	public function output_secondary_alerts() {

		$plugin_title = $this->get_setting( 'vendor/title' );

		/**
		 * Filter: Allow for adding into GET parameters on support_url
		 *
		 * ```
		 * $url_query_args = [
		 *   'message' => (string) What error should be sent to the support system.
		 * ];
		 * ```
		 *
		 * @param array $url_query_args {
		 *   @type string $message What error should be sent to the support system.
		 * }
		 *
		 * @since 0.4.3
		 */
		$query_args = apply_filters(
			'trustedlogin/support_url/query_args',
			array( 'message' => __( 'Could not create TrustedLogin access.', 'trustedlogin' ) )
		);

		$no_sync_content = '<p>' .
           sprintf(
               esc_html__( 'Unfortunately, the Support User details could not be sent to %1$s automatically.', 'trustedlogin' ),
               $plugin_title
           ) . '</p><p>' .
           sprintf(
               wp_kses(
                   __( 'Please <a href="%1$s" target="_blank">click here</a> to go to the %2$s Support Site', 'trustedlogin' ),
                   array( 'a' => array( 'href' => array() ) )
               ),
               esc_url( add_query_arg( $query_args, $this->get_setting( 'vendor/support_url' ) ) ),
               $plugin_title
           ) . '</p>';

		$secondary_alert_translations = array(
			'confirmButton'      => esc_html__( 'Confirm', 'trustedlogin' ),
			'okButton'           => esc_html__( 'OK', 'trustedlogin' ),
			'noSyncTitle'        => sprintf(
				__( 'Error syncing Support User to %1$s', 'trustedlogin' ),
				$plugin_title
			),
			'noSyncContent'      => $no_sync_content,
			'noSyncGoButton'     => sprintf(
				__( 'Go to %1$s support site', 'trustedlogin' ),
				$plugin_title
			),
			'noSyncCancelButton' => esc_html__( 'Close', 'trustedlogin' ),
			'syncedTitle'        => esc_html__( 'Support access granted', 'trustedlogin' ),
			'syncedContent'      => sprintf(
				__( 'A temporary support user has been created, and sent to %1$s Support.', 'trustedlogin' ),
				$plugin_title
			),
			'cancelButton'       => esc_html__( 'Cancel', 'trustedlogin' ),
			'cancelTitle'        => esc_html__( 'Action Cancelled', 'trustedlogin' ),
			'cancelContent'      => sprintf(
				__( 'A support account for %1$s has NOT been created.', 'trustedlogin' ),
				$plugin_title
			),
			'failTitle'          => esc_html__( 'Support Access NOT Granted', 'trustedlogin' ),
			'failContent'        => esc_html__( 'Got this from the server: ', 'trustedlogin' ),
			'fail409Title'       => sprintf(
				__( '%1$s Support User already exists', 'trustedlogin' ),
				$plugin_title
			),
			'fail409Content'     => sprintf(
				wp_kses(
					__( 'A support user for %1$s already exists. You can revoke this support access from your <a href="%2$s" target="_blank">Users list</a>.', 'trustedlogin' ),
					array( 'a' => array( 'href' => array(), 'target' => array() ) )
				),
				$plugin_title,
				esc_url( admin_url( 'users.php?role=' . $this->support_role ) )
			),
		);

		return $secondary_alert_translations;
	}

	/**
	 * Init all the settings from the provided TL_Config array.
	 *
	 * @since 0.1.0
	 *
	 * @param array as per TL_Config specification
	 *
	 * @return bool Initialization succeeded
	 */
	protected function init_settings( $config ) {

		if ( is_string( $config ) ) {
			$config = json_decode( $config, true );
		}

		if ( ! is_array( $config ) || empty( $config ) ) {
			return false;
		}

		/**
		 * Filter: Initilizing TrustedLogin settings
		 *
		 * @param array $config {
		 *
		 * @since 0.1.0
		 *
		 * @see trustedlogin-button.php for documentation of array parameters
		 * @todo Move the array documentation here
		 * }
		 */
		$this->settings = apply_filters( 'trustedlogin/init_settings', $config );

		$this->ns = $this->get_setting( 'vendor/namespace' );

		/**
		 * Filter: Set support_role value
		 *
		 * @param string
		 * @param TrustedLogin $this
		 *
		 * @since 0.2.0
		 *
		 */
		$this->support_role = apply_filters( 'trustedlogin/' . $this->ns . '/support_role/slug', $this->ns . '-support', $this );

		/**
		 * Filter: Set endpoint setting name
		 *
		 * @param string
		 * @param TrustedLogin $this
		 *
		 * @since 0.3.0
		 *
		 */
		$this->endpoint_option = apply_filters(
			'trustedlogin_' . $this->ns . '_endpoint_option_title',
			'tl_' . $this->ns . '_endpoint',
			$this
		);

		/**
		 * Filter: Sets the site option name for the Public Key for encryption functions
		 *
		 * @param string $public_key_option
		 * @param TrustedLogin $this
		 *
		 * @since 0.5.0
		 *
		 */
		$this->public_key_option = apply_filters(
			'trustedlogin/' . $this->ns . '/public-key-option',
			$this->ns . '_public_key',
			$this
		);

		$this->key_storage_option  = 'tl_' . $this->ns . '_slt';
		$this->identifier_meta_key = 'tl_' . $this->ns . '_id';
		$this->expires_meta_key    = 'tl_' . $this->ns . '_expires';

		return true;
	}

	/**
	 * Helper Function: Get a specific setting or return a default value.
	 *
	 * @param string $slug - the setting to fetch, nested results are delimited with periods (eg vendor/name => settings['vendor']['name']
	 * @param string $default - if no setting found or settings not init, return this value.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_setting( $slug, $default = false ) {

		if ( ! isset( $this->settings ) || ! is_array( $this->settings ) ) {

			$this->log( 'Settings have not been configured, returning default value', __METHOD__, 'critical' );

			return $default;
		}

		$keys = explode( '/', $slug );

		if ( count( $keys ) > 1 ) {

			$array_ptr = $this->settings;

			$last_key = array_pop( $keys );

			while ( $arr_key = array_shift( $keys ) ) {
				if ( ! array_key_exists( $arr_key, $array_ptr ) ) {
					$this->log( 'Could not find multi-dimension setting. Keys: ' . print_r( $keys, true ), __METHOD__, 'error' );

					return $default;
				}

				$array_ptr = &$array_ptr[ $arr_key ];
			}

			if ( array_key_exists( $last_key, $array_ptr ) ) {
				return $array_ptr[ $last_key ];
			}

		} else {
			if ( array_key_exists( $slug, $this->settings ) ) {
				return $this->settings[ $slug ];
			} else {
				$this->log( 'Setting for slug ' . $slug . ' not found.', __METHOD__, 'error' );

				return $default;
			}
		}
	}

	/**
	 * Create the Support User with custom role.
	 *
	 * @since 0.1.0
	 *
	 * @return int|WP_Error - Array with login response information if created, or WP_Error object if there was an issue.
	 */
	public function create_support_user() {

		$results = array();

		$user_name = sprintf( esc_html__( '%s Support', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) );

		if ( $user_id = username_exists( $user_name ) ) {
			$this->log( 'Support User not created; already exists: User #' . $user_id, __METHOD__, 'notice' );

			return new WP_Error( 'username_exists', sprintf( 'A user with the username %s already exists', $user_name ) );
		}

		$role_setting = $this->get_setting( 'role', array( 'editor' => '' ) );

		// Get the role value from the key
		$clone_role_slug = key( $role_setting );

		$role_exists = $this->support_user_create_role( $this->support_role, $clone_role_slug );

		if ( ! $role_exists ) {
			$this->log( 'Support role could not be created (based on ' . $clone_role_slug . ')', __METHOD__, 'error' );

			return new WP_Error( 'role_not_created', 'Support role could not be created' );
		}

		$user_email = $this->get_setting( 'vendor/email' );

		if ( email_exists( $user_email ) ) {
			$this->log( 'Support User not created; User with that email already exists: ' . $user_email, __METHOD__, 'warning' );

			return new WP_Error( 'user_email_exists', 'Support User not created; User with that email already exists' );
		}

		$userdata = array(
			'user_login'      => $user_name,
			'user_url'        => $this->get_setting( 'vendor/website' ),
			'user_pass'       => wp_generate_password( 64, true, true ),
			'user_email'      => $user_email,
			'role'            => $this->support_role,
			'first_name'      => $this->get_setting( 'vendor/first_name', '' ),
			'last_name'       => $this->get_setting( 'vendor/last_name', '' ),
			'user_registered' => date( 'Y-m-d H:i:s', time() ),
		);

		$new_user_id = wp_insert_user( $userdata );

		if ( is_wp_error( $new_user_id ) ) {
			$this->log( 'Error: User not created because: ' . $new_user_id->get_error_message(), __METHOD__, 'error' );

			return $new_user_id;
		}

		$this->log( 'Support User #' . $new_user_id, __METHOD__, 'info' );

		return $new_user_id;
	}

	/**
	 * Get the ID of the best-guess appropriate admin user
	 *
	 * @since 0.7.0
	 *
	 * @return int|false User ID if there are admins, false if not
	 */
	private function get_reassign_user_id() {

		// TODO: Filter here?
		$admins = get_users( array(
			'role'    => 'administrator',
			'orderby' => 'registered',
			'order'   => 'DESC',
			'number'  => 1,
		) );

		$reassign_id = empty( $admins ) ? null : $admins[0]->ID;

		$this->log( 'Reassign user ID: ' . var_export( $reassign_id, true ), __METHOD__, 'info' );

		return $reassign_id;
	}

	/**
	 *
	 * @param string $identifier - Unique Identifier of the user to delete, or 'all' to remove all support users.
	 *
	 * @since 0.1.0
	 *
	 * @todo get rid of "all" option, since it makes things confusing
	 *
	 * @return bool|WP_Error
	 */
	public function remove_support_user( $identifier = 'all' ) {

		if ( 'all' === $identifier ) {
			$users = $this->get_support_users();
		} else {
			$users = $this->get_support_user( $identifier );
		}

		if ( empty( $users ) ) {
			return false;
		}

		$this->log( count( $users ) . " support users found", __METHOD__, 'debug' );

		if ( $this->settings['reassign_posts'] ) {
			$reassign_id = $this->get_reassign_user_id();
		} else {
			$reassign_id = null;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		foreach ( $users as $_u ) {
			$this->log( "Processing user ID " . $_u->ID, __METHOD__, 'debug' );

			$tlid = get_user_meta( $_u->ID, $this->identifier_meta_key, true );

			// Remove auto-cleanup hook
			wp_clear_scheduled_hook( 'trustedlogin_revoke_access', array( $tlid ) );

			if ( wp_delete_user( $_u->ID, $reassign_id ) ) {
				$this->log( "User: " . $_u->ID . " deleted.", __METHOD__, 'info' );
			} else {
				$this->log( "User: " . $_u->ID . " NOT deleted.", __METHOD__, 'error' );
			}
		}

		if ( count( $users ) < 2 || $identifier == 'all' ) {

			if ( get_role( $this->support_role ) ) {
				remove_role( $this->support_role );
				$this->log( "Role " . $this->support_role . " removed.", __METHOD__, 'info' );
			}

			if ( get_option( $this->endpoint_option ) ) {

				delete_option( $this->endpoint_option );

				flush_rewrite_rules( false );

				update_option( 'tl_permalinks_flushed', 0 );

				$this->log( "Endpoint removed & rewrites flushed", __METHOD__, 'info' );
			}

		}

		return $this->revoke_access( $identifier );
	}

	/**
	 * Generate the keyStoreID parameter as a hash of the site URL with the identifer
	 *
	 * @param $identifier_hash
	 *
	 * @return string This hash will be used as the first part of the URL and also the keyStoreID in the Vault
	 */
	private function get_endpoint_hash( $identifier_hash ) {
		return md5( get_site_url() . $identifier_hash );
	}

	/**
	 * Hooked Action: Decays (deletes a specific support user)
	 *
	 * @param string $identifier_hash Identifier hash for the user associated with the cron job
	 * @param int $user_id
	 *
	 * @since 0.2.1
	 *
	 * @return void
	 */
	public function support_user_decay( $identifier_hash, $user_id = 0 ) {

		$this->log( 'Running cron job to disable user. ID: ' . $identifier_hash, __METHOD__, 'notice' );

		$this->remove_support_user( $identifier_hash );
	}

	/**
	 * Create the custom Support Role if it doesn't already exist
	 *
	 * @param string $new_role_slug - slug for the new role
	 * @param string $clone_role_slug - slug for the role to clone, defaults to 'editor'
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function support_user_create_role( $new_role_slug, $clone_role_slug = 'editor' ) {

		if ( empty( $new_role_slug ) || empty( $clone_role_slug ) ) {
			return false;
		}

		$role_exists = get_role( $new_role_slug );

		if ( $role_exists ) {
			$this->log( 'Not creating user role; it already exists', __METHOD__, 'notice' );

			return true;
		}

		$this->log( 'New role slug: ' . $new_role_slug . ', Clone role slug: ' . $clone_role_slug, __METHOD__, 'debug' );

		$old_role = get_role( $clone_role_slug );

		if ( empty( $old_role ) ) {
			$this->log( 'Error: the role to clone does not exist: ' . $clone_role_slug, __METHOD__, 'critical' );

			return false;
		}

		$capabilities = $old_role->capabilities;

		$extra_caps = $this->get_setting( 'extra_caps' );

		foreach ( (array) $extra_caps as $extra_cap => $reason ) {
			$capabilities[ $extra_cap ] = true;
		}

		// These roles should not be assigned to TrustedLogin roles.
		// TODO: Write doc about this
		$prevent_caps = array(
			'create_users',
			'delete_users',
			'edit_users',
			'promote_users',
			'delete_site',
			'remove_users',
		);

		foreach ( $prevent_caps as $prevent_cap ) {
			unset( $capabilities[ $prevent_cap ] );
		}

		/**
		 * @filter trustedlogin/{namespace}/support_role/display_name Modify the display name of the created support role
		 */
		$role_display_name = apply_filters( 'trustedlogin/' . $this->ns . '/support_role/display_name', sprintf( esc_html__( '%s Support', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ), $this );

		$new_role = add_role( $new_role_slug, $role_display_name, $capabilities );

		return true;
	}


	/**
	 * Get all users with the support role
	 *
	 * @since 0.7.0
	 *
	 * @return array
	 */
	public function get_support_users() {

		$args = array(
			'role' => $this->support_role,
		);

		return get_users( $args );
	}

	/**
	 * Helper Function: Get the generated support user(s).
	 *
	 * @param string $identifier - Unique Identifier
	 *
	 * @since 0.1.0
	 *
	 * @return array of WP_Users
	 */
	public function get_support_user( $identifier = '' ) {

		// When passed in the endpoint URL, the unique ID will be the raw value, not the md5 hash.
		if ( strlen( $identifier ) > 32 ) {
			$identifier = md5( $identifier );
		}

		$args = array(
			'role'       => $this->support_role,
			'number'     => 1,
			'meta_key'   => $this->identifier_meta_key,
			'meta_value' => $identifier,
		);

		return get_users( $args );
	}

	public function adminbar_add_toolbar_items( $admin_bar ) {

		if ( ! current_user_can( $this->support_role ) ) {
			return;
		}

		if ( ! $admin_bar instanceof WP_Admin_Bar ) {
			return;
		}

		$admin_bar->add_menu( array(
			'id'    => 'tl-' . $this->ns . '-revoke',
			'title' => esc_html__( 'Revoke TrustedLogin', 'trustedlogin' ),
			'href'  => admin_url( '/?revoke-tl=' . $this->ns ),
			'meta'  => array(
				'title' => esc_html__( 'Revoke TrustedLogin', 'trustedlogin' ),
				'class' => 'tl-destroy-session',
			),
		) );
	}

	/**
	 * Filter: Update the actions on the users.php list for our support users.
	 *
	 * @since 0.3.0
	 *
	 * @param array $actions
	 * @param WP_User $user_object
	 *
	 * @return array
	 */
	public function user_row_action_revoke( $actions, $user_object ) {

		if ( ! current_user_can( $this->support_role ) && ! current_user_can( 'delete_users' ) ) {
			return $actions;
		}

		$revoke_url = $this->helper_get_user_revoke_url( $user_object );

		if ( ! $revoke_url ) {
			return $actions;
		}

		$actions = array(
			'revoke' => "<a class='trustedlogin tl-revoke submitdelete' href='" . esc_url( $revoke_url ) . "'>" . esc_html__( 'Revoke Access', 'trustedlogin' ) . "</a>",
		);

		return $actions;
	}

	/**
	 * Returns admin URL to revoke support user
	 *
	 * @param WP_User $user_object
	 *
	 * @return string|false Unsanitized URL to revoke support user. If $user_object is not WP_User, or no user meta exists, returns false.
	 */
	public function helper_get_user_revoke_url( $user_object ) {

		if ( ! $user_object instanceof WP_User ) {
			$this->log( '$user_object not a user object: ' . var_export( $user_object ), __METHOD__, 'warning' );

			return false;
		}

		if ( empty( $this->identifier_meta_key ) ) {
			$this->log( 'The meta key to identify users is not set.', __METHOD__, 'error' );

			return false;
		}

		$identifier = get_user_meta( $user_object->ID, $this->identifier_meta_key, true );

		if ( empty( $identifier ) ) {
			return false;
		}

		$revoke_url = add_query_arg( array(
			'revoke-tl' => $this->ns,
			'tlid'      => $identifier,
		), admin_url( 'users.php' ) );

		$this->log( "revoke_url: $revoke_url", __METHOD__, 'debug' );

		return $revoke_url;
	}

	/**
	 * Hooked Action to maybe revoke support if _GET['revoke-tl'] == $this->ns
	 * Can optionally check for _GET['tlid'] for revoking a specific user by their identifier
	 *
	 * @since 0.2.1
	 */
	public function admin_maybe_revoke_support() {

		if ( ! isset( $_GET['revoke-tl'] ) || $this->ns !== $_GET['revoke-tl'] ) {
			return;
		}

		// Allow support team to revoke user
		if ( ! current_user_can( $this->support_role ) && ! current_user_can( 'delete_users' ) ) {
			return;
		}

		if ( isset( $_GET['tlid'] ) ) {
			$identifier = sanitize_text_field( $_GET['tlid'] );
		} else {
			$identifier = 'all';
		}

		$removed_user = $this->remove_support_user( $identifier );

		if ( is_wp_error( $removed_user ) ) {
			$this->log( 'Removing user failed: ' . $removed_user->get_error_message(), __METHOD__, 'error' );
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'delete_users' ) ) {
			wp_redirect( home_url() );
			exit;
		}

		$support_user = $this->get_support_user( $identifier );

		if ( ! empty( $support_user ) ) {
			$this->log( 'User #' . $support_user[0]->ID . ' was not removed', __METHOD__, 'error' );

			return;
		}

		add_action( 'admin_notices', array( $this, 'admin_notice_revoked' ) );
	}

	/**
	 * POSTs to `webhook_url`, if defined in the configuration array
	 *
	 * @since 0.3.1
	 *
	 * @param array $data {
	 *   @type string $url The site URL as returned by get_site_url()
	 *   @type string $action "create" or "revoke"
	 * }
	 *
	 * @return void
	 */
	public function maybe_send_webhook( $data ) {

		$webhook_url = $this->get_setting( 'webhook_url' );

		if ( empty( $webhook_url ) ) {
			return;
		}

		wp_remote_post( $webhook_url, $data );
	}

	/**
	 * @param string $identifier_hash
	 * @param array $data
	 *
	 * @return true|WP_Error
	 */
	public function create_access( $identifier_hash, $data = array() ) {

		$endpoint_hash = $this->get_endpoint_hash( $identifier_hash );

		// Ping SaaS and get back tokens.
		$site_created = $this->create_site( $endpoint_hash );

		// If no tokens received continue to backup option (redirecting to support link)
		if ( is_wp_error( $site_created ) ) {

			$this->log( sprintf( 'There was an issue creating access (%s): %s', $site_created->get_error_code(), $site_created->get_error_message() ), __METHOD__, 'error' );

			return $site_created;
		}

		do_action( 'trustedlogin/access/created', array( 'url' => get_site_url(), 'action' => 'create' ) );

		return true;
	}

	/**
	 * Revoke access to a site
	 *
	 * @param string $identifier Unique ID or "all"
	 *
	 * @return bool Both saas and vault synced. False: either or both failed to sync.
	 */
	public function revoke_access( $identifier = '' ) {

		if ( empty( $identifier ) ) {
			$this->log( "Missing the revoke access identifier.", __METHOD__, 'error' );

			return false;
		}

		$endpoint_hash = $this->get_endpoint_hash( $identifier );

		// Ping SaaS to notify of revoke
		$site_revoked = $this->revoke_site( $endpoint_hash );

		if ( is_wp_error( $site_revoked ) ) {

			// Couldn't sync to SaaS, this should/could be extended to add a cron-task to delayed update of SaaS DB
			// TODO: extend to add a cron-task to delayed update of SaaS DB
			$this->log( "There was an issue syncing to SaaS. Failing silently.", __METHOD__, 'error' );

			return $site_revoked;
		}

		do_action( 'trustedlogin/access/revoked', array( 'url' => get_site_url(), 'action' => 'revoke' ) );

		return $saas_revoke;
	}

	/**
	 * Get the license key for the current user.
	 *
	 * @since 0.7.0
	 *
	 * @return string
	 */
	function get_license_key() {

		// TODO: Make sure this setting is set when initializing the class.
		$license_key = $this->get_setting( 'auth/license_key', 'NOT SET!!!!' );

		/**
		 * Filter: Allow for over-riding the 'accessKey' sent to SaaS platform
		 *
		 * @param string|null
		 *
		 * @since 0.4.0
		 *
		 */
		$license_key = apply_filters( 'trustedlogin/' . $this->ns . '/licence_key', $license_key );

		return $license_key;
	}

	/**
	 * Creates a site in TrustedLogin using the identifier hash as the keyStoreID
	 *
	 * Stores the tokens in the options table under $this->key_storage_option
	 *
	 * @todo Convert false returns to WP_Error
	 * @uses `get_encryption_key()` to get the Public Key.
	 *
	 * @param string $identifier Unique ID used across this site and TrustedLogin
	 *
	 * @return true|WP_Error If successful, returns true. Otherwise, returns WP_Error object.
	 */
	public function create_site( $identifier ) {

		/**
		 * Filter: Override the public key functions.
		 *
		 * @since 0.5.0
		 *
		 * @param string $encryption_key
		 * @param TrustedLogin $this
		 */
		$encryption_key = apply_filters( 'trustedlogin/' . $this->ns . '/public_key', $this->get_encryption_key(), $this );

		if ( is_wp_error( $encryption_key ) ) {
			return new WP_Error(
				'no_key',
				sprintf(
					'No public key has been provided by %1$s with this message: %2$s',
					$this->get_setting( 'vendor/title' ),
					$encryption_key->get_error_message()
				)
			);
		}


		$data = array(
			'publicKey'  => $this->get_setting( 'auth/api_key' ),
			'accessKey'  => $this->get_license_key(),
			'siteUrl'    => $this->encrypt( get_site_url(), $encryption_key ),
			'keyStoreID' => $this->encrypt( $identifier, $encryption_key ),
		);

		$api_response = $this->api_send( 'sites', $data, 'POST' );

		$response_json = $this->handle_response( $api_response, array( 'token', 'deleteKey' ) );

		if ( is_wp_error( $response_json ) ) {
			return $response_json;
		}

		// handle short-lived tokens for TrustedLogin
		$keys = array(
			'vaultToken' => $response_json['token'],
			'deleteKey'  => $response_json['deleteKey'],
		);

		$this->set_vault_tokens( $keys );

		return true;
	}

	/**
	 * Revoke a site in TrustedLogin
	 *
	 * @since 0.4.1
	 *
	 * @param string $action - is the TrustedLogin being created or removed ('new' or 'revoke' respectively)
	 * @param string $vault_keyStoreID - the unique identifier of the entry in the Vault Keystore
	 *
	 * @return true|WP_Error Was the sync to TrustedLogin successful
	 */
	public function revoke_site( $vault_keyStoreID ) {

		$deleteKey = $this->get_vault_tokens( 'deleteKey' );

		if ( empty( $deleteKey ) ) {
			$this->log( "deleteKey is not set; revoking site will not work.", __METHOD__, 'error' );

			return new WP_Error( 'missing_delete_key', 'Revoking site failed: deleteKey is not set.' );
		}

		$api_response = $this->api_send( 'sites/' . $deleteKey, null, 'DELETE' );

		$response = $this->handle_response( $api_response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// remove the site option
		// TODO: Should we delete this even when the SaaS response fails?
		$deleted = delete_option( $this->key_storage_option );

		if ( ! $deleted ) {
			$this->log( "delete_option failed for 'tl_{$this->ns}_slt' key. Perhaps was already deleted.", __METHOD__, 'warning' );
		}

		return true;
	}

	/**
	 * API Response Handler - SaaS side
	 *
	 * @since 0.4.1
	 *
	 * @param array|WP_Error $api_response - the response from HTTP API
	 * @param array $required_keys If the response JSON must have specific keys in it, pass them here
	 *
	 * @return array|WP_Error - If successful response, returns array of JSON data. If failed, returns WP_Error.
	 */
	public function handle_response( $api_response, $required_keys = array(), $action = '' ) {

		if ( is_wp_error( $api_response ) ) {

			$this->log( sprintf( 'Request error (Code %s): %s', $api_response->get_error_code(), $api_response->get_error_message() ), __METHOD__, 'error' );

			return $api_response;
		}

		$this->log( "Response: " . print_r( $api_response, true ), __METHOD__, 'error' );

		$response_body = wp_remote_retrieve_body( $api_response );

		if ( empty( $response_body ) ) {
			$this->log( "Response body not set: " . print_r( $response_body, true ), __METHOD__, 'error' );

			return new WP_Error( 'missing_response_body', 'The response was invalid.', $api_response );
		}

		$response_json = json_decode( $response_body, true );

		switch ( wp_remote_retrieve_response_code( $api_response ) ) {

			// Unauthenticated
			case 401:
				return new WP_Error( 'unauthenticated', 'Authentication failed.', $response_body );
				break;

			// Problem with Token
			case 403:
				return new WP_Error( 'invalid_token', 'Invalid tokens.', $response_body );
				break;

			// the KV store was not found, possible issue with endpoint
			case 404:
				return new WP_Error( 'not_found', 'The TrustedLogin site is not currently available.', $response_body );
				break;

			// Server issue
			case 500:
				return new WP_Error( 'unavailable', 'The TrustedLogin site is not currently available.', $response_body );
				break;

			// wp_remote_retrieve_response_code() couldn't parse the $api_response
			case '':
				return new WP_Error( 'invalid_response', 'Invalid response.', $response_body );
				break;
		}

		if ( empty( $response_json ) ) {
			return new WP_Error( 'invalid_response', 'Invalid response.', $response_body );
		}

		foreach ( (array) $required_keys as $required_key ) {
			if ( ! isset( $response_json[ $required_key ] ) ) {
				return new WP_Error( 'missing_required_key', 'Invalid response. Missing key: ' . $required_key, $response_body );
			}
		}

		return $response_json;
	}

	/**
	 * @param array $keys
	 *
	 * @return bool False if value was not updated. True if value was updated.
	 */
	private function set_vault_tokens( array $keys ) {
		return update_option( $this->key_storage_option, $keys );
	}

	/**
	 * Returns token value(s) from the key store
	 *
	 * @since 0.7.0
	 *
	 * @param string|null $token Name of token, either vaultToken or deleteKey. If null, returns whole saved array.
	 *
	 * @return false|string If vault not found, false. Otherwise, the value at $token.
	 */
	private function get_vault_tokens( $token = null ) {

		$key_storage = get_option( $this->key_storage_option, false );

		if ( ! $key_storage ) {
			$this->log( "Could not get vault token; keys not yet stored.", __METHOD__, 'error' );

			return false;
		}

		if ( $token && ! isset( $key_storage[ $token ] ) ) {
			$this->log( "vaultToken not set in key store: " . print_r( $key_storage, true ), __METHOD__, 'error' );

			return false;
		}

		if ( $token ) {
			return $key_storage[ $token ];
		}

		return $key_storage;
	}

	/**
	 * API Function: send the API request
	 *
	 * @since 0.4.0
	 *
	 * @param string $path - the path for the REST API request (no initial or trailing slash needed)
	 * @param array $data
	 * @param array $addition_header - any additional headers required for auth/etc
	 *
	 * @return array|WP_Error|false wp_remote_post() response, or false if `$method` isn't valid
	 */
	public function api_send( $path, $data, $method, $additional_headers = array() ) {

		if ( ! in_array( $method, array( 'POST', 'PUT', 'GET', 'PUSH', 'DELETE' ) ) ) {
			$this->log( "Error: Method not in allowed array list ($method)", __METHOD__, 'critical' );

			return false;
		}

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);

		if ( ! empty( $additional_headers ) ) {
			$headers = array_merge( $headers, $additional_headers );
		}

		$request_options = array(
			'method'      => $method,
			'timeout'     => 45,
			'httpversion' => '1.1',
			'headers'     => $headers,
			'body'        => ( $data ? json_encode( $data ) : null ),
		);

		/**
		 * Modifies the endpoint URL for the TrustedLogin service.
		 *
		 * @param string $url URL to TrustedLogin API
		 *
		 * @internal This allows pointing requests to testing servers
		 */
		$url = apply_filters( 'trustedlogin/api-url', self::saas_api_url ) . $path;

		$this->log( sprintf( 'Sending to %s: %s', $url, print_r( $request_options, true ) ), __METHOD__, 'debug' );

		$response = wp_remote_request( $url, $request_options );

		$this->log( sprintf( 'Response: %s', print_r( $response, true ) ), __METHOD__, 'debug' );

		return $response;
	}

	/**
	 * Notice: Shown when a support user is manually revoked by admin;
	 *
	 * @since 0.3.0
	 */
	public function admin_notice_revoked() {
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( sprintf( __( 'Done! %s Support access revoked. ', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Saves the Public key from Vendor to the Local DB
	 *
	 * @since 0.5.0
	 *
	 * @param string $public_key
	 *
	 * @return true|WP_Error  True if successful, otherwise WP_Error
	 */
	private function update_encryption_key( $public_key ) {

		if ( empty( $public_key ) ) {
			return new WP_Error( 'no_public_key', 'No key provided.' );
		}

		$saved = update_site_option( $this->public_key_option, $public_key );

		if ( ! $saved ) {
			return new WP_Error( 'db_save_error', 'Could not save key to database' );
		}

		return true;
	}

	/**
	 * Fetches the Public Key from the `TrustedLogin-vendor` plugin on support website.
	 *
	 * @since 0.5.0
	 *
	 * @return string|WP_Error  If successful, will return the Public Key string. Otherwise WP_Error on failure.
	 */
	private function get_remote_encryption_key() {

		$vendor_url   = $this->get_setting( 'vendor/website' );
		$key_endpoint = apply_filters( 'trustedlogin/vendor/public-key-endpoint', 'wp-json/trustedlogin/v1/public_key' );

		$url = trailingslashit( $vendor_url ) . $key_endpoint;

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);

		$request_options = array(
			'method'      => 'GET',
			'timeout'     => 45,
			'httpversion' => '1.1',
			'headers'     => $headers
		);

		$response = wp_remote_request( $url, $request_options );

		$response_json = $this->handle_response( $response, array( 'publicKey' ) );

		if ( is_wp_error( $response_json ) ) {
			return $response_json;
		}

		return $response_json['publicKey'];
	}

	/**
	 * Fetches the Public Key from the local DB, if it exists.
	 *
	 * @since 0.5.0
	 *
	 * @return string|WP_Error  The Public Key or a WP_Error if none is found.
	 */
	private function get_local_encryption_key() {

		$public_key = get_site_option( $this->public_key_option, false );

		if ( empty( $public_key ) ) {
			return new WP_Error( 'no_local_key', 'There is no public key stored in the DB' );
		}

		return $public_key;
	}


	/**
	 * Fetches the Public Key from local or db
	 *
	 * @since 0.5.0
	 *
	 * @return string|WP_Error  If found, it returns the publicKey, if not a WP_Error
	 */
	public function get_encryption_key() {

		$local_key = $this->get_local_encryption_key();
		if ( ! is_wp_error( $local_key ) ) {
			return $local_key;
		}

		$remote_key = $this->get_remote_encryption_key();
		if ( is_wp_error( $remote_key ) ) {
			return $remote_key;
		}

		$saved = $this->update_encryption_key( $remote_key );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $remote_key;
	}

	/**
	 * Encrypts a string using the Public Key provided by the plugin/theme developers' server.
	 *
	 * @since 0.5.0
	 * @uses `openssl_public_encrypt()` for encryption.
	 *
	 * @param string $data Data to encrypt.
	 * @param string $public_key Key to use to encrypt the data.
	 *
	 * @return string|WP_Error  Encrypted envelope or WP_Error on failure.
	 */
	private function encrypt( $data, $public_key ) {

		if ( empty( $data ) || empty( $public_key ) ) {
			return new WP_Error( 'no_data', 'No data provided.' );
		}

		openssl_public_encrypt( $data, $encrypted, $public_key, OPENSSL_PKCS1_OAEP_PADDING );

		if ( empty( $encrypted ) ) {

			$error_string = '';
			while ( $msg = openssl_error_string() ) {
				$error_string .= "\n" . $msg;
			}

			return new WP_Error (
				'encryption_failed',
				sprintf(
					'Could not encrypt envelope. Errors from openssl: %1$s',
					$error_string
				)
			);
		}

		$encrypted = base64_encode( $encrypted );

		return $encrypted;
	}

}
