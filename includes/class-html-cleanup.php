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
		'links_unresolved'   => 0,
	);

	/** @var array|null Active non-regex Redirection rules, keyed by source path. */
	private $redirect_map = null;

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
		$dry_run = ! isset( $_POST['dry_run'] ) || rest_sanitize_boolean( wp_unslash( $_POST['dry_run'] ) );
		$logs    = '';

		if ( ! $dry_run && ( ! isset( $_POST['apply_confirm'] ) || 'APPLY' !== wp_unslash( $_POST['apply_confirm'] ) ) ) {
			wp_send_json_error( 'Apply ditolak: konfirmasi eksplisit tidak valid.' );
		}

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

			if ( $content !== $before && ! $dry_run ) {
				wp_update_post( array(
					'ID'           => $post->ID,
					'post_content' => $content,
				) );
			} elseif ( $content !== $before ) {
				$logs .= "Post #{$post->ID}: [DRY-RUN] Changes previewed only; database was not updated.\n";
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
			'dry_run'         => $dry_run,
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

		$before = $content;
		$content = preg_replace_callback(
			'/href=["\']([^"\']*\/\d{4}\/\d{2}\/([^"\']+)\.html)["\']/',
			function( $matches ) use ( &$logs, $post ) {
				$old_url = $matches[1];
				$slug    = sanitize_title( basename( $matches[2] ) );

				if ( ! $this->is_internal_url( $old_url ) ) {
					return $matches[0];
				}

				$new_url = $this->resolve_internal_link( $old_url, $slug );

				if ( $new_url ) {
					$logs   .= "Post #{$post->ID}: Link fixed: {$old_url} → {$new_url}\n";
					return 'href="' . esc_url( $new_url ) . '"';
				}

				$logs .= "Post #{$post->ID}: Link unresolved, left unchanged: {$old_url}\n";
				++$this->stats['links_unresolved'];
				return $matches[0];
			},
			$content
		);

		if ( $content !== $before ) {
			++$this->stats['html_links_fixed'];
		}
		return $content;
	}

	/**
	 * Resolve Blogger legacy links through the current post slug first, then
	 * through active rules from the Redirection plugin.
	 *
	 * This also covers manually authored blocks such as "BACA JUGA" because
	 * resolution is based on the anchor href, not surrounding label text.
	 *
	 * @param string $old_url Original href value.
	 * @param string $slug    Slug parsed from the Blogger URL.
	 * @return string|false
	 */
	private function resolve_internal_link( $old_url, $slug ) {
		$found = get_posts( array(
			'name'        => $slug,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'numberposts' => 1,
		) );

		if ( ! empty( $found ) ) {
			return get_permalink( $found[0]->ID );
		}

		$path = wp_parse_url( $old_url, PHP_URL_PATH );
		if ( ! $path ) {
			return false;
		}

		$redirect_map = $this->get_redirect_map();
		if ( empty( $redirect_map[ $path ] ) ) {
			return false;
		}

		$target = $redirect_map[ $path ];
		if ( wp_parse_url( $target, PHP_URL_HOST ) ) {
			return $target;
		}

		return home_url( '/' . ltrim( $target, '/' ) );
	}

	/**
	 * Determine whether a legacy URL belongs to this WordPress site.
	 *
	 * @param string $url URL from post content.
	 * @return bool
	 */
	private function is_internal_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return ! $host || $host === wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Load active plain redirects once per AJAX request.
	 *
	 * @return array
	 */
	private function get_redirect_map() {
		if ( null !== $this->redirect_map ) {
			return $this->redirect_map;
		}

		global $wpdb;

		$this->redirect_map = array();
		$table              = $wpdb->prefix . 'redirection_items';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $this->redirect_map;
		}

		$rules = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT url, action_data
			 FROM {$table}
			 WHERE status = 'enabled'
			   AND regex = 0
			 ORDER BY id ASC"
		);

		foreach ( $rules as $rule ) {
			$this->redirect_map[ $rule->url ] = $rule->action_data;
		}

		return $this->redirect_map;
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
