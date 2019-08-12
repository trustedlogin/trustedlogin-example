<?php
if (!defined('ABSPATH')) {
    exit;
}
// Exit if accessed directly

class TrustedLogin {

	use TL_Debug_Logging;

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
	 * @var bool $debug_mode - whether to output debug information to a debug text file
	 * @since 0.1.0
	 */
	private $debug_mode;

	/**
	 * @var bool $setting_init - if the settings have been initialized from the config object
	 * @since 0.1.0
	 */
	private $settings_init;

	/**
	 * @var string $ns - plugin's namespace for use in namespacing variables and strings
	 * @since 0.4.0
	 */
	private $ns;

	/**
	 * @var string $version - the current drop-in file version
	 * @since 0.1.0
	 */
	const version = '0.4.2';

	public function __construct( $config = '' ) {

		/**
		 * Filter: Whether debug logging is enabled in trustedlogin drop-in
		 *
		 * @since 0.4.2
		 *
		 * @param bool
		 */
		$this->debug_mode = apply_filters( 'trustedlogin_debug_enabled', true );

		$this->settings_init = false;

		if ( empty( $config ) ) {
			$this->dlog( 'No config settings passed to constructor', __METHOD__ );
		}

		if ( ! empty( $config ) ) {

			// Handle JSON encoded config
			if ( ! is_array( $config ) ) {
				$config = json_decode( $config );
			}

			if ( ! is_null( $config ) ) {
				$this->settings_init = $this->init_settings( $config );
			}
		}

		$this->init_hooks();

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
		add_action( 'init', array( $this, 'endpoint_add' ), 10 );
		add_action( 'template_redirect', array( $this, 'endpoint_maybe_redirect' ), 99 );
		add_filter( 'query_vars', array( $this, 'endpoint_add_var' ) );

	}

	/**
	 * Hooked Action: Add a unique endpoint to WP if a support agent exists
	 *
	 * @since 0.3.0
	 */
	public function endpoint_add() {
		$endpoint = get_option( $this->endpoint_option );
		if ( $endpoint && ! get_option( 'fl_permalinks_flushed' ) ) {
			// add_rewrite_endpoint($endpoint, EP_ALL);
			$endpoint_regex = '^' . $endpoint . '/([^/]+)/?$';
			$this->dlog( "E_R: $endpoint_regex", __METHOD__ );
			add_rewrite_rule(
			// ^p/(d+)/?$
				$endpoint_regex,
				'index.php?' . $endpoint . '=$matches[1]',
				'top' );
			$this->dlog( "Endpoint $endpoint added.", __METHOD__ );
			flush_rewrite_rules( false );
			$this->dlog( "Rewrite rules flushed.", __METHOD__ );
			update_option( 'fl_permalinks_flushed', 1 );
		}

		return;
	}

	/**
	 * Filter: Add a unique variable to endpoint queries to hold the identifier
	 *
	 * @since 0.3.0
	 *
	 * @param array $vars
	 *
	 * @return array
	 */
	public function endpoint_add_var( $vars ) {

		$endpoint = get_option( $this->endpoint_option );

		if ( $endpoint ) {
			$vars[] = $endpoint;

			$this->dlog( "Endpoint var $endpoint added", __METHOD__ );
		}

		return $vars;

	}

	/**
	 * Hooked Action: Check if the endpoint is hit and has a valid identifier before automatically logging in support agent
	 *
	 * @since 0.3.0
	 */
	public function endpoint_maybe_redirect() {

		$endpoint = get_option( $this->endpoint_option );

		$identifier = get_query_var( $endpoint, false );

		if ( ! empty( $identifier ) ) {
			$this->support_user_auto_login( $identifier );
		}
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

		//!wp_verify_nonce($nonce, 'tl_nonce-' . get_current_user_id()
		if ( ! check_ajax_referer( 'tl_nonce-' . get_current_user_id(), '_nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Verification Issue' ) );
		}

