<?php
/**
 * Class TrustedLoginAPITest
 *
 * @package Trustedlogin_Button
 */

use Example;

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

	/**
	 * @var array
	 */
	private $config;

	public function __construct() {

		$this->config = array(
			'role'           => array(
				'editor' => 'Support needs to be able to access your site as an administrator to debug issues effectively.',
			),
			'extra_caps'     => array(
				'manage_options' => 'we need this to make things work real gud',
				'edit_posts'     => 'Access the posts that you created',
				'delete_users'   => 'In order to manage the users that we thought you would want us to.',
			),
			'webhook_url'    => '...',
			'auth'           => array(
				'api_key'     => '9946ca31be6aa948', // Public key for encrypting the securedKey
				'license_key' => 'my custom key',
			),
			'decay'          => WEEK_IN_SECONDS,
			'vendor'         => array(
				'namespace'   => 'gravityview',
				'title'       => 'GravityView',
				'email'       => 'support@gravityview.co',
				'website'     => 'https://gravityview.co',
				'support_url' => 'https://gravityview.co/support/', // Backup to redirect users if TL is down/etc
				'logo_url'    => '', // Displayed in the authentication modal
			),
			'reassign_posts' => true,
		);

		$this->TrustedLogin = new TrustedLogin( $this->config );

		$this->TrustedLoginReflection = new ReflectionClass( 'TrustedLogin' );
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

	/**
	 * @covers TrustedLogin::get_license_key
	 */
	public function test_get_license_key() {

		$this->assertSame( $this->config['auth']['license_key'], $this->TrustedLogin->get_license_key() );

		add_filter( 'trustedlogin/' . $this->config['vendor']['namespace'] . '/licence_key', '__return_zero' );

		$this->assertSame( 0, $this->TrustedLogin->get_license_key() );

		remove_filter( 'trustedlogin/' . $this->config['vendor']['namespace'] . '/licence_key', '__return_zero' );

	}

	/**
	 * @covers TrustedLogin::handle_response
	 */
	public function test_handle_response() {

		// Response is an error itself
		$WP_Error = new WP_Error( 'example', 'Testing 123' );
		$this->assertSame( $WP_Error, $this->TrustedLogin->handle_response( $WP_Error ) );

		// Missing body
		$this->assertWPError( $this->TrustedLogin->handle_response( array( 'body' => '' ) ) );
		$this->assertSame( 'missing_response_body', $this->TrustedLogin->handle_response( array( 'body' => '' ) )->get_error_code() );

		// Verify error response codes
		$error_codes = array(
			'unauthenticated'  => 401,
			'invalid_token'    => 403,
			'not_found'        => 404,
			'unavailable'      => 500,
			'invalid_response' => '',
		);

		foreach ( $error_codes as $error_code => $response_code ) {

			$invalid_code_response = array(
				'body'     => 'Not Empty',
				'response' => array(
					'code' => $response_code,
				),
			);

			$handled_response = $this->TrustedLogin->handle_response( $invalid_code_response );

			$this->assertWPError( $handled_response );
			$this->assertSame( $error_code, $handled_response->get_error_code(), $response_code . ' should have triggered ' . $error_code );
		}

		// Verify invalid JSON
		$invalid_json_response = array(
			'body'     => 'Not JSON, that is for sure.',
			'response' => array(
				'code' => 200,
			),
		);

		$handled_response = $this->TrustedLogin->handle_response( $invalid_json_response );

		$this->assertWPError( $handled_response );
		$this->assertSame( 'invalid_response', $handled_response->get_error_code(), $response_code . ' should have triggered ' . $error_code );
		$this->assertSame( 'Not JSON, that is for sure.', $handled_response->get_error_data( 'invalid_response' ) );


		// Finally, VALID JSON
		$valid_json_response = array(
			'body'     => '{"message":"This works"}',
			'response' => array(
				'code' => 200,
			),
		);

		$handled_response = $this->TrustedLogin->handle_response( $valid_json_response );
		$this->assertNotWPError( $handled_response );
		$this->assertSame( array( 'message' => 'This works' ), $handled_response );

		$handled_response = $this->TrustedLogin->handle_response( $valid_json_response, 'message' );
		$this->assertNotWPError( $handled_response );
		$this->assertSame( array( 'message' => 'This works' ), $handled_response );

		$handled_response = $this->TrustedLogin->handle_response( $valid_json_response, array( 'missing_key' ) );
		$this->assertWPError( $handled_response );
		$this->assertSame( 'missing_required_key', $handled_response->get_error_code() );
	}
}
