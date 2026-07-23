<?php

namespace ArgentSentinel\WordPress\Database;

final class Schema {
	public const VERSION        = 1;
	public const VERSION_OPTION = 'argent_sentinel_schema_version';
	public const TABLE_SUFFIX   = 'argent_sentinel_events';

	/**
	 * @param object $wpdb WordPress database object.
	 */
	public function install( $wpdb ): bool {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table_name      = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_uuid char(36) NOT NULL,
			batch_uuid char(36) DEFAULT NULL,
			occurred_at_utc datetime NOT NULL,
			recorded_at_utc datetime NOT NULL,
			site_id varchar(191) NOT NULL,
			site_url varchar(512) NOT NULL,
			source_host varchar(191) NOT NULL,
			service varchar(64) NOT NULL DEFAULT 'wordpress',
			event_type varchar(64) NOT NULL,
			severity varchar(16) NOT NULL,
			outcome varchar(32) NOT NULL,
			source_ip varchar(45) DEFAULT NULL,
			source_ip_version tinyint(3) unsigned DEFAULT NULL,
			username varchar(191) DEFAULT NULL,
			wordpress_user_id bigint(20) unsigned DEFAULT NULL,
			email_domain varchar(253) DEFAULT NULL,
			email_identifier char(64) DEFAULT NULL,
			user_agent varchar(512) DEFAULT NULL,
			request_method varchar(16) DEFAULT NULL,
			request_path varchar(1024) DEFAULT NULL,
			metadata_json longtext NULL,
			export_state varchar(20) NOT NULL DEFAULT 'queued',
			exported_at_utc datetime DEFAULT NULL,
			retry_count int(10) unsigned NOT NULL DEFAULT 0,
			last_export_error text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_uuid (event_uuid),
			KEY export_order (export_state,id),
			KEY batch_uuid (batch_uuid),
			KEY event_type (event_type),
			KEY source_ip (source_ip)
		) {$charset_collate};";

		dbDelta( $sql );

		$table_like  = $wpdb->esc_like( $table_name );
		$table_found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_like )
		);

		if ( $table_name !== $table_found ) {
			return false;
		}

		$columns = $wpdb->get_col(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name ),
			0
		);

		if ( ! is_array( $columns ) || array() !== array_diff( self::requiredColumns(), $columns ) ) {
			return false;
		}

		$id_column = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $table_name, 'id' )
		);

		if (
			! is_object( $id_column )
			|| ! isset( $id_column->Field, $id_column->Type, $id_column->Null, $id_column->Key, $id_column->Extra )
			|| 'id' !== $id_column->Field
			|| 1 !== preg_match( '/^bigint(?:\\(\\d+\\))? unsigned$/i', (string) $id_column->Type )
			|| 'NO' !== strtoupper( (string) $id_column->Null )
			|| 'PRI' !== strtoupper( (string) $id_column->Key )
			|| false === stripos( (string) $id_column->Extra, 'auto_increment' )
		) {
			return false;
		}

		$uuid_column = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $table_name, 'event_uuid' )
		);

		if (
			! is_object( $uuid_column )
			|| ! isset( $uuid_column->Field, $uuid_column->Type, $uuid_column->Null )
			|| 'event_uuid' !== $uuid_column->Field
			|| 1 !== preg_match( '/^char\\(36\\)$/i', (string) $uuid_column->Type )
			|| 'NO' !== strtoupper( (string) $uuid_column->Null )
		) {
			return false;
		}

		$event_uuid_indexes = $wpdb->get_results(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table_name, 'event_uuid' )
		);
		$has_unique_uuid    = 1 === count( is_array( $event_uuid_indexes ) ? $event_uuid_indexes : array() );

		if ( $has_unique_uuid ) {
			foreach ( $event_uuid_indexes as $index ) {
				if (
					! isset( $index->Column_name, $index->Non_unique, $index->Seq_in_index )
					|| ! property_exists( $index, 'Sub_part' )
					|| 'event_uuid' !== $index->Column_name
					|| 0 !== (int) $index->Non_unique
					|| 1 !== (int) $index->Seq_in_index
					|| null !== $index->Sub_part
				) {
					$has_unique_uuid = false;
					break;
				}
			}
		}

		if ( ! $has_unique_uuid ) {
			return false;
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );

		return self::VERSION === (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * @return array<int,string>
	 */
	public static function requiredColumns(): array {
		return array(
			'id',
			'event_uuid',
			'batch_uuid',
			'occurred_at_utc',
			'recorded_at_utc',
			'site_id',
			'site_url',
			'source_host',
			'service',
			'event_type',
			'severity',
			'outcome',
			'source_ip',
			'source_ip_version',
			'username',
			'wordpress_user_id',
			'email_domain',
			'email_identifier',
			'user_agent',
			'request_method',
			'request_path',
			'metadata_json',
			'export_state',
			'exported_at_utc',
			'retry_count',
			'last_export_error',
		);
	}
}
