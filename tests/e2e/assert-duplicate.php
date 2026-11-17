<?php
/**
 * E2E assertions for "wp site duplicate", run through `wp eval-file`.
 *
 * Arguments: <origin-slug|main> <destination-slug> [<admin-email>]
 *
 * @package BEAPI\SiteDuplicator
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

global $wpdb;

if ( empty( $args[0] ) || empty( $args[1] ) ) {
	WP_CLI::error( 'Usage: wp eval-file assert-duplicate.php <origin-slug|main> <destination-slug> [<admin-email>]' );
}

$origin_is_main = 'main' === $args[0];
$origin_id      = $origin_is_main ? get_main_site_id() : (int) get_id_from_blogname( $args[0] );
$dest_id        = (int) get_id_from_blogname( $args[1] );
$admin_email    = isset( $args[2] ) ? $args[2] : '';

if ( 0 === $origin_id || 0 === $dest_id ) {
	WP_CLI::error( sprintf( 'Unknown site: origin %s (%d), destination %s (%d).', $args[0], $origin_id, $args[1], $dest_id ) );
}

$failures = [];
$check    = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$origin_prefix = $wpdb->get_blog_prefix( $origin_id );
$dest_prefix   = $wpdb->get_blog_prefix( $dest_id );
$origin_url    = untrailingslashit( get_home_url( $origin_id ) );
$dest_url      = untrailingslashit( get_home_url( $dest_id ) );

$list_tables = static function ( $prefix ) use ( $wpdb ) {
	return $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
};

/*
 * Tables: every origin table has a copy with the same row count.
 * Options are left out of the count, the copy may get new transients.
 */
$global_tables = array_merge( $wpdb->tables( 'global' ), $wpdb->tables( 'ms_global' ) );
$origin_tables = array_filter(
	$list_tables( $origin_prefix ),
	static function ( $table ) use ( $origin_is_main, $global_tables, $wpdb ) {
		if ( ! $origin_is_main ) {
			return true;
		}

		return ! in_array( $table, $global_tables, true ) && ! preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/', $table );
	}
);
$dest_tables   = $list_tables( $dest_prefix );

$check( in_array( $origin_prefix . 'e2e_custom', $origin_tables, true ), 'Seed issue: the origin custom table is missing.' );

foreach ( $origin_tables as $origin_table ) {
	$dest_table = $dest_prefix . substr( $origin_table, strlen( $origin_prefix ) );
	if ( ! in_array( $dest_table, $dest_tables, true ) ) {
		$check( false, sprintf( 'Table %s was not copied to %s.', $origin_table, $dest_table ) );
		continue;
	}

	if ( $origin_prefix . 'options' === $origin_table ) {
		continue;
	}

	$origin_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$origin_table`" );
	$dest_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$dest_table`" );
	$check( $origin_count === $dest_count, sprintf( 'Row count differs: %s has %d rows, %s has %d.', $origin_table, $origin_count, $dest_table, $dest_count ) );
}

if ( $origin_is_main ) {
	foreach ( $global_tables as $global_table ) {
		$suffix = substr( $global_table, strlen( $wpdb->base_prefix ) );
		$check( ! in_array( $dest_prefix . $suffix, $dest_tables, true ), sprintf( 'Global table %s was copied as %s.', $global_table, $dest_prefix . $suffix ) );
	}

	$child_copies = preg_grep( '/^' . preg_quote( $dest_prefix, '/' ) . '\d+_/', $dest_tables );
	$check( empty( $child_copies ), sprintf( 'Child site tables were copied: %s.', implode( ', ', (array) $child_copies ) ) );
}

/*
 * Options: names kept, except the prefix-scoped roles option.
 */
$origin_options = $wpdb->get_col( "SELECT option_name FROM `{$origin_prefix}options` WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'" );
$dest_options   = $wpdb->get_col( "SELECT option_name FROM `{$dest_prefix}options`" );

foreach ( $origin_options as $option_name ) {
	$expected = $origin_prefix . 'user_roles' === $option_name ? $dest_prefix . 'user_roles' : $option_name;
	$check( in_array( $expected, $dest_options, true ), sprintf( 'Option %s is missing on the copy (expected name %s).', $option_name, $expected ) );
}

$check( ! in_array( $origin_prefix . 'user_roles', $dest_options, true ) || $origin_prefix === $dest_prefix, 'The copy still has the origin roles option name.' );

switch_to_blog( $origin_id );
$origin_blogname = get_option( 'blogname' );
restore_current_blog();

switch_to_blog( $dest_id );
$expected_blogname = $origin_blogname . ' copy';
$dest_blogname     = get_option( 'blogname' );
$dest_home         = untrailingslashit( get_option( 'home' ) );
$check( $expected_blogname === $dest_blogname, sprintf( 'Unexpected blogname "%s".', $dest_blogname ) );
$check( $dest_url === $dest_home, sprintf( 'Unexpected home option %s.', $dest_home ) );
$check( is_array( get_option( $dest_prefix . 'user_roles' ) ), 'The roles option is not readable on the copy.' );
if ( $origin_is_main ) {
	$check( 'main-flag' === get_option( 'wp_e2e_flag' ), 'Option wp_e2e_flag was renamed or lost on the main site copy.' );
}
restore_current_blog();

