<?php
if (!defined('ABSPATH')) {
    exit;
}
// Exit if accessed directly

class TrustedLogin {

	use TL_Debug_Logging;

	/**
	 * @var string $version - the current drop-in file version
	 * @since 0.1.0
	 */
	const version = '0.4.2';

	/**
	 * @var string self::saas_api_url - the API url for the TrustedLogin SaaS Platform
	 * @since 0.4.0
	 */
    const saas_api_url = 'https://app.trustedlogin.com/api/';

	/**
	 * @var string The API url for the TrustedLogin Vault Platform
	 * @since 0.3.0
	 */
	const vault_api_url = 'https://vault.trustedlogin.com/';

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
	 * @var string $identifier_option - The namespaced setting name for storing the unique identifier hash
	 * @example tl_{vendor/namespace}_id
	 * @since 0.7.0
	 */
	private $identifier_option;

	/**
	 * @var int $expires_option - [Currently not used] The namespaced setting name for storing the timestamp the user expires
	 * @example tl_{vendor/namespace}_expires
	 * @since 0.7.0
	 */
	private $expires_option;

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

	public function __construct( $config ) {

		/**
		 * Filter: Whether debug logging is enabled in trustedlogin drop-in
		 *
		 * @since 0.4.2
		 *
		 * @param bool
		 */
		$this->debug_mode = apply_filters( 'trustedlogin_debug_enabled', true );

		// TODO: Show error when config hasn't happened.
		if ( empty( $config ) ) {
			$this->dlog( 'No config settings passed to constructor', __METHOD__ );
			return;
		}

        $this->is_initialized = $this->init_settings( $config );

		$this->init_hooks();

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

		add_action( 'tl_destroy_sessions', array( $this, 'support_user_decay' ), 10, 1 );

		if ( is_admin() ) {
			add_action( 'wp_ajax_tl_gen_support', array( $this, 'ajax_gen_support' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );

			add_action( 'trustedlogin_button', array( $this, 'output_tl_button' ), 10, 2 );

			add_filter( 'user_row_actions', array( $this, 'user_row_action_revoke' ), 10, 2 );

			// add_action('trustedlogin_button', array($this, 'output_support_users'), 20);
		}

		add_action( 'admin_bar_menu', array( $this, 'adminbar_add_toolbar_items' ), 100 );

		add_action( 'admin_init', array( $this, 'admin_maybe_revoke_support' ), 100 );

		// Endpoint Hooks
		add_action( 'init', array( $this, 'add_support_endpoint' ), 10 );
		add_action( 'template_redirect', array( $this, 'maybe_login_support' ), 99 );

		add_action( 'trustedlogin/access/created', array( $this, 'send_webhook' ) );
		add_action( 'trustedlogin/access/revoked', array( $this, 'send_webhook' ) );
	}

	/**
	 * Hooked Action: Add a unique endpoint to WP if a support agent exists
	 *
	 * @since 0.3.0
	 */
	public function add_support_endpoint() {
		$endpoint = get_option( $this->endpoint_option );

		add_rewrite_endpoint( $endpoint, EP_ROOT );

		if ( $endpoint && ! get_option( 'tl_permalinks_flushed' ) ) {

			flush_rewrite_rules( false );
			$this->dlog( "Endpoint $endpoint added.", __METHOD__ );

			update_option( 'tl_permalinks_flushed', 1 );
			$this->dlog( "Rewrite rules flushed.", __METHOD__ );
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

		$_u = $users[0];

		wp_set_current_user( $_u->ID, $_u->user_login );
		wp_set_auth_cookie( $_u->ID );

		do_action( 'wp_login', $_u->user_login, $_u );

		wp_redirect( admin_url() );
		exit();
	}

	/**
	 * AJAX handler for maybe generating a Support User
	 *
	 * @since 0.2.0
	 * @return string JSON result
	 */
	public function ajax_gen_support() {

		$nonce = $_POST['_nonce'];

		if ( empty( $_POST ) ) {
			wp_send_json_error( array( 'message' => 'Auth Issue' ) );
		}

		$this->dlog( print_r( $_POST, true ), __METHOD__ );

		if ( ! check_ajax_referer( 'tl_nonce-' . get_current_user_id(), '_nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Verification Issue' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permissions Issue' ) );
		}

		$support_user_id = $this->create_support_user();

		if ( ! $support_user_id ) {
			$this->dlog( 'Support User not created.', __METHOD__ );
			wp_send_json_error( array( 'message' => 'Support User already exists' ), 409 );
		}

		$identifier_hash = $this->get_identifier_hash();

		$endpoint = $this->update_endpoint( $identifier_hash );

		// Add user meta, configure decay
		$did_setup = $this->support_user_setup( $support_user_id, $identifier_hash );

		if ( ! $did_setup ) {
			wp_send_json_error( array( 'message' => 'Error updating user' ), 503 );
		}

		$return_data = array(
			'siteurl'    => get_site_url(),
			'endpoint'   => $endpoint,
			'identifier' => $identifier_hash,
		);

		$synced = $this->create_access( $identifier_hash );

		if ( $synced ) {
			wp_send_json_success( $return_data, 201 );
		}

		$return_data['message'] = 'Sync Issue';
		wp_send_json_error( $return_data, 503 );
	}

	/**
	 * Updates the site's endpoint to listen for logins
	 *
	 * @param string $identifier_hash
	 */
	public function update_endpoint( $identifier_hash ) {

		$endpoint = $this->get_endpoint_hash( $identifier_hash );

		// Setup endpoint
		update_option( $this->endpoint_option, $endpoint, true );

		return $endpoint;
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
	 * @return bool Whether the user meta was successfully retrieved from the new user
	 */
	public function support_user_setup( $user_id, $identifier_hash ) {

		$decay_time = $this->get_setting( 'decay', 300 );

		$expiry = time() + $decay_time;

		if ( $decay_time ) {

			$scheduled_decay = wp_schedule_single_event(
				time() + $decay_time,
				'tl_destroy_sessions',
				array( md5( $identifier_hash ) )
			);

			$this->dlog( 'Scheduled Decay: ' . var_export( $scheduled_decay, true ) . '; identifier: ' . $results['identifier'], __METHOD__ );
		}

		add_user_meta( $user_id, $this->identifier_option, md5( $identifier_hash ), true );
		add_user_meta( $user_id, $this->expires_option, $expiry );
		add_user_meta( $user_id, 'tl_created_by', get_current_user_id() );

		// Make extra sure that the identifier was saved. Otherwise, things won't work!
		return get_user_meta( $user_id, $this->identifier_option, true );
	}

	/**
	 * Register the required scripts and styles for wp-admin
	 *
	 * @since 0.2.0
	 */
	public function enqueue_admin() {

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
			plugin_dir_url( dirname( __FILE__ ) ) . '/assets/trustedlogin.js',
			array( 'jquery', 'jquery-confirm' ),
			self::version . ( $this->debug_mode ? rand( 0, 10000 ) : '' ),
			true
		);

		wp_register_style(
			'trustedlogin',
			plugin_dir_url( dirname( __FILE__ ) ) . '/assets/trustedlogin.css',
			array(),
			self::version . ( $this->debug_mode ? rand( 0, 10000 ) : '' ),
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

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $print ) ) {
			$print = true;
		}

		wp_enqueue_script( 'jquery-confirm' );
		wp_enqueue_style( 'jquery-confirm' );
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
			'text'        => sprintf( __( 'Grant %s Support Access', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ),
			'exists_text' => sprintf( __( '✅ %s Support Has An Account', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ),
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

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// The `trustedlogin_button` action passes an empty string
		if ( '' === $print ) {
			$print = true;
		}

		$users = $this->get_support_users();

		if ( 0 === count( $users ) ) {

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
				__( 'User', 'trustedlogin' ),
				__( 'Role', 'trustedlogin' ),
				__( 'Created At', 'trustedlogin' ),
				__( 'Created By', 'trustedlogin' ),
				__( 'Revoke Access', 'trustedlogin' )
			);

		$return .= $table_header;

		$return .= '<tbody>';

		foreach ( $users as $_u ) {

			$this->dlog( 'tl_created_by:' . get_user_meta( $_u->ID, 'tl_created_by', true ), __METHOD__ );

			$_gen_u = get_user_by( 'id', get_user_meta( $_u->ID, 'tl_created_by', true ) );

			$this->dlog( 'g_u:' . print_r( $_gen_u, true ) );

			$_udata = get_userdata( $_u->ID );

			$return .= '<tr><th scope="row"><a href="' . admin_url( 'user-edit.php?user_id=' . $_u->ID ) . '">' . sprintf( '%s (#%d)', esc_html( $_u->display_name ), $_udata->ID ) . '</th>';

			if ( count( $_u->roles ) > 1 ) {
				$roles = trim( '<code>' . implode( '</code>,<code>', $_u->roles ) . '</code>', ',' );
			} else {
				$roles = '<code>' . esc_html( $_u->roles[0] ) . '</code>';
			}
			$return .= '<td>' . $roles . '</td>';

			$return .= '<td>' . date( 'd M Y', strtotime( $_udata->user_registered ) ) . '</td>';
			if ( $_gen_u ) {
				$return .= '<td>' . ( $_gen_u->exists() ? $_gen_u->display_name : __( 'Unknown', 'trustedlogin' ) ) . '</td>';
			} else {
				$return .= '<td>' . __( 'Unknown', 'trustedlogin' ) . '</td>';
			}

			$revoke_url = $this->helper_get_user_revoke_url( $_udata, true );

			if ( $revoke_url ) {
				$return .= '<td><a class="trustedlogin tl-revoke submitdelete" href="' . esc_url( $revoke_url ) . '">' . __( 'Revoke Access', 'trustedlogin' ) . '</a></td>';
			} else {
				$return .= '<td><a href="' . admin_url( 'users.php' ) . '">' . __( 'Manage from Users list', 'trustedlogin' ) . '</a></td>';
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
				$this->dlog( 'Error: Decay time should be an integer. Instead: ' . var_export( $decay_time, true ), __METHOD__ );
				$decay_time = intval( $decay_time );
			}

			$decay_diff = human_time_diff( time() + $decay_time, time() );

			$details .= '<h4>' . sprintf( __( 'Access will be granted for %1$s and can be revoked at any time.', 'trustedlogin' ), $decay_diff ) . '</h4>';
		}


		$result['details'] = $details;

		return $result;

	}

	/**
	 * Helper function: Build translate-able strings for alert messages
	 *
	 * @since 0.4.3
	 * @return array of strings
	 */
	public function output_secondary_alerts() {

		$plugin_title = $this->get_setting( 'vendor/title' );

		$no_sync_content = '<p>' .
		                   sprintf(
			                   __( 'Unfortunately, the Support User details could not be sent to %1$s automatically.', 'trustedlogin' ),
			                   $plugin_title
		                   ) . '</p><p>' .
		                   sprintf(
			                   wp_kses(
				                   __( 'Please <a href="%1$s" target="_blank">click here</a> to go to %2$s Support Site', 'trustedlogin' ),
				                   array( 'a' => array( 'href' => array() ) )
			                   ),
			                   esc_url( $this->get_setting( 'vendor/support_url' ) ),
			                   $plugin_title
		                   ) . '</p>';

		$secondary_alert_translations = array(
			'confirmButton'      => __( 'Confirm', 'trustedlogin' ),
			'okButton'           => __( 'OK', 'trustedlogin' ),
			'noSyncTitle'        => sprintf(
				__( 'Error syncing Support User to %1$s', 'trustedlogin' ),
				$plugin_title
			),
			'noSyncContent'      => $no_sync_content,
			'noSyncProTip'       => sprintf(
				__( 'Pro-tip: By sharing the URL below with %1$s supprt will give them temporary support access', 'trustedlogin' ),
				$plugin_title
			),
			'noSyncGoButton'     => sprintf(
				__( 'Go to %1$s support site', 'trustedlogin' ),
				$plugin_title
			),
			'noSyncCancelButton' => __( 'Close', 'trustedlogin' ),
			'syncedTitle'        => __( 'Support access granted', 'trustedlogin' ),
			'syncedContent'      => sprintf(
				__( 'A temporary support user has been created, and sent to %1$s Support.', 'trustedlogin' ),
				$plugin_title
			),
			'cancelButton'       => __( 'Cancel', 'trustedlogin' ),
			'cancelTitle'        => __( 'Action Cancelled', 'trustedlogin' ),
			'cancelContent'      => sprintf(
				__( 'A support account for %1$s has NOT been created.', 'trustedlogin' ),
				$plugin_title
			),
			'failTitle'          => __( 'Support Access NOT Granted', 'trustedlogin' ),
			'failContent'        => __( 'Got this from the server: ', 'trustedlogin' ),
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

		if( is_string( $config ) ) {
			$config = json_decode( $config, true );
		}

		if ( ! is_array( $config ) || empty( $config ) ) {
			return false;
		}

		/**
		 * Filter: Initilizing TrustedLogin settings
		 *
		 * @since 0.1.0
		 *
		 * @param array $config {
		 *
		 * @see trustedlogin-button.php for documentation of array parameters
		 * @todo Move the array documentation here
		 * }
		 */
		$this->settings = apply_filters( 'trustedlogin_init_settings', $config );

		$this->ns = $this->get_setting( 'vendor/namespace' );

		/**
		 * Filter: Set support_role value
		 *
		 * @since 0.2.0
		 *
		 * @param string
		 * @param TrustedLogin $this
		 */
		$this->support_role = apply_filters( 'trustedlogin/' . $this->ns . '/support_role/slug', $this->ns . '-support', $this );

		/**
		 * Filter: Set endpoint setting name
		 *
		 * @since 0.3.0
		 *
		 * @param string
		 * @param TrustedLogin $this
		 */
		$this->endpoint_option = apply_filters(
			'trustedlogin_' . $this->ns . '_endpoint_option_title',
			'tl_' . $this->ns . '_endpoint',
			$this
		);

		$this->key_storage_option = 'tl_' . $this->ns . '_slt';

		return true;
	}

	/**
	 * Helper Function: Get a specific setting or return a default value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug - the setting to fetch, nested results are delimited with periods (eg vendor/name => settings['vendor']['name']
	 * @param string $default - if no setting found or settings not init, return this value.
	 *
	 * @return string
	 */
	public function get_setting( $slug, $default = false ) {

		if ( ! isset( $this->settings ) || ! is_array( $this->settings ) ) {
			$this->dlog( 'Settings have not been configured, returning default value', __METHOD__ );

			return $default;
		}

		$keys = explode( '/', $slug );

		if ( count( $keys ) > 1 ) {

			$array_ptr = $this->settings;

			$last_key = array_pop( $keys );

			while ( $arr_key = array_shift( $keys ) ) {
				if ( ! array_key_exists( $arr_key, $array_ptr ) ) {
					$this->dlog( 'Could not find multi-dimension setting. Keys: ' . print_r( $keys, true ), __METHOD__ );

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
				$this->dlog( 'Setting for slug ' . $slug . ' not found.', __METHOD__ );

				return $default;
			}
		}
	}

	/**
	 * Create the Support User with custom role.
	 *
	 * @since 0.1.0
	 * @return array|false - Array with login response information if created, or false if there was an issue.
	 */
	public function create_support_user() {

		$results = array();

		$user_name = sprintf( esc_html__( '%s Support', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) );

		if ( $user_id = username_exists( $user_name ) ) {
			$this->dlog( 'Support User not created; already exists: User #' . $user_id, __METHOD__ );

			return false;
		}

		$role_setting = $this->get_setting( 'role', array( 'editor' => '' ) );

		// Get the role value from the key
		$clone_role_slug = key( $role_setting );

		$role_exists = $this->support_user_create_role( $this->support_role, $clone_role_slug );

		if ( ! $role_exists ) {
			$this->dlog( 'Support role could not be created (based on ' . $clone_role_slug . ')', __METHOD__ );

			return false;
		}

		$user_email = $this->get_setting( 'vendor/email' );

		if ( email_exists( $user_email ) ) {
			$this->dlog( 'Support User not created; User with that email already exists: ' . $user_email, __METHOD__ );

			return false;
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
			$this->dlog( 'Error: User not created because: ' . $new_user_id->get_error_message(), __METHOD__ );

			return false;
		}

		$this->dlog( 'Support User #' . $new_user_id, __METHOD__ );

		return $new_user_id;
	}

	/**
	 * Destroy one or all of the Support Users
	 *
	 * @since 0.1.0
	 *
	 * @todo get rid of "all" option, since it makes things confusing
	 *
	 * @param string $identifier - Unique Identifier of the user to delete, or 'all' to remove all support users.
	 *
	 * @return Bool
	 */
	public function support_user_destroy( $identifier = 'all' ) {

		if ( 'all' === $identifier ) {
			$users = $this->get_support_users();
		} else {
			$users = $this->get_support_user( $identifier );
		}

		$this->dlog( count( $users ) . " support users found", __METHOD__ );

		$reassign_id = null;

		if ( $this->settings['reassign_posts'] ) {
			$admins = get_users(
				array(
					'role'    => 'administrator',
					'orderby' => 'registered',
					'order'   => 'DESC',
					'number'  => 1,
				)
			);
			if ( ! empty( $admins ) ) {
				$reassign_id = $admins[0]->ID;
			}
		}

		$this->dlog( "reassign_id: $reassign_id", __METHOD__ );

		if ( empty( $users ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		foreach ( $users as $_u ) {
			$this->dlog( "Processing uid " . $_u->ID, __METHOD__ );

			$tlid = get_user_meta( $_u->ID, $this->identifier_option, true );

			// Remove auto-cleanup hook
			wp_clear_scheduled_hook( 'tl_destroy_sessions', array( $tlid ) );

			if ( wp_delete_user( $_u->ID, $reassign_id ) ) {
				$this->dlog( "User: " . $_u->ID . " deleted.", __METHOD__ );
			} else {
				$this->dlog( "User: " . $_u->ID . " NOT deleted.", __METHOD__ );
			}
		}

		if ( count( $users ) < 2 || $identifier == 'all' ) {

			if ( get_role( $this->support_role ) ) {
				remove_role( $this->support_role );
				$this->dlog( "Role " . $this->support_role . " removed.", __METHOD__ );
			}

			if ( get_option( $this->endpoint_option ) ) {

				delete_option( $this->endpoint_option );

				flush_rewrite_rules( false );

				update_option( 'tl_permalinks_flushed', 0 );

				$this->dlog( "Endpoint removed & rewrites flushed", __METHOD__ );
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
	 * @since 0.2.1
	 *
	 * @param string $identifier_hash Identifier hash for the user associated with the cron job
	 * @param Int $user_id
	 *
	 * @return none
	 */
	public function support_user_decay( $identifier_hash, $user_id ) {

		$this->dlog( 'Disabling user with id: ' . $identifier_hash, __METHOD__ );

		$this->support_user_destroy( $identifier_hash );
	}

	/**
	 * Create the custom Support Role if it doesn't already exist
	 *
	 * @since 0.1.0
	 *
	 * @param string $new_role_slug - slug for the new role
	 * @param string $clone_role_slug - slug for the role to clone, defaults to 'editor'
	 *
	 * @return bool
	 */
	public function support_user_create_role( $new_role_slug, $clone_role_slug = 'editor' ) {

		if ( empty( $new_role_slug ) || empty( $clone_role_slug ) ) {
			return false;
		}

		$role_exists = get_role( $new_role_slug );

		if ( $role_exists ) {
			$this->dlog( 'Not creating user role; it already exists', __METHOD__ );

			return true;
		}

		$this->dlog( 'N: ' . $new_role_slug . ', O: ' . $clone_role_slug, __METHOD__ );

		$old_role = get_role( $clone_role_slug );

		if ( empty( $old_role ) ) {
			$this->dlog( 'Error: the role to clone does not exist: ' . $clone_role_slug, __METHOD__ );

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
		$role_display_name = apply_filters( 'trustedlogin/' . $this->ns . '/support_role/display_name', sprintf( esc_html__( '%s Support', 'trustedlogin' ), $this->get_setting('vendor/title') ), $this );

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
	 * @since 0.1.0
	 *
	 * @param string $identifier - Unique Identifier
	 *
	 * @return array of WP_Users
	 */
	public function get_support_user( $identifier = '' ) {

		$this->dlog( "Id length: " . strlen( $identifier ), __METHOD__ );

		if ( strlen( $identifier ) > 32 ) {
			$identifier = md5( $identifier );
		}

		$args = array(
			'role'       => $this->support_role,
			'number'     => 1,
			'meta_key'   => $this->identifier_option,
			'meta_value' => $identifier,
		);

		return get_users( $args );
	}

	public function adminbar_add_toolbar_items( $admin_bar ) {

		if ( ! current_user_can( $this->support_role ) ) {
			return;
		}

		$admin_bar->add_menu( array(
			'id'    => 'tl-' . $this->ns . '-revoke',
			'title' => __( 'Revoke TrustedLogin', 'trustedlogin' ),
			'href'  => admin_url( '/?revoke-tl=si' ),
			'meta'  => array(
				'title' => __( 'Revoke TrustedLogin', 'trustedlogin' ),
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

		if ( ! current_user_can( $this->support_role ) && ! current_user_can( 'manage_options' ) ) {
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
			$this->dlog( '$user_object not a user object: ' . var_export( $user_object ), __METHOD__ );

			return false;
		}

		$identifier = get_user_meta( $user_object->ID, $this->identifier_option, true );

		if ( empty( $identifier ) ) {
			return false;
		}

		$revoke_url = add_query_arg( array(
			'revoke-tl' => 'si',
			'tlid'      => $identifier,
		), admin_url( 'users.php' ) );

		$this->dlog( "revoke_url: $revoke_url", __METHOD__ );

		return $revoke_url;
	}

	/**
	 * Hooked Action to maybe revoke support if _GET['revoke-tl'] == 'si'
	 * Can optionally check for _GET['tlid'] for revoking a specific user by their identifier
	 *
	 * @since 0.2.1
	 */
	public function admin_maybe_revoke_support() {

		if ( ! isset( $_GET['revoke-tl'] ) || 'si' !== $_GET['revoke-tl'] ) {
			return;
		}

		$success = false;

		// Allow support team to revoke user
		if ( ! current_user_can( $this->support_role ) && ! current_user_can( 'delete_users' ) ) {
			return;
		}

		if ( isset( $_GET['tlid'] ) ) {
			$identifier = sanitize_text_field( $_GET['tlid'] );
		} else {
			$identifier = 'all';
		}

		$this->support_user_destroy( $identifier );

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_redirect( home_url() );
			exit;
		}

		$support_user = $this->get_support_user( $identifier );

		if ( empty( $support_user ) ) {
			add_action( 'admin_notices', array( $this, 'admin_notice_revoked' ) );
		}

	}

	/**
	 * Wrapper for sending Webhook Notification to Support System
	 *
	 * @since 0.3.1
	 *
	 * @param array $data
	 *
	 * @return Bool if the webhook responded sucessfully
	 */
	public function send_webhook( $data ) {

		$webhook_url = $this->get_setting( 'webhook_url' );

		if ( ! empty( $webhook_url ) ) {
			wp_remote_post( $webhook_url, $data );
		}
	}

	/**
	 * @param string $identifier_hash
	 * @param array $data
	 *
	 * @return bool
	 */
	public function create_access( $identifier_hash, $data = array() ) {

		$endpoint_hash = $this->get_endpoint_hash( $identifier_hash );

		// Ping SaaS and get back tokens.
		$saas_sync = $this->saas_create_site( $endpoint_hash );

		// If no tokens received continue to backup option (redirecting to support link)
		if ( ! $saas_sync ) {
			$this->dlog( "There was an issue syncing to SaaS for $action. Bouncing out to redirect.", __METHOD__ );

			return false;
		}

		// Else ping the envelope into vault, trigger webhook fire
		$vault_sync = $this->vault_sync_wrapper( $endpoint_hash, $data, 'POST' );

		if ( ! $vault_sync ) {
			$this->dlog( "There was an issue syncing to Vault for $action. Bouncing out to redirect.", __METHOD__ );

			return false;
		}

		do_action( 'trustedlogin/access/created', array( 'url' => get_site_url(), 'action' => 'create' ) );
	}

	/**
	 * Revoke access to a site
	 *
	 * @param string $identifier Unique ID or "all"
	 *
	 * @return bool Both saas and vault synced. False: either or both failed to sync.
	 */
	public function revoke_access( $identifier ) {

		if ( empty( $identifier ) ) {
			$this->dlog( "Error: missing the identifier.", __METHOD__ );

			return false;
		}

		$vault_keyStoreID = $this->get_endpoint_hash( $identifier );

		// Ping SaaS to notify of revoke
		$saas_sync = $this->saas_revoke_site( $vault_keyStoreID );

		if ( ! $saas_sync ) {
			// Couldn't sync to SaaS, this should/could be extended to add a cron-task to delayed update of SaaS DB
			// TODO: extend to add a cron-task to delayed update of SaaS DB
			$this->dlog( "There was an issue syncing to SaaS. Failing silently.", __METHOD__ );
		}

		$auth = get_option( $this->key_storage_option, false );

		$vault_data = array(
			'identifier' => $identifier,
			'deleteKey'  => ( isset( $auth['deleteKey'] ) ? $auth['deleteKey'] : false ),
		);

		// Try ping Vault to revoke the keyset
		$vault_sync = $this->vault_sync_wrapper( $vault_keyStoreID, $vault_data, 'DELETE' );

		if ( ! $vault_sync ) {
			// Couldn't sync to Vault
			$this->dlog( "There was an issue syncing to Vault for revoking.", __METHOD__ );

			// If can't access Vault request new vaultToken via SaaS
			// TODO: Get new endpoint for SaaS to get a new vaultToken
		}

		do_action( 'trustedlogin/access/revoked', array( 'url' => get_site_url(), 'action' => 'revoke' ) );

		return $saas_sync && $vault_sync;
	}

	function get_access_key() {

		/**
		 * Filter: Allow for over-riding the 'accessKey' sent to SaaS platform
		 *
		 * @since 0.4.0
		 *
		 * @param string|null
		 */
		$access_key = apply_filters( 'tl_' . $this->ns . '_licence_key', null );

		return $access_key;
	}

	/**
     * Creates a site in the SaaS app using the identifier hash as the keyStoreID
     *
     * Stores the tokens in the options table under $this->key_storage_option
     *
	 * @param string $identifier Unique ID used across this site/saas/vault
	 *
     * @todo Convert false returns to WP_Error
     *
	 * @return bool Success creating site?
	 */
	public function saas_create_site( $identifier ) {

		$data = array(
			'publicKey'  => $this->get_setting( 'auth/api_key' ),
			'accessKey'  => $this->get_access_key(),
			'siteUrl'    => get_site_url(),
			'keyStoreID' => $identifier,
		);

		$api_response = $this->api_send( self::saas_api_url . 'sites', $data, 'POST' );

		$response = $this->handle_saas_response( $api_response );

		if ( ! $response ) {
			$this->dlog( "Response not received from saas_create_site. Data: " . print_r( $data, true ), __METHOD__ );

			return false;
		}

		if ( ! isset( $response['token'] ) || ! isset( $response['deleteKey'] ) ) {
			$this->dlog( "Unexpected data received from SaaS. Response: " . print_r( $response, true ), __METHOD__ );

			return false;
		}

		// handle short-lived tokens for Vault and SaaS
		$keys = array(
			'vaultToken' => $response['token'],
			'deleteKey'  => $response['deleteKey'],
		);

		$this->set_vault_tokens( $keys );

		return true;
	}

	/**
	 * Revoke a site in the SaaS
	 *
	 * @since 0.4.1
	 *
	 * @param string $action - is the TrustedLogin being created or removed ('new' or 'revoke' respectively)
	 * @param string $vault_keyStoreID - the unique identifier of the entry in the Vault Keystore
	 *
	 * @return bool - was the sync to SaaS successful
	 */
	public function saas_revoke_site( $vault_keyStoreID ) {

		$data = array(
			'publicKey'  => $this->get_setting( 'auth/api_key' ),
			'accessKey'  => $this->get_access_key(),
			'siteUrl'    => get_site_url(),
			'keyStoreID' => $vault_keyStoreID,
		);

		$additional_headers = array();

		if( $delete_key = $this->get_vault_tokens( 'deleteKey' ) ) {
			$additional_headers['Authorization'] = $delete_key;
		}

		$api_response = $this->api_send( self::saas_api_url . 'sites/' . $vault_keyStoreID, $data, 'DELETE', $additional_headers );

		$response = $this->handle_saas_response( $api_response );

		$this->dlog( "Response from revoke action: " . print_r( $response, true ), __METHOD__ );

		if ( ! $response ) {
			$this->dlog( "Response not received from saas_revoke_site. Data: " . print_r( $data, true ), __METHOD__ );

			return false;
		}

		// remove the site option
		$deleted = delete_option( $this->key_storage_option );

		if ( ! $deleted ) {
			$this->dlog( "delete_option failed for 'tl_{$this->ns}_slt' key. Perhaps was already deleted.", __METHOD__ );
		}

		return true;
	}

	/**
	 * API Response Handler - SaaS side
	 *
	 * @since 0.4.1
	 *
	 * @param array $api_response - the response from HTTP API
	 *
	 * @return array|bool - If successful response has body content then returns that, otherwise true. If failed, returns false;
	 */
	public function handle_saas_response( $api_response ) {
		if ( empty( $api_response ) || ! is_array( $api_response ) ) {
			$this->dlog( 'Malformed api_response received:' . print_r( $api_response, true ), __METHOD__ );

			return false;
		}

		// first check the HTTP Response code
		if ( array_key_exists( 'response', $api_response ) ) {

			$this->dlog( "Response: " . print_r( $api_response['response'], true ), __METHOD__ );

			switch ( $api_response['response']['code'] ) {
				case 204:
					// does not return any body content, so can bounce out successfully here
					return true;
					break;
				case 403:
					// Problem with Token
					// maybe do something here to handle this
				case 404:
					// the KV store was not found, possible issue with endpoint
				default:
			}
		}

		$body = json_decode( wp_remote_retrieve_body( $api_response ), true );

		$this->dlog( "Response body: " . print_r( $body, true ), __METHOD__ );

		return $body;
	}

	/**
	 * API Helper: Vault Wrapper
	 *
	 * @since 0.4.1
	 *
	 * @param string $endpoint - the API endpoint to be pinged
	 * @param array $data - the data variables being synced
	 * @param string $method - HTTP RESTful method ('POST','GET','DELETE','PUT','UPDATE')
	 *
     * @todo Convert false returns to WP_Error
     *
	 * @return array|false - response from API
	 */
	public function vault_sync_wrapper( $vault_keyStoreID, $data, $method ) {

		$url = self::vault_api_url . 'v1/' . $this->ns . 'Store/' . $vault_keyStoreID;

		$vault_token = $this->get_vault_tokens( 'vaultToken' );

		if ( empty( $vault_token ) ) {
			$this->dlog( "No auth token provided to Vault API sync.", __METHOD__ );

			return false;
		}

		$additional_headers = array(
			'X-Vault-Token' => $vault_token,
		);

		$api_response = $this->api_send( $url, $data, $method, $additional_headers );

		return $this->handle_vault_response( $api_response );
	}

	/**
	 * @param array $keys
     *
     * @return bool False if value was not updated. True if value was updated.
	 */
	private function set_vault_tokens( Array $keys ) {
		return update_option( $this->key_storage_option, $keys );
	}

	/**
     * Returns token value(s) from the key store
     *
     * @param string|null $token Name of token, either vaultToken or deleteKey. If null, returns whole saved array.
     *
     * @since 0.7.0
     *
	 * @return false|string If vault not found, false. Otherwise, the value at $token.
	 */
	private function get_vault_tokens( $token = null ) {

	    $key_storage = get_option( $this->key_storage_option, false );

		if ( ! $key_storage ) {
			$this->dlog( "Could not get vault token; keys not yet stored.", __METHOD__ );
		    return false;
		}

		if ( $token && ! isset( $key_storage[ $token ] ) ) {
			$this->dlog( "vaultToken not set in key store: " . print_r( $key_storage, true ), __METHOD__ );
		    return false;
        }

		if( $token ) {
		    return $key_storage[ $token ];
        }

        return $key_storage;
    }

	/**
	 * API Response Handler - Vault side
	 *
	 * @since 0.4.1
	 *
	 * @param array $api_response - the response from HTTP API
	 *
	 * @return array|bool - If successful response has body content then returns that, otherwise true. If failed, returns false;
	 */
	public function handle_vault_response( $api_response ) {

		if ( empty( $api_response ) || ! is_array( $api_response ) ) {
			$this->dlog( 'Malformed api_response received:' . print_r( $api_response, true ), __METHOD__ );

			return false;
		}

		// first check the HTTP Response code
		$response_code = wp_remote_retrieve_response_code( $api_response );

		switch ( $response_code ) {
			case 204:
				// does not return any body content, so can bounce out successfully here
				return true;
				break;
			case 403:
				// Problem with Token
				// maybe do something here to handle this
			case 404:
				// the KV store was not found, possible issue with endpoint
			default:
		}

		$body = json_decode( wp_remote_retrieve_body( $api_response ), true );

		if ( empty( $body ) || ! is_array( $body ) ) {
			$this->dlog( 'No body received:' . print_r( $body, true ), __METHOD__ );

			return false;
		}

		if ( array_key_exists( 'errors', $body ) ) {
			foreach ( $body['errors'] as $error ) {
				$this->dlog( "Error from Vault: $error", __METHOD__ );
			}

			return false;
		}

		return $body;

	}

	/**
	 * API Function: send the API request
	 *
	 * @since 0.4.0
	 *
	 * @param string $url - the complete url for the REST API request
	 * @param array $data
	 * @param array $addition_header - any additional headers required for auth/etc
	 *
     * @todo Return WP_Error instead of bool
     *
	 * @return array|false - wp_remote_post response or false if fail
	 */
	public function api_send( $url, $data, $method, $additional_headers ) {

		if ( ! in_array( $method, array( 'POST', 'PUT', 'GET', 'PUSH', 'DELETE' ) ) ) {
			$this->dlog( "Error: Method not in allowed array list ($method)", __METHOD__ );

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
			'body'        => json_encode( $data ),
		);

		$response = wp_remote_request( $url, $request_options );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();

			$this->dlog( __METHOD__ . " - Something went wrong: $error_message" );

			return false;
		} else {

			$this->dlog( __METHOD__ . " - result " . print_r( $response['response'], true ) );

			if ( 399 < wp_remote_retrieve_response_code( $response ) ) {
				$this->dlog( __METHOD__ . " - Error. URL: {$url} . Request options: " . print_r( $request_options, true ) );

				return false;
			}
		}

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
            <p><?php esc_html_e( 'Done! Support access revoked. ', 'trustedlogin' ); ?></p>
        </div>
		<?php
	}

}