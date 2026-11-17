<?php

namespace BEAPI\SiteDuplicator;

// Standard plugin security, keep this line in place.
defined( 'ABSPATH' ) || die();

use WP_CLI;

class WP_CLI_Command {

	/**
	 * Options for WP_CLI::runcommand() when the output is parsed.
	 */
	private const RUNCOMMAND_CAPTURE = [
		'launch'     => true,  // Launch a new process, or reuse the existing.
		'exit_error' => false, // Exit on error by default.
		'return'     => true,  // Capture and return output, or render in realtime.
		'parse'      => false, // Parse returned output as a particular format.
	];

	private int $origin_id             = 0;
	private string $origin_url         = '';
	private string $origin_url_uploads = '';

	/**
	 * @var string[]
	 */
	private array $origin_tables = [];

	private int $destination_id             = 0;
	private string $destination_name        = '';
	private string $destination_url         = '';
	private string $destination_slug        = '';
	private string $destination_url_uploads = '';
	private string $destination_email       = '';

	/**
	 * Duplicate a site
	 *
	 * ## OPTIONS
	 *
	 * <new-site-slug>
	 * : The subdomain/directory of the new site
	 *
	 * [<email>]
	 * : Email for admin user
	 *
	 * ## EXAMPLES
	 *
	 *     wp site duplicate domain-slug
	 *     wp site duplicate domain-slug admin@domain.com
	 *     wp site duplicate test-site-12 --url=multisite.local/test-site-3
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments, unused.
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_multisite() ) {
			WP_CLI::error( 'This is a multisite command only.' );
		}

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'A destination slug is missing.' );
		}

		$this->destination_slug  = $args[0];
		$this->destination_email = $args[1] ?? '';

		// Get info for origin site.
		$this->origin_id     = get_current_blog_id();
		$this->origin_url    = home_url();
		$this->origin_tables = $this->get_origin_tables();

		$this->destination_name = get_option( 'blogname' ) . ' copy';

		$this->create_site();
		$this->duplicate_tables();
		$this->fix_metadata();
		$this->copy_files();
		$this->search_replace();
		if ( defined( 'BEA_CSF_VERSION' ) ) {
			$this->duplicate_csf( $this->origin_id, $this->destination_id );
		}
		$this->flush_cache();

		WP_CLI::success( sprintf( 'Site %d created.', $this->destination_id ) );
	}

	private function create_site(): void {
		$_command = sprintf(
			'site create --slug=%s --porcelain --title=%s --network_id=%d --email=%s --url=%s',
			escapeshellarg( $this->destination_slug ),
			escapeshellarg( $this->destination_name ),
			get_current_network_id(),
			escapeshellarg( $this->destination_email ),
			escapeshellarg( $this->origin_url )
		);

		$output = WP_CLI::runcommand( $_command, self::RUNCOMMAND_CAPTURE );

		$this->destination_id = is_string( $output ) ? (int) trim( $output ) : 0;
		if ( 0 === $this->destination_id ) {
			WP_CLI::error( sprintf( 'Invalid new site ID %s', is_string( $output ) ? $output : '' ) );
		}

		$this->verbose_line( 'New site id:', (string) $this->destination_id );

		$this->destination_url = get_home_url( $this->destination_id );
	}

	private function copy_files(): void {
		$src_wp_upload_dir        = wp_upload_dir();
		$src_basedir              = $src_wp_upload_dir['basedir'];
		$this->origin_url_uploads = $src_wp_upload_dir['baseurl'];

		switch_to_blog( $this->destination_id );

		// Make upload destination.
		$dest_wp_upload_dir            = wp_upload_dir();
		$dest_basedir                  = $dest_wp_upload_dir['basedir'];
		$this->destination_url_uploads = $dest_wp_upload_dir['baseurl'];

		restore_current_blog();

		wp_mkdir_p( $dest_basedir );

		$_command = sprintf(
			'rsync -a %s %s --exclude sites',
			escapeshellarg( $src_basedir . '/' ),
			escapeshellarg( $dest_basedir )
		);

		WP_CLI::line( 'Duplicating uploads...' );
		$this->verbose_line( 'Running command:', $_command );
		WP_CLI::launch( $_command );
	}

	/**
	 * List the origin site's tables, without network-wide tables or other sites' tables.
	 *
	 * When the origin uses the base prefix (blog 1), "--all-tables-with-prefix" may also return
	 * global tables (users, blogs...) and the tables of every other site. Other sites are matched
	 * on their real prefix ("wp_2_"), not on "wp_<digits>_", so plugin tables such as
	 * "wp_404_to_301" stay in the copy.
	 *
	 * @return string[]
	 */
	private function get_origin_tables(): array {
		global $wpdb;

		$_command = sprintf( 'db tables --scope=blog --all-tables-with-prefix --url=%s --quiet', escapeshellarg( $this->origin_url ) );
		$output   = WP_CLI::runcommand( $_command, self::RUNCOMMAND_CAPTURE );

		$tables = array_filter( array_map( 'trim', explode( "\n", is_string( $output ) ? $output : '' ) ) );
		if ( empty( $tables ) ) {
			WP_CLI::error( sprintf( 'No table found for site %s.', $this->origin_url ) );
		}

		if ( $wpdb->get_blog_prefix( $this->origin_id ) !== $wpdb->base_prefix ) {
			return array_values( $tables );
		}

		$global_tables  = array_merge( $wpdb->tables( 'global' ), $wpdb->tables( 'ms_global' ) );
		$other_sites    = array_diff(
			array_map(
				'intval',
				get_sites(
					[
						'fields' => 'ids',
						'number' => 0,
					]
				)
			),
			[ $this->origin_id ]
		);
		$other_prefixes = array_map( static fn( int $site_id ): string => $wpdb->get_blog_prefix( $site_id ), $other_sites );

		$tables = array_filter(
			$tables,
			static function ( string $table ) use ( $global_tables, $other_prefixes ): bool {
				if ( in_array( $table, $global_tables, true ) ) {
					return false;
				}

				foreach ( $other_prefixes as $prefix ) {
					if ( str_starts_with( $table, $prefix ) ) {
						return false;
					}
				}

				return true;
			}
		);

		return array_values( $tables );
	}

