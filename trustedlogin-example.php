<?php
/**
 * Plugin Name: TrustedLogin Example Plugin
 * Plugin URI: https://www.trustedlogin.com
 * Description: Proof-of-concept plugin to grant support wp-admin access in a click
 * Version: 0.4.2
 * Author: TrustedLogin
 * Author URI: https://www.trustedlogin.com
 * Text Domain: trustedlogin
 *
 * Copyright: © 2019 trustedlogin
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace ReplaceMe;

if ( ! defined('ABSPATH') ) {
	exit;
}
// Exit if accessed directly

class Button {

	public $trustedlogin;

	public function __construct() {

		// This is necessary to load required TrustedLogin classes.
		require plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

		add_action( 'plugins_loaded', array( $this, 'init_tl' ) );

	}

	public function init_tl() {

		$settings = array(
			// Role(s) provided to created support user
			'role' => 'editor',
			// Extra capabilities to grant the user, in addition to what the defined roles provide
			'caps' => array(
				'add' => array(
					// 'cap_name' => 'reason for requesting',
					// Key = capability/role. Value = Text describing why it's needed.
					'manage_options' => 'we need this to make things work real gud',
					'edit_posts' => 'Access the posts that you created',
					'delete_users' => 'In order to manage the users that we thought you would want us to.',
				),
				'remove' => array(
					// 'cap_name' => 'reason for requesting',
					// Key = capability/role. Value = Text describing why it's needed.
					'delete_published_pages' => 'Your published posts cannot and will not be deleted by support staff',
				),
			),
			'webhook_url' => 'https://www.example.com/api/',

			//  Endpoint for pinging the encrypted envelope to.
			'auth' => array(
				'public_key' => 'b814872125f46543', // Public key for encrypting the securedKey
				'license_key' => 'REQUIRED', // Pass the license key for the current user. Example: gravityview()->plugin->settings->get( 'license_key' ),
			),

			// How quickly to disable the generated users
			'decay' => WEEK_IN_SECONDS,

			// Settings regarding adding links to the admin sidebar. Leave blank to not add (a direct link will remain enabled)
			'menu' => array(
				'slug' => 'edit.php?post_type=gravityview', // Add "Grant Support Access" submenu.
				'title' => 'Provide Access',
				'priority' => 1000,
			),

			// Details about your support setup
			'vendor' => array(
				'namespace' => 'gravityview',
				'title' => 'GravityView',
				'first_name' => 'Floaty',
				'last_name' => 'the Astronaut',
				'email' => 'support@gravityview.co',
				'website' => 'https://gravityview.co',
				'support_url' => 'https://gravityview.co/support/', // Backup to redirect users if TL is down/etc
				'logo_url' => 'https://static4.gravityview.co/wp-content/themes/Website/images/GravityView-262x80@2x.png', // Displayed in the authentication modal
			),

			// Override CSS styles or JavaScript files by providing your own URL to the JS and CSS files
			'paths' => array(
				'css' => null, // plugin_dir_url( __FILE__ ) . 'assets/trustedlogin-override.css', // For example
				'js'  => null, // plugin_dir_url( __FILE__ ) . 'assets/trustedlogin-override.js', // For example
			),

			// Whether or not to re-assign posts created by support account to admin. If not, they'll be deleted.
			'reassign_posts' => true,

			// Whether we require SSL for extra security when syncing Access Keys to your TrustedLogin account.
			'require_ssl' => true,

			// Configure whether to log
			'logging' => array(
				'enabled' => true,
				'directory' => null, // Default is /wp-uploads/trustedlogin-logs/
				'threshold' => 'warning',
				'options' => array(),
			),
		);

		$config = new \ReplaceMe\TrustedLogin\Config( $settings );

		$trustedlogin = new \ReplaceMe\TrustedLogin\Client( $config );

		// Classes
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-settings.php';

		new \TrustedLogin_Example_Settings_Page( $config );
	}

}

$example_tl = new Button();