		if ( current_user_can( 'manage_options' ) ) {
			$support_user_array = $this->support_user_generate();

			if ( is_array( $support_user_array ) ) {
				$this->dlog( 'Support User: ' . print_r( $support_user_array, true ), __METHOD__ );
				// Send to Vault
			} else {
				$this->dlog( 'Support User not created.', __METHOD__ );
				wp_send_json_error( array( 'message' => 'Support User already exists' ), 409 );
			}

			$synced = $this->api_prepare_envelope( $support_user_array, 'create' );

			if ( $synced ) {
				wp_send_json_success( $support_user_array, 201 );
			} else {
				$support_user_array['message'] = 'Sync Issue';
				wp_send_json_error( $support_user_array, 503 );
			}

		} else {
			wp_send_json_error( array( 'message' => 'Permissions Issue' ) );
		}

		wp_die();
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
            'vendor'  => $this->get_setting( 'vendor' ),
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            '_nonce'  => wp_create_nonce( 'tl_nonce-' . get_current_user_id() ),
            'lang'    => array_merge( $this->output_tl_alert(), $this->output_secondary_alerts() ),
            'debug'   => $this->debug_mode,
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
			'text'       => sprintf( __( 'Grant %s Support Access', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ),
			'size'       => 'hero',
			'class'      => 'button-primary',
			'tag'        => 'a', // "a", "button", "span"
			'id'         => null,
			'powered_by' => true,
            'support_url' => $this->get_setting( 'vendor/support_url' ),
		);

		$sizes = array( 'small', 'normal', 'large', 'hero' );

		$atts = wp_parse_args( $atts, $defaults );

		switch( $atts['size'] ) {
            case '':
	            $size_class = '';
                break;
			case 'normal':
				$size_class = 'button';
				break;
            default:
	            if ( ! in_array( $atts['size'], $sizes ) ) {
		            $atts['size'] = 'hero';
	            }

                $size_class = 'button button-' . $atts['size'];
        }

		$tags = array( 'a', 'button', 'span' );

		if ( ! in_array( $atts['tag'], $tags ) ) {
			$atts['tag'] = 'a';
		}

		$tag = empty( $atts['tag'] ) ? 'a' : $atts['tag'];

		$href      = esc_url( $atts['support_url'] );
		$css_class = esc_attr( implode( ' ', array( $size_class, $atts['class'] ) ) );

		$powered_by  = $atts['powered_by'] ? '<small><span class="trustedlogin-logo"></span>Powered by TrustedLogin</small>' : false;
		$anchor_html = esc_html( $atts['text'] ) . $powered_by;

		$button = sprintf( '<%s href="%s" class="%s button-trustedlogin trustedlogin–grant-access">%s</%s>', $tag, $href, $css_class, $anchor_html, $tag );

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

		$users = $this->helper_get_support_users( 'all' );

