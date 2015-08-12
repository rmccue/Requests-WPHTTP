<?php
/**
 * Plugin Name: Requests for PHP Transport
 * Description: Use the Requests for PHP library to handle HTTP requests
 * Author: Ryan McCue
 * Author URI: http://ryanmccue.info/
 * Version: 1.0
 */

require_once dirname(__FILE__) . '/requests/library/Requests.php';
Requests::register_autoloader();

$GLOBALS['requests_wphttp'] = new Requests_WPHTTP();

class Requests_WPHTTP {
	protected $hook_adapter;

	public function __construct() {
		add_filter('pre_http_request', array( $this, 'request' ), -100, 3);

		$this->hook_adapter = new Requests_WPHTTP_Hooks();
	}

	/**
	 * Adapt the request to Requests
	 *
	 * @param boolean|mixed $response Filtered value
	 * @param array $r WP HTTP options array
	 * @param string $url Requested URL
	 * @return array|WP_Error Response data
	 */
	public function request($response, $r, $url) {
		// Currently ignored options:
		// * 'compress' - doesn't actually do anything in WP_HTTP
		// * 'decompress'
		// * 'sslverify'
		// * 'httpversion'

		if ( function_exists( 'wp_kses_bad_protocol' ) ) {
			if ( $r['reject_unsafe_urls'] )
				$url = wp_http_validate_url( $url );
			$url = wp_kses_bad_protocol( $url, array( 'http', 'https', 'ssl' ) );
		}

		$wp_http = _wp_http_get_object();

		if ( $wp_http->block_request( $url ) )
			return new WP_Error( 'http_request_failed', __( 'User has blocked requests through HTTP.' ) );

		// If we are streaming to a file but no filename was given drop it in the WP temp dir
		// and pick its name using the basename of the $url
		if ( $r['stream'] ) {
			if ( empty( $r['filename'] ) ) {
				$r['filename'] = get_temp_dir() . basename( $url );
			}

			// Force some settings if we are streaming to a file and check for existence and perms of destination directory
			$r['blocking'] = true;
			if ( ! wp_is_writable( dirname( $r['filename'] ) ) )
				return new WP_Error( 'http_request_failed', __( 'Destination directory for file streaming does not exist or is not writable.' ) );
		}

		if ( is_null( $r['headers'] ) ) {
			$r['headers'] = array();
		}

		// WP allows passing in headers as a string, weirdly.
		if ( ! is_array( $r['headers'] ) ) {
			$processedHeaders = WP_Http::processHeaders( $r['headers'] );
			$r['headers'] = $processedHeaders['headers'];
		}

		// Setup arguments
		$headers = $r['headers'];
		$data = $r['body'];
		$type = $r['method'];
		$options = array(
			'timeout' => $r['timeout'],
			'useragent' => $r['user-agent'],
			'blocking' => $r['blocking'],
			'hooks' => $this->hook_adapter,
		);

		if ( $r['stream'] ) {
			$options['filename'] = $r['filename'];
		}
		if ( empty( $r['redirection'] ) ) {
			$options['follow_redirects'] = false;
		}
		else {
			$options['redirects'] = $r['redirection'];
		}

		// If we've got cookies, use them
		if ( ! empty( $r['cookies'] ) ) {
			$options['cookies'] = $r['cookies'];
		}

		// SSL certificate handling
		if ( ! $r['sslverify'] ) {
			$options['verify'] = false;
		}
		else {
			$options['verify'] = $r['sslcertificates'];
		}

		try {
			$response = Requests::request($url, $headers, $data, $type, $options);
		}
		catch (Requests_Exception $e) {
			return new WP_Error( 'http_request_failed', $e->getMessage() );
		}

		if ( ! $r['blocking'] ) {
			return array( 'headers' => array(), 'body' => '', 'response' => array('code' => false, 'message' => false), 'cookies' => array() );
		}
		$data = array(
			'headers' => $response->headers,
			'body' => $response->body,
			'response' => array(
				'code' => $response->status_code,
				'message' => get_status_header_desc( $response->status_code ),
			),
			'cookies' => array(),
			'filename' => $r['filename']
		);

		$cookies = $response->headers['set-cookie'];
		if ( ! empty( $cookies ) ) {
			$values = explode(',', $cookies);
			foreach ($cookies as $value) {
				$data['cookies'][] = new WP_Http_Cookie( $value );
			}
		}

		if ( isset( $r['limit-response-size'] ) && strlen( $process['body'] ) > $r['limit-response-size'] )
			$data['body'] = substr( $process['body'], 0, $r['limit-response-size'] );

		return $data;
	}
}

/**
 * Handles adding and dispatching events
 *
 * @package Requests
 * @subpackage Utilities
 */
class Requests_WPHTTP_Hooks {
	/**
	 * Constructor
	 */
	public function __construct() {
		// pass
	}

	/**
	 * Register a callback for a hook
	 *
	 * Defers to the WP hooking system, but adds 10 to the priority since WP's
	 * default is 10 rather than 0. Also sets the parameter count as high as
	 * possible.
	 *
	 * @param string $hook Hook name
	 * @param callback $callback Function/method to call on event
	 * @param int $priority Priority number. <0 is executed earlier, >0 is executed later
	 */
	public function register($hook, $callback, $priority = 0) {
		add_filter('requests-' . $hook, $callback, $priority + 10, 9999);
	}

	/**
	 * Dispatch a message
	 *
	 * @param string $hook Hook name
	 * @param array $parameters Parameters to pass to callbacks
	 * @return boolean Successfulness
	 */
	public function dispatch($hook, $parameters = array()) {
		if (empty($this->hooks[$hook])) {
			return false;
		}

		apply_filters_ref_array('requests-' . $hook, $parameters);
		return true;
	}
}