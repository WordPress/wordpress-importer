<?php
/**
 * E2E helper MU plugin: register meta used by tests so it’s exposed via REST.
 */

add_action(
	'init',
	function () {
		// Expose comment meta 'rating' and 'note' in REST for reading.
		register_meta(
			'comment',
			'rating',
			array(
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				// Allow read without auth in tests.
				'auth_callback' => function () {
					return true; },
			)
		);

		register_meta(
			'comment',
			'note',
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return true; },
			)
		);
	}
);
