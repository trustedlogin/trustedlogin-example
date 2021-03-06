<?php
/**
 * Class Remote
 *
 * @package ReplaceMe\TrustedLogin\Client
 *
 * @copyright 2020 Katz Web Services, Inc.
 */
namespace ReplaceMe\TrustedLogin;

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
	exit;
}

use \Exception;
use \WP_Error;
use \WP_User;
use \WP_Admin_Bar;

/**
 * The TrustedLogin all-in-one drop-in class.
 */
final class Remote {

	/**
	 * @var string The API url for the TrustedLogin SaaS Platform (with trailing slash)
	 * @since 0.4.0
	 */
	const API_URL = 'https://app.trustedlogin.com/api/v1/';

	/**
	 * @var Config $config
	 */
	private $config;

	/**
	 * @var Logging $logging
	 */
	private $logging;

	/**
	 * SupportUser constructor.
	 */
	public function __construct( Config $config, Logging $logging ) {
		$this->config = $config;
		$this->logging = $logging;
	}

	public function init() {
		add_action( 'trustedlogin/' . $this->config->ns() . '/access/created', array( $this, 'maybe_send_webhook' ) );
		add_action( 'trustedlogin/' . $this->config->ns() . '/access/revoked', array( $this, 'maybe_send_webhook' ) );
	}

	/**
	 * POSTs to `webhook_url`, if defined in the configuration array
	 *
	 * @since 0.3.1
	 *
	 * @param array $data {
	 *   @type string $url The site URL as returned by get_site_url()
	 *   @type string $action "create" or "revoke"
	 * }
	 *
	 * @return bool|WP_Error False: webhook setting not defined; True: success; WP_Error: error!
	 */
	public function maybe_send_webhook( $data ) {

		$webhook_url = $this->config->get_setting( 'webhook_url' );

		if ( ! $webhook_url ) {
			return false;
		}

		if ( ! wp_http_validate_url( $webhook_url ) ) {

			$error = new WP_Error( 'invalid_webhook_url', 'An invalid `webhook_url` setting was passed to the TrustedLogin Client: ' . esc_attr( $webhook_url ) );

			$this->logging->log( $error, __METHOD__, 'error' );

			return $error;
		}

		try {

			$posted = wp_remote_post( $webhook_url, $data );

			if ( is_wp_error( $posted ) ) {
				$this->logging->log( 'An error encountered while sending a webhook to ' . esc_attr( $webhook_url ), __METHOD__, 'error', $posted );
				return $posted;
			}

			$this->logging->log( 'Webhook was sent to ' . esc_attr( $webhook_url ), __METHOD__, 'debug', $data );

			return true;

		} catch ( Exception $exception ) {

			$this->logging->log( 'A fatal error was triggered while sending a webhook to ' . esc_attr( $webhook_url ) . ': ' . $exception->getMessage(), __METHOD__, 'error' );

			return new WP_Error( $exception->getCode(), $exception->getMessage() );
		}
	}

	/**
	 * API Function: send the API request
	 *
	 * @since 0.4.0
	 *
	 * @param string $path - the path for the REST API request (no initial or trailing slash needed)
	 * @param array $data Data passed as JSON-encoded body for
	 * @param string $method
	 * @param array $additional_headers - any additional headers required for auth/etc
	 *
	 * @return array|WP_Error wp_remote_request() response or WP_Error if something went wrong
	 */
	public function send( $path, $data, $method = 'POST', $additional_headers = array() ) {

		$method = is_string( $method ) ? strtoupper( $method ) : $method;

		if ( ! is_string( $method ) || ! in_array( $method, array( 'POST', 'PUT', 'GET', 'HEAD', 'PUSH', 'DELETE' ), true ) ) {
			$this->logging->log( sprintf( 'Error: Method not in allowed array list (%s)', print_r( $method, true ) ), __METHOD__, 'critical' );

			return new WP_Error( 'invalid_method', sprintf( 'Error: HTTP method "%s" is not in the list of allowed methods', print_r( $method, true ) ) );
		}

		$headers = array(
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $this->config->get_setting( 'auth/public_key' ),
		);

		if ( ! empty( $additional_headers ) ) {
			$headers = array_merge( $headers, $additional_headers );
		}

		$request_options = array(
			'method'      => $method,
			'timeout'     => 45,
			'httpversion' => '1.1',
			'headers'     => $headers,
		);

		if ( ! empty( $data ) && ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$request_options['body'] = wp_json_encode( $data );
		}

		try {
			$api_url = $this->build_api_url( $path );

			$this->logging->log( sprintf( 'Sending to %s: %s', $api_url, print_r( $request_options, true ) ), __METHOD__, 'debug' );

			$response = wp_remote_request( $api_url, $request_options );

		} catch ( Exception $exception ) {

			$error = new WP_Error( 'wp_remote_request_exception', sprintf( 'There was an exception during the remote request: %s (%s)', $exception->getMessage(), $exception->getCode() ) );

			$this->logging->log( $error, __METHOD__, 'error' );

			return $error;
		}

		$this->logging->log( sprintf( 'Response: %s', print_r( $response, true ) ), __METHOD__, 'debug' );

		return $response;
	}

