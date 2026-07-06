<?php
/**
 * Database Backup
 *
 * Membuat dump SQL penuh ke file temporary, mengirimkannya sebagai gzip ke
 * browser administrator, lalu menghapus file tersebut dari server.
 *
 * @package Blogger_Recovery
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Blogger_Database_Backup
 */
class Blogger_Database_Backup {

	public function __construct() {
		add_action( 'admin_post_blogger_recovery_database_backup', array( $this, 'download_backup' ) );
	}

	/**
	 * Generate and download a full database backup.
	 */
	public function download_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Anda tidak memiliki izin untuk membuat backup database.', 'blogger-recovery' ),
				esc_html__( 'Database Backup Forbidden', 'blogger-recovery' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'blogger_recovery_database_backup' );

		if ( ! extension_loaded( 'zlib' ) || ! function_exists( 'gzopen' ) ) {
			wp_die( esc_html__( 'Ekstensi PHP zlib diperlukan untuk membuat backup terkompresi.', 'blogger-recovery' ) );
		}

		global $wpdb;

		if ( ! $wpdb->dbh instanceof mysqli ) {
			wp_die( esc_html__( 'Driver database mysqli diperlukan untuk membuat backup streaming.', 'blogger-recovery' ) );
		}

		@set_time_limit( 0 );
		ignore_user_abort( true );

		$temp_file = wp_tempnam( 'blogger-recovery-database.sql.gz' );
		if ( ! $temp_file ) {
			wp_die( esc_html__( 'Tidak dapat membuat file temporary untuk backup.', 'blogger-recovery' ) );
		}

		register_shutdown_function(
			static function () use ( $temp_file ) {
				if ( file_exists( $temp_file ) ) {
					wp_delete_file( $temp_file );
				}
			}
		);

