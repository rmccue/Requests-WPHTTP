<?php

/**
 * Handles adding and dispatching events
 *
 * @package Requests
 * @subpackage Utilities
 */
class Requests_WPHTTP_Hooks implements Requests_Hooker {
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
		do_action_ref_array('requests-' . $hook, $parameters);
		return true;
	}
}
