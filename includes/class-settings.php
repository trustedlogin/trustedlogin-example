<?php
/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */
class TrustedLogin_Example_Settings {

	const demo_option_name = 'trustedlogin_example_plugin';

	/**
	 * @var \ReplaceMe\TrustedLogin\Config
	 */
	private $config;

	/**
	 * Start up
	 */
	public function __construct() {

		add_action( 'trustedlogin/example/settings_form', array( $this, 'settings_form' ) );

		add_action( 'admin_init', array( $this, 'register_demo_settings' ) );

		add_filter( 'trustedlogin/example/settings', array( $this, 'get_settings' ) );
	}

	public function settings_form() {
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( self::demo_option_name );

			do_settings_sections( self::demo_option_name );
			?>
			<input name="submit" class="button button-primary button-large" type="submit" value="<?php esc_attr_e( 'Save' ); ?>" />
		</form>
		<?php
	}

	/**
	 * This is just to make it easier to update DEMO TrustedLogin configurations IN THIS DEMO.
	 *
	 * TrustedLogin configurations should not be set like this! {@see \TrustedLogin\Config}
	 *
	 * @internal DO NOT
	 */
	public function register_demo_settings() {

		$page = 'trustedlogin-example';

		register_setting( self::demo_option_name, self::demo_option_name, array(
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );

		add_settings_section( 'trustedlogin_example_settings', 'Configure the Demo Settings', array( $this, 'settings_description' ), self::demo_option_name );

		add_settings_field( 'auth[public_key]', 'API Key', array( $this, 'input_public_key' ), self::demo_option_name, 'trustedlogin_example_settings' );
		add_settings_field( 'vendor[website]', 'Website Running Vendor Plugin', array( $this, 'input_vendor_website' ), self::demo_option_name, 'trustedlogin_example_settings' );
		add_settings_field( 'require_ssl', 'Require SSL', array( $this, 'input_require_ssl' ), self::demo_option_name, 'trustedlogin_example_settings' );
	}

	public function settings_description() {
		echo '<p>This is designed to make it easy to test out TrustedLogin with your own API key without having to generate a Config object.';
	}

	public function sanitize_settings( $settings ) {

		if ( isset( $settings['require_ssl'] ) ) {
			$settings['require_ssl'] = (int) $settings['require_ssl']; // Saves in the DB, as opposed to FALSE
		}

		if ( empty( $settings['vendor']['website'] ) ) {
			unset( $settings['vendor']['website'] );
		}

		return array_filter( $settings );

		return $settings;
	}

	function input_public_key() {

		$options = get_option( self::demo_option_name );

		$public_key = isset( $options['auth']['public_key'] ) ? $options['auth']['public_key'] : '';

		echo '<input name="' . self::demo_option_name . '[auth][public_key]" type="text" size="55" placeholder="b814872125f46543" value="' . esc_attr( $public_key ) . '" />';
	}

	function input_vendor_website() {

		$options = get_option( self::demo_option_name );

		$website = isset( $options['vendor']['website'] ) ? $options['vendor']['website'] : '';

		echo '<input name="' . self::demo_option_name . '[vendor][website]" type="text" size="55" placeholder="https://www.example.com" value="' . esc_attr( $website ) . '" />';
	}

	public function input_require_ssl() {

		$options = get_option( self::demo_option_name );

		$checked = isset( $options['require_ssl'] ) ? $options['require_ssl'] : '';

		echo '<label><input name="' . self::demo_option_name . '[require_ssl]" type="checkbox" value="1" ' . checked( true, ! empty( $checked ), false ) . ' /></label>';
	}

	function get_settings( $config_settings = array() ) {

		$settings = array();

		$options = get_option( self::demo_option_name );

		foreach ( $config_settings as $key => $config_setting ) {

			if ( ! isset( $options[ $key ] ) || ! is_array( $config_setting ) ) {
				$settings[ $key ] = $config_setting;
				continue;
			}

			$settings[ $key ] = wp_parse_args( $options[ $key ], $config_setting );
		}

		return $settings;
	}


	function render_settings_page() { ?>

		<div class="wrap">
		<form action="options.php" method="post">
			<?php

			settings_fields( self::demo_option_name );

			do_settings_sections( self::demo_option_name );
			?>
			<input name="submit" class="button button-primary button-large" type="submit" value="<?php esc_attr_e( 'Save' ); ?>" />
		</form>
		</div>
		<?php
	}
}
