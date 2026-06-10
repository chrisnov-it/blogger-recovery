<?php
/**
 * HTML Cleanup
 *
 * Membersihkan sisa HTML Blogger dari konten post.
 *
 * Urutan eksekusi KRITIS:
 *   1. Fix malformed quotes  ← HARUS PERTAMA agar regex AdSense bisa match
 *   2. Remove AdSense blocks
 *   3. Fix internal Blogger links (/YYYY/MM/slug.html → WP permalink)
 *   4. Convert Blogger caption tables → <figure>
 *   5. Cleanup lainnya (tr_bq, align, border=0, table markup, fonts, empty elements)
 *
 * @package Blogger_Recovery
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Blogger_HTML_Cleanup
 */
class Blogger_HTML_Cleanup {

	/** @var int Ukuran batch per request AJAX */
	private $batch_size = 10;

	/** @var array Statistik per-run */
	private $stats = array(
		'adsense_removed'    => 0,
		'html_links_fixed'   => 0,
		'captions_converted' => 0,
		'tables_cleaned'     => 0,
		'quotes_fixed'       => 0,
	);

	public function __construct() {
		add_action( 'wp_ajax_cleanup_blogger_html', array( $this, 'ajax_cleanup' ) );
	}

	/**
	 * AJAX handler: proses batch post.
	 */
	public function ajax_cleanup() {
		check_ajax_referer( 'cleanup_html' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$offset  = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$options = isset( $_POST['options'] ) ? (array) $_POST['options'] : array();
		$logs    = '';

		$posts = get_posts( array(
			'numberposts' => $this->batch_size,
			'offset'      => $offset,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'orderby'     => 'ID',
			'order'       => 'ASC',
		) );

		foreach ( $posts as $post ) {
			$content = $post->post_content;
			$before  = $content;

			// Eksekusi sesuai urutan kritis
			$content = $this->step_fix_malformed_quotes( $content, $options, $post, $logs );
			$content = $this->step_remove_adsense( $content, $options, $post, $logs );
			$content = $this->step_fix_html_links( $content, $options, $post, $logs );
			$content = $this->step_convert_captions( $content, $options, $post, $logs );
			$content = $this->step_misc_cleanup( $content, $options, $post, $logs );

			if ( $content !== $before ) {
				wp_update_post( array(
					'ID'           => $post->ID,
					'post_content' => $content,
				) );
			}
		}

		$total = wp_count_posts()->publish;

		wp_send_json_success( array(
			'logs'            => nl2br( esc_html( $logs ) ),
			'total_processed' => $offset + count( $posts ),
			'total_posts'     => $total,
			'batch_size'      => $this->batch_size,
			'has_more'        => ( $offset + $this->batch_size ) < $total,
			'stats'           => $this->stats,
		) );
	}

	// =========================================================================
	// STEP 1 — Fix malformed quotes
	// HARUS dijalankan sebelum step_remove_adsense agar regex <td> bisa match.
	// Contoh real ranalino.co: width=""180?" di dalam <td> AdSense Blogger.
	// =========================================================================

	private function step_fix_malformed_quotes( $content, $options, $post, &$logs ) {
		if ( empty( $options['cleanup_malformed_quotes'] ) ) {
			return $content;
		}

		$before  = $content;
		$content = preg_replace( '/(\w+)=["\']"([^"\']*)\?"?["\']/', '$1="$2"', $content ); // width=""180?"
		$content = preg_replace( '/(\w+)=["\']"([^"\']*)"["\']/', '$1="$2"', $content );    // align=""left""
		$content = preg_replace( '/(\w+)=["\']([^"\']*)\?["\']/', '$1="$2"', $content );    // value="something?"

		if ( $content !== $before ) {
			$logs .= "Post #{$post->ID}: Fixed malformed HTML quotes.\n";
			++$this->stats['quotes_fixed'];
		}
		return $content;
	}

	// =========================================================================
	// STEP 2 — Remove AdSense blocks
	// Pattern dari kondisi real ranalino.co: nested di dalam <table><tbody><tr><td>
	// =========================================================================

	private function step_remove_adsense( $content, $options, $post, &$logs ) {
		if ( empty( $options['cleanup_adsense'] ) ) {
			return $content;
		}

		$before = $content;

		// Pattern A: <table>...<ins class="adsbygoogle">...(adsbygoogle).push({})...</table>
		$content = preg_replace(
			'/<table[^>]*>\s*<tbody[^>]*>\s*<tr[^>]*>\s*<td[^>]*>\s*<ins\s+class=["\']adsbygoogle["\'][^>]*>\s*<\/ins>\s*\(adsbygoogle\s*=\s*window\.adsbygoogle[^)]*\)\.push\(\{\}\);?\s*<\/td>\s*<\/tr>\s*<\/tbody>\s*<\/table>/is',
			'',
			$content
		);

		// Pattern B: div-wrapped AdSense
		$content = preg_replace(
			'/<div[^>]*>(?:(?!<div\b|<\/div>)[\s\S])*?<ins\s+class=["\']adsbygoogle["\'][^>]*>(?:(?!<div\b|<\/div>)[\s\S])*?<\/ins>(?:(?!<div\b|<\/div>)[\s\S])*?\(adsbygoogle(?:(?!<div\b|<\/div>)[\s\S])*?\)\.push\(\{\}\);?(?:(?!<div\b|<\/div>)[\s\S])*?<\/div>/is',
			'',
			$content
		);

		// Pattern C: <script> berisi AdSense
		$content = preg_replace(
			'/<script[^>]*>[\s\S]*?adsbygoogle[\s\S]*?<\/script>/is',
			'',
			$content
		);

		// Pattern D: sisa <ins class="adsbygoogle"> yang mungkin tertinggal
		$content = preg_replace(
			'/<ins\s+class=["\']adsbygoogle["\'][^>]*>[\s\S]*?<\/ins>/is',
			'',
			$content
		);

		// Pattern E: sisa JS push tanpa wrapper
		$content = preg_replace(
			'/\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\.push\(\{\}\);?/i',
			'',
			$content
		);

		if ( $content !== $before ) {
			$logs .= "Post #{$post->ID}: Removed AdSense block(s).\n";
			++$this->stats['adsense_removed'];
		}
		return $content;
	}

	// =========================================================================
	// STEP 3 — Fix internal link format Blogger /YYYY/MM/slug.html
	// Lookup slug di DB → replace ke WordPress permalink.
	// Jika tidak ketemu, biarkan (akan ditangani redirect).
	// =========================================================================

	private function step_fix_html_links( $content, $options, $post, &$logs ) {
		if ( empty( $options['cleanup_html_links'] ) ) {
			return $content;
		}

		$before  = $content;
		$fixed   = 0;
		$content = preg_replace_callback(
			'/href=["\']([^"\']*\/\d{4}\/\d{2}\/([^"\']+)\.html)["\']/',
			function( $matches ) use ( &$logs, &$fixed, $post ) {
				$old_url = $matches[1];
				$slug    = sanitize_title( basename( $matches[2] ) );

				$found = get_posts( array(
					'name'        => $slug,
					'post_type'   => 'post',
					'post_status' => 'publish',
					'numberposts' => 1,
				) );

				if ( ! empty( $found ) ) {
					$new_url = get_permalink( $found[0]->ID );
					$logs   .= "Post #{$post->ID}: Link fixed: {$old_url} → {$new_url}\n";
					$fixed++;
					return 'href="' . esc_url( $new_url ) . '"';
				}

				// Tidak ketemu di DB — biarkan
				return 'href="' . esc_url( $old_url ) . '"';
			},
			$content
		);

		if ( $content !== $before ) {
			++$this->stats['html_links_fixed'];
		}
		return $content;
	}

	// =========================================================================
	// STEP 4 — Convert Blogger caption table → <figure><figcaption>
	// =========================================================================

	private function step_convert_captions( $content, $options, $post, &$logs ) {
		if ( empty( $options['cleanup_caption_tables'] ) ) {
			return $content;
		}

		$before  = $content;
		$content = preg_replace_callback(
			'/<table[^>]*class=["\']tr-caption-container["\'][^>]*>[\s\S]*?<img([^>]*)>[\s\S]*?<td[^>]*class=["\']tr-caption["\'][^>]*>([\s\S]*?)<\/td>[\s\S]*?<\/table>/is',
			function( $matches ) use ( &$logs, $post ) {
				$img_attrs = $matches[1];
				$caption   = trim( strip_tags( $matches[2] ) );
				$logs     .= "Post #{$post->ID}: Converted Blogger caption table → <figure>.\n";
				return '<figure><img' . $img_attrs . '>'
					. ( $caption ? '<figcaption>' . esc_html( $caption ) . '</figcaption>' : '' )
					. '</figure>';
			},
			$content
		);

		if ( $content !== $before ) {
			++$this->stats['captions_converted'];
		}
		return $content;
	}

	// =========================================================================
	// STEP 5 — Misc cleanup (urutan tidak kritis)
	// =========================================================================

	private function step_misc_cleanup( $content, $options, $post, &$logs ) {
		$before = $content;

		if ( ! empty( $options['cleanup_tr_bq'] ) ) {
			$content = preg_replace( '/<blockquote\s+class=["\']tr_bq["\']>/i', '<blockquote>', $content );
		}

		if ( ! empty( $options['cleanup_align'] ) ) {
			$content = preg_replace(
				'/<(div|table|p)\s+align=["\']?center["\']?([^>]*)>/i',
				'<$1 style="text-align:center;" $2>',
				$content
			);
		}

		if ( ! empty( $options['cleanup_img_border'] ) ) {
			$content = preg_replace( '/\s+border=["\']?0["\']?/i', '', $content );
		}

		if ( ! empty( $options['cleanup_tables'] ) ) {
			$prev    = $content;
			$content = preg_replace( '/\s+cellpadding=["\']?[^"\'>\s]+["\']?/i', '', $content );
			$content = preg_replace( '/\s+cellspacing=["\']?[^"\'>\s]+["\']?/i', '', $content );
			if ( $content !== $prev ) {
				++$this->stats['tables_cleaned'];
			}
		}

		if ( ! empty( $options['cleanup_blogger_fonts'] ) ) {
			$content = preg_replace( '/\s*font-family:\s*Trebuchet[^;"\']*;?/i', '', $content );
		}

		if ( ! empty( $options['cleanup_empty_elements'] ) ) {
			$content = preg_replace( '/<br\s*\/?>(\s*<br\s*\/?>)+/i', '<br>', $content );
			$content = preg_replace( '/<p[^>]*>\s*(<br\s*\/?>)?\s*<\/p>/i', '', $content );
			$content = preg_replace( '/<div[^>]*>\s*<\/div>/i', '', $content );
		}

		if ( $content !== $before ) {
			$logs .= "Post #{$post->ID}: Misc cleanup applied.\n";
		}
		return $content;
	}
}
