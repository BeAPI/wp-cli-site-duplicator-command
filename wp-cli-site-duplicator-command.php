<?php
/**
 * Plugin Name:       WP-CLI Site Duplicator
 * Plugin URI:        https://github.com/BeAPI/wp-cli-site-duplicator-command
 * Description:       A WP-CLI command for duplicating a site (blog) on a WordPress mutisite network
 * Version:           2.0.1
 * Requires at least: 4.4
 * Requires PHP:      5.6
 * Author:            Be API
 * Author URI:        https://beapi.fr
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:
 * Domain Path:
 *
 * Based on original work of Kailey Lampert (kaileylampert.com) -- https://github.com/trepmal/blog-duplicator
 * and another works made by the Be API team !
 */

namespace BEAPI\SiteDuplicator;

use WP_CLI;

// Standard plugin security, keep this line in place.
defined( 'ABSPATH' ) || die();

if ( defined( 'WP_CLI' ) ) {
	include plugin_dir_path( __FILE__ ) . '/classes/command.php';

	WP_CLI::add_command( 'site duplicate', __NAMESPACE__ . '\\WP_CLI_Command' );
}
