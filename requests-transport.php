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

require_once dirname(__FILE__) . '/class-requests-wphttp.php';
require_once dirname(__FILE__) . '/class-requests-wphttp-hooks.php';

$GLOBALS['requests_wphttp'] = new Requests_WPHTTP();