		try {
			$this->write_dump( $temp_file );
			$this->stream_download( $temp_file );
		} catch ( Throwable $error ) {
			wp_delete_file( $temp_file );
			wp_die(
				esc_html( 'Backup database gagal: ' . $error->getMessage() ),
				esc_html__( 'Database Backup Error', 'blogger-recovery' ),
				array( 'response' => 500 )
			);
		}
	}

	/**
	 * Write the database dump as gzip.
	 *
	 * @param string $temp_file Temporary file path.
	 */
	private function write_dump( $temp_file ) {
		global $wpdb;

		$handle = gzopen( $temp_file, 'wb6' );
		if ( false === $handle ) {
			throw new RuntimeException( 'File temporary tidak dapat dibuka.' );
		}

		$objects = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( empty( $objects ) ) {
			gzclose( $handle );
			throw new RuntimeException( 'Tidak ada tabel database yang ditemukan.' );
		}

		$tables = array();
		$views  = array();

		foreach ( $objects as $object ) {
			if ( isset( $object[1] ) && 'VIEW' === strtoupper( $object[1] ) ) {
				$views[] = $object[0];
			} else {
				$tables[] = $object[0];
			}
		}

		$this->write(
			$handle,
			"-- Blogger Recovery Tools database backup\n"
			. '-- Site: ' . home_url() . "\n"
			. '-- Generated UTC: ' . gmdate( 'Y-m-d H:i:s' ) . "\n"
			. '-- WordPress: ' . get_bloginfo( 'version' ) . "\n\n"
			. "SET NAMES utf8mb4;\n"
			. "SET FOREIGN_KEY_CHECKS=0;\n"
			. "SET UNIQUE_CHECKS=0;\n"
			. "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n"
		);

		foreach ( $tables as $table ) {
			$this->write_table_schema( $handle, $table );
		}

		$this->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );

		try {
			foreach ( $tables as $table ) {
				$this->write_table_data( $handle, $table );
			}
			$this->query( 'COMMIT' );
		} catch ( Throwable $error ) {
			$this->query( 'ROLLBACK' );
			throw $error;
		}

		foreach ( $views as $view ) {
			$this->write_view_schema( $handle, $view );
		}

		$this->write(
			$handle,
			"\nSET UNIQUE_CHECKS=1;\n"
			. "SET FOREIGN_KEY_CHECKS=1;\n"
		);

		if ( ! gzclose( $handle ) ) {
			throw new RuntimeException( 'Backup gzip tidak dapat diselesaikan.' );
		}
	}

	/**
	 * Write one table schema.
	 *
	 * @param resource $handle Gzip handle.
	 * @param string   $table  Table name.
	 */
	private function write_table_schema( $handle, $table ) {
		global $wpdb;

		$row = $wpdb->get_row( 'SHOW CREATE TABLE ' . $this->quote_identifier( $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( empty( $row[1] ) ) {
			throw new RuntimeException( 'Schema tabel tidak dapat dibaca: ' . $table );
		}

		$this->write(
			$handle,
			"\n-- Table structure for {$table}\n"
			. 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $table ) . ";\n"
			. $row[1] . ";\n"
		);
	}

	/**
	 * Stream table rows into the dump without loading the full table in memory.
	 *
	 * @param resource $handle Gzip handle.
	 * @param string   $table  Table name.
	 */
	private function write_table_data( $handle, $table ) {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . $this->quote_identifier( $table );
		$result = mysqli_query( $wpdb->dbh, $sql, MYSQLI_USE_RESULT );

		if ( false === $result ) {
			throw new RuntimeException( 'Data tabel tidak dapat dibaca: ' . $table );
		}

		$fields  = mysqli_fetch_fields( $result );
		$columns = array();

		foreach ( $fields as $field ) {
			$columns[] = $this->quote_identifier( $field->name );
		}

		$prefix     = 'INSERT INTO ' . $this->quote_identifier( $table ) . ' (' . implode( ',', $columns ) . ") VALUES\n";
		$rows       = array();
		$chunk_size = 100;

		while ( $row = mysqli_fetch_row( $result ) ) {
			$values = array();
			foreach ( $row as $value ) {
				$values[] = $this->quote_value( $value );
			}
			$rows[] = '(' . implode( ',', $values ) . ')';

			if ( count( $rows ) >= $chunk_size ) {
				$this->write( $handle, $prefix . implode( ",\n", $rows ) . ";\n" );
				$rows = array();
			}
		}

		if ( ! empty( $rows ) ) {
			$this->write( $handle, $prefix . implode( ",\n", $rows ) . ";\n" );
		}

		mysqli_free_result( $result );
	}

	/**
	 * Write one view schema after table data.
	 *
	 * @param resource $handle Gzip handle.
	 * @param string   $view   View name.
	 */
	private function write_view_schema( $handle, $view ) {
		global $wpdb;

		$row = $wpdb->get_row( 'SHOW CREATE VIEW ' . $this->quote_identifier( $view ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( empty( $row[1] ) ) {
			throw new RuntimeException( 'Schema view tidak dapat dibaca: ' . $view );
		}

		$this->write(
			$handle,
			"\n-- View structure for {$view}\n"
			. 'DROP VIEW IF EXISTS ' . $this->quote_identifier( $view ) . ";\n"
			. $row[1] . ";\n"
		);
	}

	/**
	 * Run a mysqli query and fail explicitly.
	 *
	 * @param string $sql Query.
	 */
	private function query( $sql ) {
		global $wpdb;

		if ( false === mysqli_query( $wpdb->dbh, $sql ) ) {
			throw new RuntimeException( 'Database query gagal saat membuat snapshot.' );
		}
	}

	/**
	 * Quote a database identifier.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	private function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Quote a database value for a SQL dump.
	 *
	 * @param string|null $value Value.
	 * @return string
	 */
	private function quote_value( $value ) {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}

		return "'" . mysqli_real_escape_string( $wpdb->dbh, $value ) . "'";
	}

	/**
	 * Write data to the gzip stream.
	 *
	 * @param resource $handle Gzip handle.
	 * @param string   $data   Data.
	 */
	private function write( $handle, $data ) {
		if ( false === gzwrite( $handle, $data ) ) {
			throw new RuntimeException( 'Gagal menulis data backup.' );
		}
	}

	/**
	 * Stream the generated file and remove it immediately.
	 *
	 * @param string $temp_file Temporary file path.
	 */
	private function stream_download( $temp_file ) {
		$site     = sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) );
		$filename = $site . '-database-' . gmdate( 'Y-m-d-His' ) . '.sql.gz';
		$size     = filesize( $temp_file );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( false !== $size ) {
			header( 'Content-Length: ' . $size );
		}

		$handle = fopen( $temp_file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			throw new RuntimeException( 'File backup tidak dapat dibaca untuk download.' );
		}

		while ( ! feof( $handle ) ) {
			echo fread( $handle, 1024 * 1024 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.AlternativeFunctions
			flush();
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $temp_file );
		exit;
	}
}
