<?php

namespace BEAPI\SiteDuplicator;

// Standard plugin security, keep this line in place.
defined( 'ABSPATH' ) || die();

use WP_CLI;

class WP_CLI_Command {

	private $verbose = false;

	private $origin_id = 0;
	private $origin_url = '';
	private $origin_url_uploads = '';
	private $origin_tables = array();

	private $destination_id = 0;
	private $destination_name = '';
	private $destination_url = '';
	private $destination_slug = '';
	private $destination_url_uploads = '';

	/**
	 * Duplicate a site
	 *
	 * ## OPTIONS
	 *
	 * <new-site-slug>
	 * : The subdomain/directory of the new site
	 *
	 * ## EXAMPLES
	 *
	 *     wp site duplicate domain-slug
	 *     wp site duplicate test-site-12 --url=multisite.local/test-site-3
	 */
	public function __invoke( $args, $assoc_args ) {
		if ( ! is_multisite() ) {
			WP_CLI::error( 'This is a multisite command only.' );
		}

		if ( ! isset( $args[0] ) && empty( $args[0] ) ) {
			WP_CLI::error( 'A destination slug is missing.' );
		}

		$this->destination_slug = $args[0];

		// get info for origin site
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

		WP_CLI::success( sprintf( 'Site %s created.', $this->destination_id ) );
	}

	private function create_site() {
		$origin_network_id = get_current_network_id();

		// first step
		$_command = sprintf(
			'site create --slug=%s --porcelain --title="%s" --network_id=%d --url="%s"',
			$this->destination_slug,
			$this->destination_name,
			$origin_network_id,
			$this->origin_url
		);

		$this->destination_id = WP_CLI::runcommand(
			$_command,
			array(
				'launch'     => true, // Launch a new process, or reuse the existing.
				'exit_error' => false, // Exit on error by default.
				'return'     => true, // Capture and return output, or render in realtime.
				'parse'      => false, // Parse returned output as a particular format.
			)
		);

		if ( 0 === (int) $this->destination_id ) {
			WP_CLI::error( sprintf( 'Invalid new site ID %s', $this->destination_id ) );
		}

		$this->verbose_line( 'New site id:', $this->destination_id );

		$this->destination_url = get_home_url( $this->destination_id );
	}

	private function copy_files() {
		$src_wp_upload_dir        = wp_upload_dir();
		$src_basedir              = $src_wp_upload_dir['basedir'];
		$this->origin_url_uploads = $src_wp_upload_dir['baseurl'];

		switch_to_blog( $this->destination_id );

		// make upload destination
		$dest_wp_upload_dir            = wp_upload_dir();
		$dest_basedir                  = $dest_wp_upload_dir['basedir'];
		$this->destination_url_uploads = $dest_wp_upload_dir['baseurl'];

		restore_current_blog();

		// Recursive directory creation
		wp_mkdir_p( $dest_basedir );

		// copy files via rsync
		WP_CLI::line( 'Duplicating uploads...' );
		$this->verbose_line(
			'Running command:',
			"rsync -a {$src_basedir}/ {$dest_basedir} --exclude sites"
		);
		WP_CLI::launch(
			WP_CLI\Utils\esc_cmd(
				'rsync -a %s/ %s --exclude sites',
				$src_basedir,
				$dest_basedir
			)
		);
	}

	private function get_origin_tables() {
		$_command      = "db tables --scope=blog --all-tables-with-prefix --url=$this->origin_url --quiet";
		$origin_tables = WP_CLI::runcommand(
			$_command,
			array(
				'launch'     => true, // Launch a new process, or reuse the existing.
				'exit_error' => false, // Exit on error by default.
				'return'     => true, // Capture and return output, or render in realtime.
				'parse'      => false, // Parse returned output as a particular format.
			)
		);
		$origin_tables = explode( PHP_EOL, $origin_tables );

		return $origin_tables;
	}

	private function duplicate_tables() {
		global $wpdb;

		$tables = $this->get_origin_tables();

		WP_CLI::line( sprintf( 'Duplicating %d tables...', count( $tables ) ) );

		foreach ( $tables as $origin_table ) {
			$new_table = $this->get_new_table_name( $origin_table );
			if ( $origin_table === $new_table ) {
				WP_CLI::error(
					sprintf(
						"Duplicate table can't have the same name has original table : %s",
						$origin_table
					)
				);
			}

			$wpdb->query( sprintf( 'DROP TABLE IF EXISTS %s;', esc_sql( $new_table ) ) );
			$results = $wpdb->query(
				sprintf(
					'CREATE TABLE %s LIKE %s;',
					esc_sql( $new_table ),
					esc_sql( $origin_table )
				)
			);
			if ( false === $results ) {
				WP_CLI::error( sprintf( 'Failed to create table %s from table %s', $new_table, $origin_table ) );
			}

			$wpdb->query(
				sprintf(
					'INSERT INTO %s SELECT * FROM %s',
					esc_sql( $new_table ),
					esc_sql( $origin_table )
				)
			);
		}
	}