		if( 0 === count( $users ) ) {

		    $return = '<h3>' . sprintf( esc_html__( 'No %s users exist.', 'trustedlogin' ), $this->get_setting( 'vendor/title' ) ) . '</h3>';

			if( $print ) {
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

            $revoke_url = $this->helper_get_user_revoke_url( $_udata );

            if( $revoke_url ) {
                $return .= '<td><a class="trustedlogin tl-revoke submitdelete" href="' . esc_url( $revoke_url ) . '">' . __( 'Revoke Access', 'trustedlogin' ) . '</a></td>';
            } else {
                $return .= '<td><a href="' . admin_url( 'users.php' ). '">' . __( 'Manage from Users list', 'trustedlogin' ) . '</a></td>';
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
			                   esc_url( $this->get_setting( 'vendor/support_uri' ) ),
			                   $plugin_title
		                   ) . '</p>';

		$secondary_alert_translations = array(
			'confirmButton'      => __( 'Confirm', 'trustedlogin' ),
			'okButton'      => __( 'OK', 'trustedlogin' ),
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
	 * @return Bool
	 */
	public function init_settings( $config ) {

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
		$this->support_role = apply_filters(
			'trustedlogin_' . $this->ns . '_support_role_title',
			$this->ns . '-support',
			$this
		);

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

		/**
		 * @var string TL_SAAS_URL - the API url for the TrustedLogin SaaS Platform
		 * @since 0.4.0
		 */
		DEFINE( "TL_SAAS_URL", "https://app.trustedlogin.com/api" );

		/**
		 * @var string TL_VAULT_URL - the API url for the TrustedLogin Vault Platfomr
		 * @since 0.3.0
		 */
		DEFINE( "TL_VAUlT_URL", "https://vault.trustedlogin.io" );

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
	 * Generate the Support User with custom role.
	 *
	 * @since 0.1.0
	 * @return array|false - Array with login response information if created, or false if there was an issue.
	 */
	public function support_user_generate() {

		$results = array();

		$user_name = 'tl_' . $this->ns;

		if ( validate_username( $user_name ) ) {
			$user_id = username_exists( $user_name );
		} else {
			$user_id = null;
		}

		foreach ( $this->get_setting( 'role' ) as $key => $reason ) {
			$role_to_clone = $key;
		}

		$role_exists = $this->support_user_create_role(
			$this->support_role,
			$role_to_clone
		);

		$user_email = $this->get_setting( 'vendor/email' );

		if ( ! $user_id && ( email_exists( $user_email ) == false ) && $role_exists ) {
			$random_password = wp_generate_password( 64, true, true );
			$userdata        = array(
				'user_login'      => $user_name,
				'user_url'        => $this->get_setting( 'vendor/website' ),
				'user_pass'       => $random_password,
				'user_email'      => $user_email,
				'role'            => $this->support_role,
				'first_name'      => $this->get_setting( 'vendor/title' ),
				'last_name'       => 'Support',
				'user_registered' => date( 'Y-m-d H:i:s', time() ),
			);

			$results['user_id'] = wp_insert_user( $userdata );

			if ( is_wp_error( $results['user_id'] ) ) {
				$this->dlog( 'User not created because: ' . $results['user_id']->get_error_message(), __METHOD__ );

				return false;
			}

			$id_key = 'tl_' . $this->ns . '_id';

			$results['identifier'] = wp_generate_password( 64, false, false );

			add_user_meta( $results['user_id'], $id_key, md5( $results['identifier'] ), true );
			add_user_meta( $results['user_id'], 'tl_created_by', get_current_user_id() );

			$results['siteurl'] = get_site_option( 'siteurl' );

			$results['endpoint'] = md5( $results['siteurl'] . $results['identifier'] );

			update_option( $this->endpoint_option, $results['endpoint'] );

			$decay_time = $this->get_setting( 'decay', 300 );

			$results['expiry'] = time() + $decay_time;

			if ( $decay_time ) {
				$scheduled_decay = wp_schedule_single_event(
					$results['expiry'],
					'tl_destroy_sessions',
					array( $results['identifier'] )
				);
				$this->dlog( 'Scheduled Decay: ' . var_export( $scheduled_decay, true ), __METHOD__ );
			}

			return $results;
		}

		$this->dlog( 'Support User NOT created.', __METHOD__ );

		return false;

	}

	/**
	 * Destroy one or all of the Support Users
	 *
	 * @since 0.1.0
	 *
	 * @param string $identifier - Unique Identifier of the user to delete, or 'all' to remove all support users.
	 *
	 * @return Bool
	 */
	public function support_user_destroy( $identifier = 'all' ) {

		$users = $this->helper_get_support_users( $identifier );

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

		if ( count( $users ) == 0 ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		foreach ( $users as $_u ) {
			$this->dlog( "Processing uid " . $_u->ID, __METHOD__ );

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
				update_option( 'fl_permalinks_flushed', 0 );
				$this->dlog( "Endpoint removed & rewrites flushed", __METHOD__ );
			}

		}

		$siteurl  = get_site_url();
		$vault_id = md5( $siteurl . $identifier );
		if ( false !== ( $auth = get_site_option( 'tl_' . $this->ns . '_slt', false ) ) ) {
			$deleteKey = $auth['authToken'];
		} else {
			$deleteKey = '';
		}

		$data   = array( 'identifier' => $vault_id, 'siteurl' => $siteurl, 'deletekey' => $deleteKey );
		$synced = $this->api_prepare_envelope( $data, 'revoke' );

		if ( $synced ) {
			$this->dlog( "Revoked status synced to SaaS & Vault" );
		} else {
			$this->dlog( "Revoked status NOT synced to SaaS & Vault" );
		}

		return true;

	}

	/**
	 * Hooked Action: Decays (deletes a specific support user)
	 *
	 * @since 0.2.1
	 *
	 * @param string $identifier
	 * @param Int $user_id
	 *
	 * @return none
	 */
	public function support_user_decay( $identifier, $user_id ) {

		$this->dlog( 'Disabling user with id: ' . $identifier, __METHOD__ );
		$this->support_user_destroy( $identifier );

	}

	/**
	 * Create the custom Support Role if it doesn't already exist
	 *
	 * @since 0.1.0
	 *
	 * @param string $new_role_slug - slug for the new role
	 * @param string $clone_role_slug - slug for the role to clone, defaults to 'editor'
	 *
	 * @return Bool
	 */
	public function support_user_create_role( $new_role_slug, $clone_role_slug = 'editor' ) {

		$this->dlog( 'N: ' . $new_role_slug . ', O: ' . $clone_role_slug, __METHOD__ );

		if ( ! is_null( get_role( $new_role_slug ) ) ) {
			return true;
		}

		$old_role = get_role( $clone_role_slug );

		if ( ! empty( $old_role ) ) {

			$capabilities = $old_role->capabilities;

			$extra_caps = $this->get_setting( 'extra_caps' );

			if ( is_array( $extra_caps ) && ! empty( $extra_caps ) ) {
				$capabilities = array_merge( $extra_caps, $capabilities );
			}

			$new_role = add_role( $new_role_slug, $this->get_setting( 'vendor/title' ), $capabilities );

			return true;
		}

		return false;
	}

	/**
	 * Auto-login function, which takes in a unique identifier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $identifier - Unique Identifier for the Support User to be logged into
	 *
	 * @return false if user not logged in, otherwise redirect to wp-admin.
	 */
	public function support_user_auto_login( $identifier ) {

		if ( empty( $identifier ) ) {
			return false;
		}

		$users = $this->helper_get_support_users( $identifier );

		if ( empty( $users ) ) {
			return false;
		}

		$_u = $users[0];

		wp_set_current_user( $_u->ID, $_u->user_login );
		wp_set_auth_cookie( $_u->ID );
		do_action( 'wp_login', $_u->user_login, $_u );

		wp_redirect( admin_url() );
		exit();

	}

	/**
	 * Helper Function: Get the generated support user(s).
	 *
	 * @since 0.1.0
	 *
	 * @param string $identifier - Unique Identifier of 'all'
	 *
	 * @return array of WP_Users
	 */
	public function helper_get_support_users( $identifier = 'all' ) {
		$args = array(
			'role' => $this->support_role,
		);

		if ( 'all' !== $identifier ) {

			$this->dlog( "Id length: " . strlen( $identifier ), __METHOD__ );

			if ( strlen( $identifier ) > 32 ) {
				$identifier = md5( $identifier );
			}

			$args['meta_key']   = 'tl_' . $this->ns . '_id';
			$args['meta_value'] = $identifier;
			$args['number']     = 1;
		}

		$this->dlog( 'Args:' . print_r( $args, true ), __METHOD__ );

		return get_users( $args );
	}

	public function adminbar_add_toolbar_items( $admin_bar ) {

		if ( current_user_can( $this->support_role ) ) {
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
			'revoke' => "<a class='trustedlogin tl-revoke submitdelete' href='" . esc_url( $revoke_url ) . "'>" . __( 'Revoke Access', 'trustedlogin' ) . "</a>",
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

	    $identifier = get_user_meta( $user_object->ID, 'tl_' . $this->ns . '_id', true );

		if ( empty( $identifier ) ) {
			$this->dlog( "Could not generate revoke url: Empty identifier meta", __METHOD__ );
			return false;
		}

        $revoke_url = add_query_arg( array(
            'revoke-tl' => 'si',
            'tlid' => $identifier,
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

		if ( current_user_can( $this->support_role ) || current_user_can( 'manage_options' ) ) {

			if ( isset( $_GET['tlid'] ) ) {
				$identifier = sanitize_text_field( $_GET['tlid'] );
			} else {
				$identifier = 'all';
			}

			$success = $this->support_user_destroy( $identifier );
		}

		if ( $success ) {

			if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
				wp_redirect( home_url() );
				exit;
			}

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
	public function send_support_webhook( $data ) {

		if ( ! is_array( $data ) ) {
			$this->dlog( "Data is not an array: " . print_r( $data, true ), __METHOD__ );

			return false;
		}

		$webhook_url = $this->get_setting( 'notification_uri' );

		if ( ! empty( $webhook_url ) ) {
			// send to webhook
		}
	}

	/**
	 * Prepare data and (maybe) send it to the Vault
	 *
	 * @since 0.3.1
	 *
	 * @param array $data
	 * @param string $action - what's trigerring the vault sync. Options can be 'create','revoke'
	 *
	 * @return string|false - the VaultID of where in the keystore the data is saved, or false if there was an error
	 */
	public function api_prepare_envelope( $data, $action ) {
		if ( ! is_array( $data ) ) {
			$this->dlog( "Data is not array: " . print_r( $data, true ), __METHOD__ );

			return false;
		}

		if ( ! in_array( $action, array( 'create', 'revoke' ) ) ) {
			$this->dlog( "Action is not defined: $action", __METHOD__ );

			return false;
		}

		$vault_id       = md5( $data['siteurl'] . $data['identifier'] );
		$vault_endpoint = $this->ns . 'Store/' . $vault_id;

		if ( 'create' == $action ) {
			$method = 'POST';
			// Ping SaaS and get back tokens.
			$saas_sync = $this->tl_saas_sync_site( 'new', $vault_id );
			// If no tokens received continue to backup option (redirecting to support link)

			if ( ! $saas_sync ) {
				$this->dlog( "There was an issue syncing to SaaS for $action. Bouncing out to redirect.", __METHOD__ );

				return false;
			}

			// Else ping the envelope into vault, trigger webhook fire
			$vault_sync = $this->api_prepare( 'vault', $vault_endpoint, $data, $method );

			if ( ! $vault_sync ) {
				$this->dlog( "There was an issue syncing to Vault for $action. Bouncing out to redirect.", __METHOD__ );

				return false;
			}

		} else if ( 'revoke' == $action ) {
			$method = 'DELETE';
			// Ping SaaS to notify of revoke
			$saas_sync = $this->tl_saas_sync_site( 'revoke', $vault_id );

			if ( ! $saas_sync ) {
				// Couldn't sync to SaaS, this should/could be extended to add a cron-task to delayed update of SaaS DB
				$this->dlog( "There was an issue syncing to SaaS for $action. Failing silently.", __METHOD__ );
			}

			// Try ping Vault to revoke the keyset
			$vault_sync = $this->api_prepare( 'vault', $vault_endpoint, $data, $method );

			if ( ! $vault_sync ) {
				// Couldn't sync to Vault
				$this->dlog( "There was an issue syncing to Vault for $action.", __METHOD__ );

				// If can't access Vault request new vaultToken via SaaS
				#TODO - get new endpoint for SaaS to get a new vaultToken
			}

		}

		$this->send_support_webhook( array( 'url' => $data['siteurl'], 'vid' => $vault_id, 'action' => $action ) );

		return true;

	}

	/**
	 * API request builder for syncing to SaaS instance
	 *
	 * @since 0.4.1
	 *
	 * @param string $action - is the TrustedLogin being created or removed ('new' or 'revoke' respectively)
	 * @param string $vault_id - the unique identifier of the entry in the Vault Keystore
	 *
	 * @return bool - was the sync to SaaS successful
	 */
	public function tl_saas_sync_site( $action, $vault_id ) {

		if ( empty( $action ) || ! in_array( $action, array( 'new', 'revoke' ) ) ) {
			return false;
		}

		/**
		 * Filter: Allow for over-riding the 'accessKey' sent to SaaS platform
		 *
		 * @since 0.4.0
		 *
		 * @param string|null
		 */
		$access_key = apply_filters( 'tl_' . $this->ns . '_licence_key', null );

		$data = array(
			'publicKey'  => $this->get_setting( 'auth.api_key' ),
			'accessKey'  => $access_key,
			'siteurl'    => get_site_url(),
			'keyStoreID' => $vault_id,
		);

		if ( 'revoke' == $action ) {
			$method   = 'DELETE';
			$endpoint = 'sites/' . $vault_id;
		} else {
			$method   = 'POST';
			$endpoint = 'sites';
		}

		$response = $this->api_prepare( 'saas', $endpoint, $data, $method );

		if ( $response ) {

			if ( 'new' == $action ) {
				// handle responses to new site request

				if ( array_key_exists( 'token', $response ) && array_key_exists( 'deleteKey', $response ) ) {
					// handle short-lived tokens for Vault and SaaS
					$keys = array( 'vaultToken' => $response['token'], 'authToken' => $response['deleteKey'] );
					update_site_option( 'tl_' . $this->ns . '_slt', $keys );

					return true;
				} else {
					$this->dlog( "Unexpected data received from SaaS. Response: " . print_r( $response, true ), __METHOD__ );

					return false;
				}
			} else if ( 'revoke' == $action ) {
				// handle responses to revoke

				// remove the site option
				delete_site_option( 'tl_' . $this->ns . '_slt' );
				$this->dlog( "Respone from revoke action: " . print_r( $response, true ), __METHOD__ );

				return true;
			}

		} else {
			$this->dlog(
				"Response not received from api_prepare('saas','sites',data,'POST',$method). Data: " . print_r( $data, true ),
				__METHOD__
			);

			return false;
		}
	}

	/**
	 * API router based on type
	 *
	 * @since 0.4.1
	 *
	 * @param string $type - where the API is being prepared for (either 'saas' or 'vault')
	 * @param string $endpoint - the API endpoint to be pinged
	 * @param array $data - the data variables being synced
	 * @param string $method - HTTP RESTful method ('POST','GET','DELETE','PUT','UPDATE')
	 *
	 * @return array|false - response from the RESTful API
	 */
	public function api_prepare( $type, $endpoint, $data, $method ) {

		$type = sanitize_title( $type );

		if ( 'saas' == $type ) {
			return $this->saas_sync_wrapper( $endpoint, $data, $method );
		} else if ( 'vault' == $type ) {
			return $this->vault_sync_wrapper( $endpoint, $data, $method );
		} else {
			$this->dlog( 'Unrecognised value for type:' . $type, __METHOD__ );

			return false;
		}
	}

	/**
	 * API Helper: SaaS Wrapper
	 *
	 * @since 0.4.1
	 * @see api_prepare() for more attribute info
	 *
	 * @param string $endpoint
	 * @param array $data
	 * @param string $method
	 *
	 * @return array|false - response from API
	 */
	public function saas_sync_wrapper( $endpoint, $data, $method ) {

		$additional_headers = array();

		$url = TL_SAAS_URL . '/' . $endpoint;

		$auth = get_site_option( 'tl_' . $this->ns . '_slt', false );

		if ( $auth && 'sites' !== $endpoint ) {
			if ( array_key_exists( 'authToken', $auth ) ) {
				$additional_headers['Authorization'] = $auth['authToken'];
			}
		}

		$api_response = $this->api_send( $url, $data, $method, $additional_headers );

		return $this->handle_saas_response( $api_response );

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
	 * @see api_prepare() for more attribute info
	 *
	 * @param string $endpoint
	 * @param array $data
	 * @param string $method
	 *
	 * @return array|false - response from API
	 */
	public function vault_sync_wrapper( $endpoint, $data, $method ) {
		$additional_headers = array();

		// $vault_url = $this->get_setting('vault.url');
		$url = TL_VAUlT_URL . '/v1/' . $endpoint;

		$auth = get_site_option( 'tl_' . $this->ns . '_slt', false );

		if ( $auth ) {
			if ( array_key_exists( 'vaultToken', $auth ) ) {
				$additional_headers['X-Vault-Token'] = $auth['vaultToken'];
			}
		}

		if ( empty( $additional_headers ) ) {
			$this->dlog( "No auth token provided to Vault API sync.", __METHOD__ );

			return false;
		}

		$api_response = $this->api_send( $url, $data, $method, $additional_headers );

		return $this->handle_vault_response( $api_response );
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

		$data_json = json_encode( $data );

		$response = wp_remote_request( $url, array(
			'method'      => $method,
			'timeout'     => 45,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
			'body'        => $data_json,
			'cookies'     => array(),
		) );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->dlog( __METHOD__ . " - Something went wrong: $error_message" );

			return false;
		} else {
			$this->dlog( __METHOD__ . " - result " . print_r( $response['response'], true ) );
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
            <p><?php _e( 'Done! Support access revoked. ', 'trustedlogin' ); ?></p>
        </div>
		<?php
	}

}
