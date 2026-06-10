<?php
/**
 * Redirect Migrator
 *
 * Export redirect rules dari plugin Redirection (John Godley) ke format
 * Yoast SEO Premium Redirect Manager, sekaligus CSV backup.
 *
 * Kategorisasi rules:
 *   - "Blogger legacy" : source URL format /YYYY/MM/slug.html
 *   - "Slug WP"        : slug WordPress lama → baru (/slug-lama/ → /slug-baru/)
 *   - "Lainnya"        : URL pattern lain
 *
 * @package Blogger_Recovery
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Blogger_Redirect_Migrator
 */
class Blogger_Redirect_Migrator {

	public function __construct() {
		add_action( 'wp_ajax_export_redirect_rules', array( $this, 'ajax_export_rules' ) );
		add_action( 'wp_ajax_import_to_yoast',       array( $this, 'ajax_import_to_yoast' ) );
	}

	/**
	 * AJAX handler: load semua redirect rules dari tabel Redirection plugin
	 * dan kategorisasikan.
	 */
	public function ajax_export_rules() {
		check_ajax_referer( 'redirect_migrate' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		global $wpdb;

		$table = $wpdb->prefix . 'redirection_items';

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_send_json_error( 'Tabel redirection_items tidak ditemukan. Pastikan plugin Redirection terinstall.' );
		}

		$rules = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT id, url AS source_url, action_data AS target_url, action_code AS http_code, regex, status, last_count
			 FROM {$table}
			 WHERE status = 'enabled'
			 ORDER BY id ASC"
		);

		if ( empty( $rules ) ) {
			wp_send_json_error( 'Tidak ada redirect rules aktif ditemukan.' );
		}

		$blogger_rules = array();
		$slug_rules    = array();
		$other_rules   = array();

		foreach ( $rules as $rule ) {
			if ( preg_match( '/\/\d{4}\/\d{2}\/.*\.html$/', $rule->source_url ) ) {
				$blogger_rules[] = $rule;
			} elseif ( preg_match( '/^\/[a-z0-9\-]+\/$/', $rule->source_url ) ) {
				$slug_rules[] = $rule;
			} else {
				$other_rules[] = $rule;
			}
		}

		wp_send_json_success( array(
			'rules'         => $rules,
			'blogger_rules' => $blogger_rules,
			'slug_rules'    => $slug_rules,
			'other_rules'   => $other_rules,
			'total'         => count( $rules ),
		) );
	}

	/**
	 * AJAX handler: import rules dari Redirection ke Yoast Premium.
	 * Rules yang sudah ada di Yoast akan di-skip (tidak overwrite).
	 */
	public function ajax_import_to_yoast() {
		check_ajax_referer( 'redirect_migrate' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		if ( ! class_exists( 'WPSEO_Redirect_Importer' ) || ! class_exists( 'WPSEO_Redirect' ) ) {
			wp_send_json_error( 'Yoast SEO Premium tidak aktif atau redirect manager tidak tersedia.' );
		}

		$rules_json = isset( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : '';
		$rules      = json_decode( $rules_json, true );

		if ( empty( $rules ) ) {
			wp_send_json_error( 'Tidak ada rules yang dikirim.' );
		}

		$redirects = array();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['source_url'] ) || ! isset( $rule['target_url'], $rule['http_code'] ) ) {
				continue;
			}

			$format      = ! empty( $rule['regex'] ) ? 'regex' : 'plain';
			$redirects[] = new WPSEO_Redirect(
				$rule['source_url'],
				$rule['target_url'],
				intval( $rule['http_code'] ),
				$format
			);
		}

		if ( empty( $redirects ) ) {
			wp_send_json_error( 'Tidak ada redirect valid yang dapat diimport.' );
		}

		$importer = new WPSEO_Redirect_Importer();
		$result   = $importer->import( $redirects );
		$imported = intval( $result['total_imported'] );
		$skipped  = intval( $result['total_redirects'] ) - $imported;

		wp_send_json_success( array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'message'  => "Import selesai: {$imported} rules ditambahkan ke Yoast, {$skipped} dilewati (sudah ada).",
		) );
	}
}