	/**
	 * Fix option_name and user_key with table prefix
	 *
	 * @param bool $keep_user
	 */
	public function fix_metadata( $keep_user = true ) {
		global $wpdb;

		$origin_prefix      = $wpdb->get_blog_prefix( $this->origin_id );
		$destination_prefix = $wpdb->get_blog_prefix( $this->destination_id );

		$option_table = esc_sql( $destination_prefix . 'options' );
		//phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$_query = $wpdb->prepare(
		//phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE $option_table SET option_name = REPLACE(option_name, %s, %s )",
			//phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$origin_prefix,
			$destination_prefix
		);
		$wpdb->query( $_query );
		$this->verbose_line( 'Running query:', $_query );
		//phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		//phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$_query = $wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
			//phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$destination_prefix . 'capabilities'
		);
		$wpdb->query( $_query );
		$this->verbose_line( 'Running query:', $_query );
		//phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		/**
		 * Copy existing users's capabilities to the receiver site.
		 */
		if ( true === $keep_user ) {
			//phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			//phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$_query = $wpdb->prepare(
				"
					INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value)
					SELECT user_id, %s, meta_value
					FROM {$wpdb->usermeta}
					WHERE meta_key = %s;
					",
				$destination_prefix . 'capabilities',
				$origin_prefix . 'capabilities'
			);
			$wpdb->query( $_query );
			$this->verbose_line( 'Running query:', $_query );

			$_query_2 = $wpdb->prepare(
				"
					INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value)
					SELECT user_id, %s, meta_value
					FROM {$wpdb->usermeta}
					WHERE meta_key = %s;
					",
				$destination_prefix . 'user_level ',
				$origin_prefix . 'user_level '
			);
			$wpdb->query( $_query_2 );
			$this->verbose_line( 'Running query:', $_query_2 );
			//phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			//phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		}

		// Fix title after tables recopy
		$wpdb->update(
			$destination_prefix . 'options',
			[ 'option_value' => $this->destination_name ],
			[ 'option_name' => 'blogname' ]
		);
	}

	/**
	 * @return string
	 *
	 * @param string $table_name
	 */
	private function get_new_table_name( $table_name ) {
		global $wpdb;

		$origin_prefix      = $wpdb->get_blog_prefix( $this->origin_id );
		$destination_prefix = $wpdb->get_blog_prefix( $this->destination_id );

		return str_replace( $origin_prefix, $destination_prefix, $table_name );
	}

	private function search_replace() {
		// long match first, replace upload url
		WP_CLI::line( "Search-replace'ing tables (1/2)..." );
		$_command = "search-replace --precise '$this->origin_url_uploads' '$this->destination_url_uploads' --url=$this->destination_url --quiet";
		$this->verbose_line( 'Running command:', $_command );
		WP_CLI::runcommand( $_command );

		// replace root url
		WP_CLI::line( "Search-replace'ing tables (2/2)..." );
		$_command = "search-replace --precise '$this->origin_url' '$this->destination_url' --url=$this->destination_url --quiet";
		$this->verbose_line( 'Running command:', $_command );
		WP_CLI::runcommand( $_command );
	}

	private function flush_cache() {
		// Flush object cache
		$_command = "cache flush --url=$this->destination_url";
		WP_CLI::runcommand( $_command );
		$this->verbose_line( 'Flush cache command:', $_command );
	}

	/**
	 * @param string $pre
	 * @param string $text
	 */
	private function verbose_line( $pre, $text ) {
		WP_CLI::debug(
			WP_CLI::colorize(
				"%C$pre%n $text"
			)
		);
	}

	/**
	 * Duplicate the CSF relationships of the original site for the new site
	 *
	 * @param int $origin_id
	 * @param int $new_id
	 *
	 * @return void
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
	 * @param int $receiver_id
	 *
	 * @return array|object|\stdClass[]|null
	 */
	private function get_relation_by_receiver_id( int $receiver_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->bea_csf_relations} WHERE receiver_blog_id=%d",
				$receiver_id
			),
			ARRAY_A
		);
	}

	/**
	 * Insert relationships for the new blog ID
	 *
	 * @param array $rows_relations
	 * @param int $receiver_id
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
				$insert_rows ++;
			}

			if ( false === $insert ) {
				WP_CLI::warning(
					sprintf(
						'Error while duplicating relation : type %s / emitter_blog_id %d / emitter_id %d / receiver_blog_id %d / receiver_id %d : %s',
						$relation['type'],
						$relation['emitter_blog_id'],
						$relation['emitter_id'],
						$receiver_id,
						$relation['receiver_id'],
						$wpdb->last_error,
					)
				);
			}
		}

		return $insert_rows;
	}
}
