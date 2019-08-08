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
			'role'             => array(
				/**
				 * 'role_name' => 'reason for requesting', // Key = capability/role. Value = Text describing why it's needed.
				 **/
				'administrator' => 'Support needs to be able to access your site as an administrator to debug issues effectively.',
			),
			'extra_caps'       => array(/**
			                             * 'cap_name' => 'reason for requesting', // Key = capability/role. Value = Text describing why it's needed.
			                             **/
			),
			'notification_uri' => '...',
			//  Endpoint for pinging the encrypted envelope to.
			'auth'             => array(
				'api_key' => '...', // Public key for encrypting the securedKey
			),
			'decay'            => WEEK_IN_SECONDS,
			// How quickly to disable the generated users
			'plugin'           => array(
				'namespace' => 'gravityview',
				'title' => 'GravityView',
				'email' => 'support@gravityview.com',
				'website' => 'https://gravityview.com',
				'support_uri' => 'https://gravityview.com/support', // Backup to redirect users if TL is down/etc
			),
			'reassign_posts'   => true,
			// Whether or not to re-assign posts created by support account to admin. If not, they'll be deleted.
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
