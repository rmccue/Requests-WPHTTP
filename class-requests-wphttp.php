<?php

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

		// TODO:
		// @type string       $httpversion         Version of the HTTP protocol to use. Accepts '1.0' and '1.1'.
		//                                         Default '1.0'.
		// =< Requests only supports 1.1 right now.

		// @type bool         $compress            Whether to compress the $body when sending the request.
		//                                         Default false.
		// => This isn't actually functional in WP_Http right now; should it be?

		// @type bool         $decompress          Whether to decompress a compressed response. If set to false and
		//                                         compressed content is returned in the response anyway, it will
		//                                         need to be separately decompressed. Default true.
		// => Is there any reason you wouldn't want responses decompressed?

		// @type int          $limit_response_size Size in bytes to limit the response to. Default null.
		// => Not available yet, but good idea. We fake support for it by using substr afterwards.

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

		/**
		 * Filter whether SSL should be verified for non-local requests.
		 *
		 * @since 2.8.0
		 *
		 * @param bool $ssl_verify Whether to verify the SSL connection. Default true.
		 */
		$options['verify'] = apply_filters( 'https_ssl_verify', $options['verify'] );

		try {
			$response = Requests::request($url, $headers, $data, $type, $options);
		}
		catch ( Requests_Exception $e ) {
			$response = new WP_Error( 'http_request_failed', $e->getMessage() );
		}

		/**
		 * Fires after an HTTP API response is received and before the response is returned.
		 *
		 * @since 2.8.0
		 *
		 * @param array|WP_Error $response HTTP response or WP_Error object.
		 * @param string         $context  Context under which the hook is fired.
		 * @param string         $class    HTTP transport used.
		 * @param array          $args     HTTP request arguments.
		 * @param string         $url      The request URL.
		 */
		do_action( 'http_api_debug', $response, 'response', 'Requests', $r, $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $r['blocking'] ) {
			return array(
				'headers' => array(),
				'body' => '',
				'response' => array(
					'code' => false,
					'message' => false,
				),
				'cookies' => array(),
			);
		}
		$data = array(
			'headers' => $this->get_header_array( $response->headers ),
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

		if ( isset( $r['limit_response_size'] ) && strlen( $data['body'] ) > $r['limit_response_size'] )
			$data['body'] = substr( $data['body'], 0, $r['limit_response_size'] );

		/**
		 * Filter the HTTP API response immediately before the response is returned.
		 *
		 * @since 2.9.0
		 *
		 * @param array  $data HTTP response.
		 * @param array  $r    HTTP request arguments.
		 * @param string $url  The request URL.
		 */
		return apply_filters( 'http_response', $data, $r, $url );
	}

	/**
	 * Convert headers to WP-style mixed strings and arrays
	 *
	 * @param Requests_Response_Headers $headers
	 * @return array
	 */
	protected function get_header_array( $headers ) {
		$converted = array();

		foreach ( $headers->getAll() as $key => $value ) {
			if ( count( $value ) === 1 ) {
				$converted[ $key ] = $value[0];
			}
			else {
				$converted[ $key ] = $value;
			}
		}

		return $converted;
	}
}
