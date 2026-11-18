<?php
/**
 * Raise a PHP notice before WordPress loads, as a constant defined twice in wp-config.php does.
 *
 * Loaded with "--require", which WP-CLI passes on to the subprocesses it launches. Before
 * WordPress sets up its error handling, PHP prints notices to STDOUT.
 */

trigger_error( 'Early notice raised before WordPress loads', E_USER_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
