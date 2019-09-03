<?php
/**
 * Class TrustedLoginAPITest
 *
 * @package Trustedlogin_Button
 */

/**
 * Sample test case.
 */
class TrustedLoginAPITest extends WP_UnitTestCase {

	/**
	 * @var TrustedLogin
	 */
	private $TrustedLogin;

	/**
	 * @var ReflectionClass
	 */
	private $TrustedLoginReflection;

	public function __construct() {

		$config = array(
			'role'             => array(
				'editor' => 'Support needs to be able to access your site as an administrator to debug issues effectively.',
			),
			'extra_caps'       => array(
				'manage_options' => 'we need this to make things work real gud',
				'edit_posts' => 'Access the posts that you created',
				'delete_users' => 'In order to manage the users that we thought you would want us to.',
			),
			'webhook_url' => '...',
			'auth' => array(
				'api_key' => '9946ca31be6aa948', // Public key for encrypting the securedKey
			),
			'decay' => WEEK_IN_SECONDS,
			'vendor' => array(
				'namespace' => 'gravityview',
				'title' => 'GravityView',
				'email' => 'support@gravityview.co',
				'website' => 'https://gravityview.co',
				'support_url' => 'https://gravityview.co/support/', // Backup to redirect users if TL is down/etc
				'logo_url' => '', // Displayed in the authentication modal
			),
			'reassign_posts' => true,
		);

		$this->TrustedLogin = new TrustedLogin( $config );

		$this->TrustedLoginReflection = new ReflectionClass('TrustedLogin' );
	}

	private function _get_public_method( $name ) {

		$method = $this->TrustedLoginReflection->getMethod( $name );
		$method->setAccessible( true );

		return $method;
	}

	private function _get_public_property( $name ) {

		$prop = $this->TrustedLoginReflection->getProperty( $name );
		$prop->setAccessible( true );

		return $prop;
	}

	/**
	 * @covers TrustedLogin::get_vault_tokens
	 * @covers TrustedLogin::set_vault_tokens
	 */
	public function test_get_vault_tokens() {

		$option_name = $this->_get_public_property( 'key_storage_option' )->getValue( $this->TrustedLogin );

		$this->assertEmpty( get_site_option( $option_name ) );

		$set_vault_tokens = $this->_get_public_method( 'set_vault_tokens' );
		$get_vault_tokens = $this->_get_public_method( 'get_vault_tokens' );

		$this->assertFalse( $get_vault_tokens->invoke( $this->TrustedLogin ) );

		$keys = array(
			'example1' => 'value1',
			'example2' => 'value2',
		);

		$this->assertTrue( $set_vault_tokens->invoke( $this->TrustedLogin, $keys ) );

		$this->assertEquals( $keys, $get_vault_tokens->invoke( $this->TrustedLogin ) );

		$this->assertEquals( 'value1', $get_vault_tokens->invoke( $this->TrustedLogin, 'example1' ) );
		$this->assertEquals( 'value2', $get_vault_tokens->invoke( $this->TrustedLogin, 'example2' ) );
		$this->assertFalse( $get_vault_tokens->invoke( $this->TrustedLogin, 'doesnt exist' ) );

	}
}