	/**
	 * Builds URL to API endpoints
	 *
	 * @since 0.9.3
	 *
	 * @param string $endpoint Endpoint to hit on the API; example "sites" or "sites/{$site_identifier}"
	 *
	 * @return string
	 */
	private function build_api_url( $endpoint = '' ) {

		/**
		 * Modifies the endpoint URL for the TrustedLogin service.
		 *
		 * @param string $url URL to TrustedLogin API
		 *
		 * @internal This allows pointing requests to testing servers
		 */
		$base_url = apply_filters( 'trustedlogin/' . $this->config->ns() . '/api_url', self::API_URL );

		if ( is_string( $endpoint ) ) {
			$url = trailingslashit( $base_url ) . $endpoint;
		} else {
			$url = trailingslashit( $base_url );
		}

		return $url;
	}

	/**
	 * API Response Handler
	 *
	 * @since 0.4.1
	 *
	 * @param array|WP_Error $api_response - the response from HTTP API
	 * @param array $required_keys If the response JSON must have specific keys in it, pass them here
	 *
	 * @return array|WP_Error If successful response, returns array of JSON data. If failed, returns WP_Error.
	 */
	public function handle_response( $api_response, $required_keys = array() ) {

		if ( is_wp_error( $api_response ) ) {

			$this->logging->log( sprintf( 'Request error (Code %s): %s', $api_response->get_error_code(), $api_response->get_error_message() ), __METHOD__, 'error' );

			return $api_response;
		}

		$this->logging->log( "Response: " . print_r( $api_response, true ), __METHOD__, 'debug' );

		$response_body = wp_remote_retrieve_body( $api_response );

		if ( empty( $response_body ) ) {
			$this->logging->log( "Response body not set: " . print_r( $response_body, true ), __METHOD__, 'error' );

			return new WP_Error( 'missing_response_body', __( 'The response was invalid.', 'trustedlogin' ), $api_response );
		}

		switch ( wp_remote_retrieve_response_code( $api_response ) ) {

			// Unauthenticated
			case 401:
				return new WP_Error( 'unauthenticated', __( 'Authentication failed.', 'trustedlogin' ), $response_body );
				break;

			// Problem with Token
			case 403:
				return new WP_Error( 'invalid_token', __( 'Invalid tokens.', 'trustedlogin' ), $response_body );
				break;

			// the KV store was not found, possible issue with endpoint
			case 404:
				return new WP_Error( 'not_found', __( 'The TrustedLogin vendor was not found.', 'trustedlogin' ), $response_body );
				break;

			// Server issue
			case 500:
				return new WP_Error( 'unavailable', __( 'The TrustedLogin site is not currently available.', 'trustedlogin' ), $response_body );
				break;

			case 501:
				return new WP_Error( 'server_error', __( 'The TrustedLogin site is not currently available.', 'trustedlogin' ), $response_body );
				break;

			// wp_remote_retrieve_response_code() couldn't parse the $api_response
			case '':
				return new WP_Error( 'invalid_response', __( 'Invalid response.', 'trustedlogin' ), $response_body );
				break;
		}

		$response_json = json_decode( $response_body, true );

		if ( empty( $response_json ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response.', 'trustedlogin' ), $response_body );
		}

		if ( isset( $response_json['errors'] ) ) {

			$errors = '';

			// Multi-dimensional; we flatten.
			foreach ( $response_json['errors'] as $key => $error ) {
				$error  = is_array( $error ) ? reset( $error ) : $error;
				$errors .= $error;
			}

			return new WP_Error( 'errors_in_response', esc_html( $errors ), $response_body );
		}

		foreach ( (array) $required_keys as $required_key ) {
			if ( ! isset( $response_json[ $required_key ] ) ) {
				return new WP_Error( 'missing_required_key', sprintf( __( 'Invalid response. Missing key: %s', 'trustedlogin' ), $required_key ), $response_body );
			}
		}

		return $response_json;
	}
}
