<?php
/**
 * Issues Detector
 *
 * Scan semua post untuk menemukan sisa masalah migrasi dari Blogger:
 * - Gambar dengan href wrapper ke blogspot (src sudah lokal)
 * - Gambar src yang masih ke blogspot
 * - Script AdSense tertanam di konten
 * - Internal link format Blogger (/YYYY/MM/slug.html)
 * - Pola HTML lama (tr_bq, border=0, Trebuchet, caption table)
 *
 * @package Blogger_Recovery
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Blogger_Image_Detector
 */
class Blogger_Image_Detector {

	/** @var int Ukuran batch per request AJAX */
	private $batch_size = 10;

	public function __construct() {
		add_action( 'wp_ajax_detect_blogger_issues', array( $this, 'ajax_detect_issues' ) );
	}

	/**
	 * AJAX handler: scan batch post dan kembalikan temuan.
	 */
	public function ajax_detect_issues() {
		check_ajax_referer( 'detect_issues' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

		$posts = get_posts( array(
			'numberposts' => $this->batch_size,
			'offset'      => $offset,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'orderby'     => 'ID',
			'order'       => 'ASC',
		) );

		$issues = array(
			'blogger_href_wrapper' => array(),
			'full_blogger_images'  => array(),
			'adsense_blocks'       => array(),
			'blogger_html_links'   => array(),
			'old_html'             => array(),
		);

		foreach ( $posts as $post ) {
			$this->scan_post( $post, $issues );
		}

		$total = wp_count_posts()->publish;

		wp_send_json_success( array(
			'issues'        => $issues,
			'total_scanned' => $offset + count( $posts ),
			'total_posts'   => $total,
			'batch_size'    => $this->batch_size,
			'has_more'      => ( $offset + $this->batch_size ) < $total,
		) );
	}

	/**
	 * Scan satu post dan isi array $issues (by reference).
	 *
	 * @param WP_Post $post
	 * @param array   $issues
	 */
	private function scan_post( $post, &$issues ) {
		$content = $post->post_content;
		$id      = $post->ID;

		// Kondisi A: href ke Blogger, src sudah lokal
		if ( preg_match_all(
			'/<a\s[^>]*href=["\']https?:\/\/\d+\.bp\.blogspot\.com\/[^"\']+["\'][^>]*>\s*<img\s[^>]*src=["\']([^"\']+)["\'][^>]*>\s*<\/a>/i',
			$content, $matches, PREG_SET_ORDER
		) ) {
			foreach ( $matches as $m ) {
				$issues['blogger_href_wrapper'][] = array( 'post_id' => $id, 'local_src' => $m[1] );
			}
		}

		// Kondisi B: src masih ke Blogger
		if ( preg_match_all(
			'/<img[^>]*src=["\'](https?:\/\/\d+\.bp\.blogspot\.com\/[^"\']+)["\'][^>]*>/i',
			$content, $matches
		) ) {
			foreach ( $matches[1] as $url ) {
				$issues['full_blogger_images'][] = array( 'post_id' => $id, 'url' => $url );
			}
		}

		// AdSense tertanam
		$adsense_count = substr_count( $content, 'adsbygoogle' );
		if ( strpos( $content, 'googlesyndication' ) !== false ) {
			$adsense_count++;
		}
		if ( $adsense_count > 0 ) {
			$issues['adsense_blocks'][] = array( 'post_id' => $id, 'count' => $adsense_count );
		}

		// Internal link format /YYYY/MM/slug.html
		if ( preg_match_all(
			'/href=["\'][^"\']*\/\d{4}\/\d{2}\/[^"\']+\.html["\']/i',
			$content, $matches
		) ) {
			$issues['blogger_html_links'][] = array( 'post_id' => $id, 'count' => count( $matches[0] ) );
		}

		// Pola HTML lama
		$old_html_checks = array(
			'class="tr_bq"'                   => 'tr_bq blockquote',
			'border="0"'                       => 'img border=0',
		);
		foreach ( $old_html_checks as $needle => $label ) {
			if ( strpos( $content, $needle ) !== false ) {
				$issues['old_html'][] = array( 'post_id' => $id, 'issue' => $label );
			}
		}
		if ( preg_match( '/font-family:\s*Trebuchet/i', $content ) ) {
			$issues['old_html'][] = array( 'post_id' => $id, 'issue' => 'Trebuchet font' );
		}
		if ( preg_match( '/<table[^>]*class=["\']tr-caption-container["\']/i', $content ) ) {
			$issues['old_html'][] = array( 'post_id' => $id, 'issue' => 'Blogger caption table' );
		}
	}
}
