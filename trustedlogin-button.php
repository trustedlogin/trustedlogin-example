<?php
/**
 * Plugin Name: TrustedLogin Button
 * Plugin URI: https://trustedlogin.com
 * Description: Proof-of-concept plugin to grant support wp-admin access in a click
 * Version: 0.4.2
 * Author: trustedlogin.io
 * Author URI: https://trustedlogin.com
 * Text Domain: trustedlogin
 *
 * Copyright: © 2019 trustedlogin
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined('ABSPATH') ) {
	exit;
}
// Exit if accessed directly

class TrustedLogin_Button {

	/**
	 * @var array $tl_config Configuration array passed to TrustedLogin class
	 */
	private $tl_config;

	public $trustedlogin;

	public function __construct() {

		$this->load_includes();

		$this->tl_config = array(

			// Role(s) provided to created support user
			'role'             => array(
				 // Key = capability/role. Value = Text describing why it's needed.
				 // 'role_name' => 'reason for requesting',
				 'editor' => 'Support needs to be able to access your site as an administrator to debug issues effectively.',
			),

			// Extra capabilities to grant the user, in addition to what the defined roles provide
			'extra_caps'       => array(
				// 'cap_name' => 'reason for requesting',
				// Key = capability/role. Value = Text describing why it's needed.
				'manage_options' => 'we need this to make things work real gud',
				'edit_posts' => 'Access the posts that you created',
				'delete_users' => 'In order to manage the users that we thought you would want us to.',
			),
			'webhook_url' => '...',

			//  Endpoint for pinging the encrypted envelope to.
			'auth' => array(
				'api_key' => '...', // Public key for encrypting the securedKey
			),

			// How quickly to disable the generated users
			'decay' => WEEK_IN_SECONDS,

			// Details about your support setup
			'vendor' => array(
				'namespace' => 'gravityview',
				'title' => 'GravityView',
				'email' => 'support@gravityview.com',
				'website' => 'https://gravityview.com',
				'support_url' => 'https://gravityview.com/support/', // Backup to redirect users if TL is down/etc
				'logo_url' => '', // Displayed in the authentication modal
			),

			// Whether or not to re-assign posts created by support account to admin. If not, they'll be deleted.
			'reassign_posts' => true,
		);

		add_action( 'plugins_loaded', array( $this, 'init_tl' ) );

	}

	public function init_tl() {
		$trustedlogin = new TrustedLogin( $this->tl_config );
	}

	public function load_includes() {

		// Traits
		require_once plugin_dir_path( __FILE__ ) . 'includes/trait-debug-logging.php';

		// Classes
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-trustedlogin.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-settings.php';

	}

}

$example_tl = new TrustedLogin_Button();