/*
 * Users: roles and user levels copied once, the <email> user is administrator.
 */
$admin_id = 0;
if ( '' !== $admin_email ) {
	$admin_user = get_user_by( 'email', $admin_email );
	$check( false !== $admin_user, sprintf( 'No user created for %s.', $admin_email ) );
	$admin_id = false !== $admin_user ? (int) $admin_user->ID : 0;
}

$origin_caps = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $origin_prefix . 'capabilities' ), OBJECT_K );
$check( count( $origin_caps ) >= 2, 'Seed issue: the origin site should have at least two members.' );

foreach ( [ 'capabilities', 'user_level' ] as $meta_suffix ) {
	$duplicates = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY user_id HAVING COUNT(*) > 1",
			$dest_prefix . $meta_suffix
		)
	);
	$check( empty( $duplicates ), sprintf( 'Duplicate %s rows for users %s.', $dest_prefix . $meta_suffix, implode( ', ', $duplicates ) ) );
}

foreach ( array_keys( $origin_caps ) as $user_id ) {
	if ( (int) $user_id === $admin_id ) {
		continue;
	}

	$check(
		get_user_meta( $user_id, $origin_prefix . 'capabilities', true ) == get_user_meta( $user_id, $dest_prefix . 'capabilities', true ), // phpcs:ignore Universal.Operators.StrictComparisons
		sprintf( 'User %d does not have the same role on the copy.', $user_id )
	);
	$check(
		get_user_meta( $user_id, $origin_prefix . 'user_level', true ) === get_user_meta( $user_id, $dest_prefix . 'user_level', true ),
		sprintf( 'User %d does not have the same user_level on the copy.', $user_id )
	);
}

if ( 0 !== $admin_id ) {
	$admin_caps = get_user_meta( $admin_id, $dest_prefix . 'capabilities', true );
	$check( is_array( $admin_caps ) && ! empty( $admin_caps['administrator'] ), sprintf( 'User %s is not administrator of the copy.', $admin_email ) );
}

/*
 * Content and uploads: URLs point to the copy, files exist, origin untouched.
 */
$read_site = static function ( $site_id ) {
	switch_to_blog( $site_id );
	$data          = [
		'posts'      => get_posts(
			[
				'post_type'   => 'post',
				'numberposts' => 1,
			]
		),
		'images'     => get_posts(
			[
				'post_type'   => 'attachment',
				'numberposts' => -1,
			]
		),
		'upload_dir' => wp_upload_dir(),
	];
	$data['files'] = array_map( 'get_attached_file', wp_list_pluck( $data['images'], 'ID' ) );
	restore_current_blog();

	return $data;
};

$origin_data = $read_site( $origin_id );
$dest_data   = $read_site( $dest_id );

$check( ! empty( $dest_data['posts'] ), 'The copy has no post.' );
if ( ! empty( $dest_data['posts'] ) && ! empty( $origin_data['posts'] ) ) {
	$dest_content   = $dest_data['posts'][0]->post_content;
	$origin_content = $origin_data['posts'][0]->post_content;

	$check( false !== strpos( $dest_content, $dest_url . '/sample/' ), 'Post links were not replaced with the copy URL.' );
	// wp_upload_dir() keeps the current site's WP_CONTENT_URL after switch_to_blog(),
	// so match the uploads path of the copy instead of its full base URL.
	$check( false !== strpos( $dest_content, $dest_url . '/wp-content/uploads/sites/' . $dest_id . '/' ), 'Image URL does not point to the copy uploads.' );
	$check( false !== strpos( $origin_content, $origin_url . '/sample/' ), 'The origin post was modified.' );
	if ( ! $origin_is_main ) {
		$check( false === strpos( $dest_content, $origin_url . '/' ), 'The copy still links to the origin site.' );
	}
}

$check( ! empty( $dest_data['files'] ), 'The copy has no attachment.' );
foreach ( $dest_data['files'] as $file ) {
	$check( is_string( $file ) && 0 === strpos( $file, $dest_data['upload_dir']['basedir'] ) && file_exists( $file ), sprintf( 'Attachment file missing on the copy: %s.', (string) $file ) );
}

if ( $origin_is_main ) {
	$check( ! is_dir( $dest_data['upload_dir']['basedir'] . '/sites' ), 'The main site copy received the uploads of the child sites.' );
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		WP_CLI::warning( $failure );
	}
	WP_CLI::error( sprintf( '%d assertion(s) failed for %s -> %s.', count( $failures ), $args[0], $args[1] ) );
}

WP_CLI::success( sprintf( 'Duplication %s -> %s (site %d) is valid.', $args[0], $args[1], $dest_id ) );
