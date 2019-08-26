<?php

/**
 * Class TrustedLogin_Basic_Debug_Logging
 *
 * Writes a plaintext log file.
 */
class TrustedLogin_Basic_Debug_Logging {

	/**
	 * @var string Relative path to the debugging file
	 */
	private $debug_filename = 'trustedlogin-debug-log.txt';

	/**
	 * TrustedLogin_Basic_Debug_Logging constructor.
	 *
	 */
	public function __construct( ) {

		add_action( 'trustedlogin/log', array( $this, 'dlog' ), 10, 3 );

	}


	/**
	 * Logs a message from TrustedLogin's class in the same directory as this file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text
	 * @param string $method The method that called the logging action
	 * @param string $level PSR-3 log level of the log
	 *
	 * @see https://github.com/php-fig/log/blob/master/Psr/Log/LogLevel.php for log levels
	 *
	 * @return void
	 */
	function dlog( $text, $method = null, $level = 'notice' ) {

		// open log file
		try {
			$filename = $this->debug_filename;
			$fh       = fopen( plugin_dir_path( dirname( __FILE__ ) ) . $filename, "a" );

			if ( false == $fh ) {
				error_log( __METHOD__ . " - Could not open log file: " . plugin_dir_path( __FILE__ ) . $filename, 0 );
				throw new Exception( '(ewi) Could not open log file.' );
			}

			if ( ! is_null( $method ) ) {
				$text = '' . $method . ' => ' . $text;
			}

			$fw = fwrite( $fh, date( "d-m-Y, H:i" ) . " - $text\n" );

			if ( false == $fw ) {
				error_log( __METHOD__ . " - Could not write file!", 0 );
			} else {
				fclose( $fh );
			}

		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( __METHOD__ . ' - ' . $text );
			}
		}

	}

}

new TrustedLogin_Basic_Debug_Logging();