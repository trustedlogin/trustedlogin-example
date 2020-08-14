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
 * Copyright: © 2020 trustedlogin
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace ReplaceMe;

if ( ! defined('ABSPATH') ) {
	exit;
}
// Exit if accessed directly

class Example {

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
			// Extra capabilities to grant the user, in addition to what the defined roles provide.
			'caps' => array(
				'add' => array(
					// capability => Text describing why it's needed.
					'manage_options' => 'We need to confirm the settings you have for other plugins.',
					'edit_posts' => 'This allows us to add or modify View shortcodes if we need to.',

				/** @see SupportRole::$prevented_caps for a list of disallowed capabilities. */
				//	'delete_users' => 'Uncomment this line to see what happens when configuration is not valid.',
				),
				'remove' => array(
					// capability => Text describing why it's not needed.
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
			#	'slug' => 'edit.php?post_type=gravityview', // Add "Grant Support Access" submenu.
			#	'title' => 'Provide Access',
			#	'priority' => 1000,
			),

			// Details about your support setup
			'vendor' => array(
				'namespace' => 'test', // TODO: Replace this with `example` once live.
				'title' => 'GravityView',
				'display_name' => 'GravityView Support',
				'email' => 'support@example.com',
				'website' => 'https://www.example.com',
				'support_url' => 'https://www.example.com/support/', // Backup to redirect users if TL is down/etc

				// Logo displayed in the authentication form.
				'logo_url' => 'data:image/svg+xml;utf8,' . '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><path d="M4.548 31.999c0 10.9 6.3 20.3 15.5 24.706L6.925 20.827A27.168 27.168 0 004.5 31.999zm45.983-1.385c0-3.394-1.219-5.742-2.264-7.57-1.391-2.263-2.695-4.177-2.695-6.439 0-2.523 1.912-4.872 4.609-4.872.121 0 .2 0 .4.022C45.653 7.3 39.1 4.5 32 4.548c-9.591 0-18.027 4.921-22.936 12.4.645 0 1.3 0 1.8.033 2.871 0 7.316-.349 7.316-.349 1.479-.086 1.7 2.1.2 2.3 0 0-1.487.174-3.142.261l9.997 29.735 6.008-18.017-4.276-11.718c-1.479-.087-2.879-.261-2.879-.261-1.48-.087-1.306-2.349.174-2.262 0 0 4.5.3 7.2.349 2.87 0 7.317-.349 7.317-.349 1.479-.086 1.7 2.1.2 2.262 0 0-1.489.174-3.142.261l9.92 29.508 2.739-9.148C49.628 35.7 50.5 33 50.5 30.614zM32.481 34.4l-8.237 23.934c2.46.7 5.1 1.1 7.8 1.1 3.197 0 6.262-.552 9.116-1.556a2.6 2.6 0 01-.196-.379L32.481 34.4zm23.607-15.6c.119.9.2 1.8.2 2.823 0 2.785-.521 5.916-2.088 9.832l-8.385 24.242c8.161-4.758 13.65-13.6 13.65-23.728A27.738 27.738 0 0056.1 18.83zM32 0C14.355 0 0 14.355 0 32c0 17.6 14.4 32 32 32s32-14.355 32-32.001C64 14.4 49.6 0 32 0zm0 62.533c-16.835 0-30.533-13.698-30.533-30.534C1.467 15.2 15.2 1.5 32 1.5s30.534 13.7 30.5 30.532C62.533 48.8 48.8 62.5 32 62.533z" fill="#0073aa"/></svg>',
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

		/**
		 * Modify the settings in this Example plugin
		 *
		 * @hooked TrustedLogin_Example_Settings::override_example_settings
		 */
		$settings = apply_filters( 'trustedlogin/example/settings', $settings );

		$config = new \ReplaceMe\TrustedLogin\Config( $settings );

		$trustedlogin = new \ReplaceMe\TrustedLogin\Client( $config );

		// Classes
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-settings.php';

		new \TrustedLogin_Example_Settings_Page( $config );
	}

}

$example_tl = new Example();
