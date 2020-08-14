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
class TrustedLogin_Example_Pages {

	/**
	 * @var \ReplaceMe\TrustedLogin\Config
	 */
	private $config;

	/**
	 * Start up
	 */
	public function __construct( $config ) {

		$this->config = $config;

		add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );

	}

	/**
	 * Add options page
	 */
	public function add_plugin_page() {

##		// This page would be under "Settings"
##		add_options_page(
##			'TrustedLogin Demo',
##			'TrustedLogin Demo',
##			'manage_options',
##			'trustedlogin-settings',
##			array( $this, 'create_admin_page' )
##		);
##
##		// This page would be under "Users"
##		add_users_page(
##			'TrustedLogin Demo',
##			'TrustedLogin Users',
##			'manage_options',
##			'trustedlogin-users',
##			array( $this, 'create_users_page' )
##		);
##
##		// This page would be under "Tools"
##		add_management_page(
##			'TrustedLogin Demo',
##			'TrustedLogin Demo',
##			'manage_options',
##			'trustedlogin-tools',
##			array( $this, 'create_admin_page' )
##		);

		// Add top level menu page
		add_menu_page(
			'TrustedLogin Demo',
			'TrustedLogin Demo',
			'manage_options',
			'trustedlogin-admin',
			array( $this, 'demo_landing_page' ),
			'dashicons-lock'
		);

		add_submenu_page(
			'trustedlogin-admin',
			'TrustedLogin Auth Screen',
			'TrustedLogin Auth',
			'manage_options',
			'trustedlogin-auth',
			array( $this, 'auth_demo_page' )
		);

		add_submenu_page(
			'trustedlogin-admin',
			'TrustedLogin Button',
			'TrustedLogin Button',
			'manage_options',
			'trustedlogin-button',
			array( $this, 'button_demo_page' )
		);

		add_submenu_page(
			'trustedlogin-admin',
			'TrustedLogin Users',
			'TrustedLogin Users',
			'manage_options',
			'trustedlogin-users',
			array( $this, 'user_table_demo_page' )
		);
	}

	public function demo_landing_page() {
		?>
		<div class="about-wrap full-width-layout">

			<?php do_action( 'trustedlogin/example/settings_form' ); ?>

			<hr>

			<h2>TrustedLogin has three customer-facing templates:</h2>

			<ul class="ul-disc">
				<li>Button</li>
				<li>Auth Screen</li>
				<li>Users Table</li>
			</ul>
		</div>
		<?php
	}

	public function auth_demo_page() {
		?>
		<div class="about-wrap full-width-layout">
			<h2>Output Auth Screen</h2>
			<p class="description">To include a TrustedLogin-generated Auth screen:</p>
			<pre lang="php">do_action( 'trustedlogin/<?php echo $this->config->ns(); ?>/auth_screen' );</pre>
			<hr>
			<?php
			do_action( 'trustedlogin/' . $this->config->ns() . '/auth_screen' );
			?>
		</div>
		<?php
	}

	public function user_table_demo_page() {
		?>
		<div class="about-wrap full-width-layout">
			<h2>Output a table of users</h2>
			<p class="description">To include a table of your active support users created with TrustedLogin:</p>
			<pre lang="php">do_action( 'trustedlogin/<?php echo $this->config->ns(); ?>/users_table' );</pre>
			<?php
			do_action( 'trustedlogin/' . $this->config->ns() . '/users_table' );
			?>
		</div>
		<?php
	}

	public function button_demo_page() {
		?>
		<div class="about-wrap full-width-layout">
            <h2>Output a TrustedLogin button</h2>
		<p class="description">Examples of using the TrustedLogin button generator:</p>
		<pre lang="php">$TL = new TrustedLogin;
echo $TL->get_button( 'size=normal&class=button-secondary' );
</pre>

		<div class="has-2-columns is-fullwidth">
			<div class="column">
				<h3 style="font-weight: normal;">Attributes: <code>size=hero</code></h3>
				<?php do_action( 'trustedlogin/' . $this->config->ns() . '/button', 'size=hero'); ?>
			</div>

			<div class="column">
				<h3 style="font-weight: normal;">Attributes: <code>size=hero&class=button-secondary</code></h3>
				<?php do_action( 'trustedlogin/' . $this->config->ns() . '/button', 'size=hero&class=button-secondary'); ?>
			</div>
		</div>

		<hr />

		<div class="has-2-columns is-fullwidth">
			<div class="column">
				<h3 style="font-weight: normal;">Attributes: <code>size=large</code></h3>
				<?php do_action( 'trustedlogin/' . $this->config->ns() . '/button', 'size=large'); ?>
			</div>

			<div class="column">
				<h3 style="font-weight: normal;">Attributes: <code>size=large&class=button-secondary</code></h3>
				<?php do_action( 'trustedlogin/' . $this->config->ns() . '/button', 'size=large&class=button-secondary'); ?>
			</div>
		</div>

		<hr />

		<div class="has-2-columns is-fullwidth">
			<div class="column">
				<h3 style="font-weight: normal;">Attributes: <code>size=normal</code></h3>
				<?php do_action( 'trustedlogin/' . $this->config->ns() . '/button', 'size=normal'); ?>
			</div>

			<div class="column">
				<h3 style="font-weight: normal;">Attributes: <code>size=normal&class=button-secondary</code></h3>
				<?php do_action( 'trustedlogin/' . $this->config->ns() . '/button', 'size=normal&class=button-secondary'); ?>
			</div>
		</div>

		<hr />

		<div class="has-2-columns is-fullwidth">
			<div class="column">
			<h3 style="font-weight: normal;">Attributes: <code>size=small</code></h3>
				<?php do_action( 'trustedlogin/' . $this->config->ns() . '/button', 'size=small'); ?>
			</div>

			<div class="column">
				<h3 style="font-weight: normal;">Attributes: <code>size=small&class=button-secondary</code></h3>
				<?php do_action( 'trustedlogin/' . $this->config->ns() . '/button', 'size=small&class=button-secondary'); ?>
			</div>
		</div>

		<hr />

		<div class="has-2-columns is-fullwidth">
			<div class="column">
			<h3 style="font-weight: normal;">Attributes: <code>size=&class=&powered_by=</code></h3>
				<?php do_action( 'trustedlogin/' . $this->config->ns() . '/button', 'size=&class=&powered_by=', false); ?>
			</div>
		</div>

	</div>
<?php
	}

}