	private function duplicate_tables(): void {
		global $wpdb;

		WP_CLI::line( sprintf( 'Duplicating %d tables...', count( $this->origin_tables ) ) );

		foreach ( $this->origin_tables as $origin_table ) {
			$new_table = $this->get_new_table_name( $origin_table );
			if ( $origin_table === $new_table ) {
				WP_CLI::error(
					sprintf(
						"Duplicate table can't have the same name has original table : %s",
						$origin_table
					)
				);
			}

			// Table names come from the database itself, they cannot be passed to prepare().
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS `$new_table`" );

			$results = $wpdb->query( "CREATE TABLE `$new_table` LIKE `$origin_table`" );
			if ( false === $results ) {
				WP_CLI::error( sprintf( 'Failed to create table %s from table %s', $new_table, $origin_table ) );
			}

			$results = $wpdb->query( "INSERT INTO `$new_table` SELECT * FROM `$origin_table`" );
			if ( false === $results ) {
				WP_CLI::error( sprintf( 'Failed to copy rows from table %s to table %s: %s', $origin_table, $new_table, $wpdb->last_error ) );
			}
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * Fix the roles option name and copy the users' roles to the new site.
	 */
	private function fix_metadata(): void {
		global $wpdb;

		$origin_prefix      = $wpdb->get_blog_prefix( $this->origin_id );
		$destination_prefix = $wpdb->get_blog_prefix( $this->destination_id );

		// Only the roles option carries the table prefix in its name (see WP_Roles).
		// A global REPLACE would also rename unrelated options such as
		// "wp_page_for_privacy_policy" when the origin is the main site.
		$wpdb->update(
			$destination_prefix . 'options',
			[ 'option_name' => $destination_prefix . 'user_roles' ],
			[ 'option_name' => $origin_prefix . 'user_roles' ]
		);

		// The admin set by "site create --email" must keep its role on the new site.
		$admin_user_id = $this->get_destination_admin_id();

		foreach ( [ 'capabilities', 'user_level' ] as $meta_suffix ) {
			$_query = $wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id != %d",
				$destination_prefix . $meta_suffix,
				$admin_user_id
			);
			$wpdb->query( $_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->verbose_line( 'Running query:', $_query );

			// Copy existing users' roles to the receiver site.
			$_query = $wpdb->prepare(
				"INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value)
				SELECT user_id, %s, meta_value
				FROM {$wpdb->usermeta}
				WHERE meta_key = %s AND user_id != %d",
				$destination_prefix . $meta_suffix,
				$origin_prefix . $meta_suffix,
				$admin_user_id
			);
			$wpdb->query( $_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->verbose_line( 'Running query:', $_query );
		}

		// Fix title after tables recopy.
		$wpdb->update(
			$destination_prefix . 'options',
			[ 'option_value' => $this->destination_name ],
			[ 'option_name' => 'blogname' ]
		);
	}

	/**
	 * Get the ID of the user passed as <email>, who must stay admin of the new site.
	 *
	 * @return int User ID, or 0 when no email was given or the user does not exist.
	 */
	private function get_destination_admin_id(): int {
		if ( '' === $this->destination_email ) {
			return 0;
		}

		$user = get_user_by( 'email', $this->destination_email );
		if ( false === $user ) {
			WP_CLI::warning( sprintf( 'No user found for email %s.', $this->destination_email ) );

			return 0;
		}

		return (int) $user->ID;
	}

	/**
	 * Swap the origin prefix for the destination one, at the start of the name only.
	 *
	 * @param string $table_name Origin table name.
	 *
	 * @return string Destination table name, or the unchanged name when it lacks the origin prefix.
	 */
	private function get_new_table_name( string $table_name ): string {
		global $wpdb;

		$origin_prefix      = $wpdb->get_blog_prefix( $this->origin_id );
		$destination_prefix = $wpdb->get_blog_prefix( $this->destination_id );

		if ( ! str_starts_with( $table_name, $origin_prefix ) ) {
			return $table_name;
		}

		return $destination_prefix . substr( $table_name, strlen( $origin_prefix ) );
	}

	private function search_replace(): void {
		// Long match first: replace the uploads URL, then the root URL.
		$replacements = [
			$this->origin_url_uploads => $this->destination_url_uploads,
			$this->origin_url         => $this->destination_url,
		];

		$step = 0;
		foreach ( $replacements as $search => $replace ) {
			++$step;
			WP_CLI::line( sprintf( "Search-replace'ing tables (%d/%d)...", $step, count( $replacements ) ) );

			$_command = sprintf(
				'search-replace --precise %s %s --url=%s --quiet',
				escapeshellarg( $search ),
				escapeshellarg( $replace ),
				escapeshellarg( $this->destination_url )
			);
			$this->verbose_line( 'Running command:', $_command );
			WP_CLI::runcommand( $_command );
		}
	}

	private function flush_cache(): void {
		$_command = sprintf( 'cache flush --url=%s', escapeshellarg( $this->destination_url ) );
		WP_CLI::runcommand( $_command );
		$this->verbose_line( 'Flush cache command:', $_command );
	}

	private function verbose_line( string $pre, string $text ): void {
		WP_CLI::debug(
			WP_CLI::colorize(
				"%C$pre%n $text"
			)
		);
	}

	/**
	 * Duplicate the CSF relationships of the original site for the new site
	 *
	 * @param int $origin_id Origin site ID.
	 * @param int $new_id    New site ID.
	 */
	private function duplicate_csf( int $origin_id, int $new_id ): void {
		$rows_relations = $this->get_relation_by_receiver_id( $origin_id );

		if ( empty( $rows_relations ) ) {
			WP_CLI::warning( sprintf( 'No relationship exists for the receiver ID : %d', $origin_id ) );

			return;
		}

		$total_origin_relations = count( $rows_relations );

		WP_CLI::line( sprintf( 'Duplicating %d rows...', $total_origin_relations ) );

		$inserted = $this->insert_news_relation( $rows_relations, $new_id );

		if ( $total_origin_relations !== $inserted ) {
			WP_CLI::warning( sprintf( 'Error while duplicating relation. Original relations count was %d and duplicated relations count is %d', $total_origin_relations, $inserted ) );
		}
	}

	/**
	 * Retrieve CSF relationships for a specific receiver site
	 *
	 * @param int $receiver_id Receiver site ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_relation_by_receiver_id( int $receiver_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->bea_csf_relations} WHERE receiver_blog_id=%d",
				$receiver_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Insert relationships for the new blog ID
	 *
	 * @param array<int, array<string, mixed>> $rows_relations Relations of the origin site.
	 * @param int                              $receiver_id    New site ID.
	 *
	 * @return int Number of inserted relations.
	 */
	private function insert_news_relation( array $rows_relations, int $receiver_id ): int {
		global $wpdb;

		$insert_rows = 0;

		foreach ( $rows_relations as $relation ) {
			$insert = $wpdb->insert(
				$wpdb->bea_csf_relations,
				[
					'type'             => $relation['type'],
					'emitter_blog_id'  => $relation['emitter_blog_id'],
					'emitter_id'       => $relation['emitter_id'],
					'receiver_blog_id' => $receiver_id,
					'receiver_id'      => $relation['receiver_id'],
				],
				[
					'%s',
					'%d',
					'%d',
					'%d',
					'%d',
				]
			);

			if ( false !== $insert ) {
				++$insert_rows;
				continue;
			}

			WP_CLI::warning(
				sprintf(
					'Error while duplicating relation : type %s / emitter_blog_id %d / emitter_id %d / receiver_blog_id %d / receiver_id %d : %s',
					$relation['type'],
					$relation['emitter_blog_id'],
					$relation['emitter_id'],
					$receiver_id,
					$relation['receiver_id'],
					$wpdb->last_error
				)
			);
		}

		return $insert_rows;
	}
}
