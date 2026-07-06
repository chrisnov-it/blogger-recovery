<?php
/**
 * Paragraph Normalizer
 *
 * Converts deterministic Blogger div-based paragraphs into semantic paragraphs.
 *
 * @package Blogger_Recovery
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Blogger_Paragraph_Normalizer
 */
class Blogger_Paragraph_Normalizer {

	/** Maximum number of posts accepted by one Apply request. */
	const MAX_BATCH_SIZE = 20;

	public function __construct() {
		add_action( 'wp_ajax_blogger_scan_paragraphs', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_blogger_preview_paragraphs', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_blogger_normalize_paragraphs', array( $this, 'ajax_normalize' ) );
	}

	/**
	 * Find published posts that contain deterministic Blogger paragraph patterns.
	 */
	public function ajax_scan() {
		check_ajax_referer( 'normalize_paragraphs' );
		$this->authorize();

		$posts = get_posts( array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		) );

		$rows = array();
		foreach ( $posts as $post ) {
			$analysis = $this->analyze_content( $post->post_content );
			if ( empty( $analysis['candidate'] ) ) {
				continue;
			}

			$rows[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
				'view_url'   => get_permalink( $post->ID ),
				'divs'       => $analysis['divs'],
				'paragraphs' => $analysis['paragraphs'],
				'spacers'    => $analysis['spacers'],
				'ambiguous'  => $analysis['ambiguous'],
				'eligible'   => $analysis['eligible'],
				'reason'     => $analysis['reason'],
			);
		}

		wp_send_json_success( array(
			'rows'  => $rows,
			'total' => count( $rows ),
		) );
	}

	/**
	 * Preview one post without writing to the database.
	 */
	public function ajax_preview() {
		check_ajax_referer( 'normalize_paragraphs' );
		$this->authorize();

		$post = $this->get_requested_post();
		$result = $this->normalize_content( $post->post_content );

		wp_send_json_success( array(
			'id'      => $post->ID,
			'title'   => $post->post_title,
			'before'  => $post->post_content,
			'after'   => $result['content'],
			'changed' => $result['changed'],
			'safe'    => $result['safe'],
			'stats'   => $result['stats'],
			'reason'  => $result['reason'],
		) );
	}

	/**
	 * Dry-run or normalize a selected list of post IDs.
	 */
	public function ajax_normalize() {
		check_ajax_referer( 'normalize_paragraphs' );
		$this->authorize();

		$dry_run = ! isset( $_POST['dry_run'] ) || rest_sanitize_boolean( wp_unslash( $_POST['dry_run'] ) );
		$ids     = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : array();
		$ids     = array_values( array_unique( array_filter( $ids ) ) );

		if ( empty( $ids ) ) {
			wp_send_json_error( 'Pilih minimal satu Post ID.' );
		}
		if ( count( $ids ) > self::MAX_BATCH_SIZE ) {
			wp_send_json_error( 'Maksimal ' . self::MAX_BATCH_SIZE . ' artikel per eksekusi.' );
		}
		if ( ! $dry_run && ( ! isset( $_POST['apply_confirm'] ) || 'NORMALIZE' !== wp_unslash( $_POST['apply_confirm'] ) ) ) {
			wp_send_json_error( 'Apply ditolak: ketik NORMALIZE untuk mengonfirmasi.' );
		}

		$summary = array(
			'selected'   => count( $ids ),
			'changed'    => 0,
			'unchanged'  => 0,
			'skipped'    => 0,
			'paragraphs' => 0,
			'spacers'    => 0,
			'wrappers'   => 0,
		);
		$logs = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
				++$summary['skipped'];
				$logs[] = "Post #{$id}: dilewati; artikel publish tidak ditemukan.";
				continue;
			}

			$result = $this->normalize_content( $post->post_content );
			if ( ! $result['safe'] ) {
				++$summary['skipped'];
				$logs[] = "Post #{$id}: dilewati; {$result['reason']}";
				continue;
			}
			if ( ! $result['changed'] ) {
				++$summary['unchanged'];
				$logs[] = "Post #{$id}: tidak ada pola paragraf yang aman untuk diubah.";
				continue;
			}

			++$summary['changed'];
			foreach ( array( 'paragraphs', 'spacers', 'wrappers' ) as $key ) {
				$summary[ $key ] += $result['stats'][ $key ];
			}

			if ( $dry_run ) {
				$logs[] = "Post #{$id}: [DRY-RUN] {$result['stats']['paragraphs']} paragraf, {$result['stats']['spacers']} spacer, {$result['stats']['wrappers']} wrapper.";
				continue;
			}

			$updated = wp_update_post( array(
				'ID'           => $id,
				'post_content' => $result['content'],
			), true );

			if ( is_wp_error( $updated ) ) {
				--$summary['changed'];
				++$summary['skipped'];
				$logs[] = "Post #{$id}: gagal ditulis; " . $updated->get_error_message();
			} else {
				$logs[] = "Post #{$id}: paragraph normalization diterapkan.";
			}
		}

		wp_send_json_success( array(
			'dry_run' => $dry_run,
			'summary' => $summary,
			'logs'    => $logs,
		) );
	}

	/**
	 * Analyze whether content contains safe Blogger paragraph candidates.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	public function analyze_content( $content ) {
		$analysis = array(
			'candidate'  => false,
			'eligible'   => true,
			'reason'     => '',
			'divs'       => preg_match_all( '/<div\b/i', $content ),
			'paragraphs' => 0,
			'spacers'    => 0,
			'ambiguous'  => 0,
		);

		if ( false !== strpos( $content, '<!-- wp:' ) ) {
			$analysis['eligible'] = false;
			$analysis['reason']   = 'Gutenberg block markup terdeteksi.';
			return $analysis;
		}
		if ( ! $analysis['divs'] ) {
			return $analysis;
		}

		$document = $this->load_fragment( $content );
		if ( ! $document ) {
			$analysis['eligible'] = false;
			$analysis['reason']   = 'HTML tidak dapat diparse dengan aman.';
			return $analysis;
		}

		$xpath = new DOMXPath( $document );
		foreach ( $xpath->query( '//*[@id="br-normalizer-root"]//div' ) as $div ) {
			if ( $this->is_spacer_div( $div ) ) {
				++$analysis['spacers'];
			} elseif ( $this->is_paragraph_div( $div ) ) {
				++$analysis['paragraphs'];
			} elseif ( $this->is_text_leaf_div( $div ) ) {
				++$analysis['ambiguous'];
			}
		}

		$analysis['candidate'] = ( $analysis['paragraphs'] + $analysis['spacers'] ) > 0;
		return $analysis;
	}

	/**
	 * Normalize one HTML fragment and verify content preservation.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	public function normalize_content( $content ) {
		$analysis = $this->analyze_content( $content );
		$result   = array(
			'content' => $content,
			'changed' => false,
			'safe'    => $analysis['eligible'],
			'reason'  => $analysis['reason'],
			'stats'   => array( 'paragraphs' => 0, 'spacers' => 0, 'wrappers' => 0 ),
		);

		if ( ! $analysis['eligible'] || ! $analysis['candidate'] ) {
			return $result;
		}

		$before_signature = $this->content_signature( $content );
		$document         = $this->load_fragment( $content );
		$root             = $document->getElementById( 'br-normalizer-root' );

		do {
			$changed = false;
			$nodes   = array();
			foreach ( $root->getElementsByTagName( 'div' ) as $node ) {
				$nodes[] = $node;
			}

			for ( $index = count( $nodes ) - 1; $index >= 0; --$index ) {
				$div = $nodes[ $index ];
				if ( ! $div->parentNode ) {
					continue;
				}
				if ( $this->is_spacer_div( $div ) ) {
					$div->parentNode->removeChild( $div );
					++$result['stats']['spacers'];
					$changed = true;
					continue;
				}
				if ( $this->is_paragraph_div( $div ) ) {
					$paragraph = $document->createElement( 'p' );
					while ( $div->firstChild ) {
						$paragraph->appendChild( $div->firstChild );
					}
					$div->parentNode->replaceChild( $paragraph, $div );
					++$result['stats']['paragraphs'];
					$changed = true;
					continue;
				}
				if ( $this->is_safe_wrapper_div( $div ) ) {
					$parent = $div->parentNode;
					while ( $div->firstChild ) {
						$parent->insertBefore( $div->firstChild, $div );
					}
					$parent->removeChild( $div );
					++$result['stats']['wrappers'];
					$changed = true;
				}
			}
		} while ( $changed );

		$normalized = $this->save_fragment( $document, $root );
		if ( $before_signature !== $this->content_signature( $normalized ) ) {
			$result['safe']   = false;
			$result['reason'] = 'Validasi gagal: teks, link, gambar, atau embed berubah.';
			return $result;
		}

		$result['content'] = trim( $normalized );
		$result['changed'] = $result['content'] !== trim( $content );
		return $result;
	}

	/**
	 * Parse an HTML fragment inside a stable root.
	 */
	private function load_fragment( $content ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return false;
		}

		$document = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML(
			'<?xml encoding="UTF-8"><div id="br-normalizer-root">' . $content . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $document : false;
	}

	/**
	 * Serialize only the children of the artificial root.
	 */
	private function save_fragment( DOMDocument $document, DOMElement $root ) {
		$html = '';
		foreach ( $root->childNodes as $child ) {
			$html .= $document->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * A safe paragraph is a leaf div with text and no meaningful attributes.
	 */
	private function is_paragraph_div( DOMElement $div ) {
		if ( ! $this->has_safe_attributes( $div ) || ! $this->is_text_leaf_div( $div ) ) {
			return false;
		}

		if ( $div->getElementsByTagName( 'br' )->length > 1 ) {
			return false;
		}

		foreach ( array( 'img', 'iframe', 'video', 'audio', 'script', 'style', 'form', 'input' ) as $tag ) {
			if ( $div->getElementsByTagName( $tag )->length ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Detect divs containing text but no nested block-level elements.
	 */
	private function is_text_leaf_div( DOMElement $div ) {
		$text = trim( str_replace( "\xc2\xa0", ' ', $div->textContent ) );
		if ( '' === $text ) {
			return false;
		}

		foreach ( $div->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			if ( in_array( strtolower( $child->nodeName ), array( 'div', 'p', 'figure', 'table', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'pre', 'iframe' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Detect spacing divs that contain only whitespace and br elements.
	 */
	private function is_spacer_div( DOMElement $div ) {
		if ( ! $this->has_spacing_attributes( $div ) ) {
			return false;
		}
		if ( '' !== trim( str_replace( "\xc2\xa0", ' ', $div->textContent ) ) ) {
			return false;
		}

		return $this->contains_only_spacing_nodes( $div );
	}

	/**
	 * Allow whitespace, line breaks, and inline formatting without actual text.
	 */
	private function contains_only_spacing_nodes( DOMNode $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' !== trim( str_replace( "\xc2\xa0", ' ', $child->nodeValue ) ) ) {
					return false;
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			if ( 'br' === strtolower( $child->nodeName ) ) {
				continue;
			}
			if ( ! in_array( strtolower( $child->nodeName ), array( 'span', 'i', 'em', 'b', 'strong' ), true ) ) {
				return false;
			}
			if ( ! $this->contains_only_spacing_nodes( $child ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Unwrap divs that only group normalized block elements and carry no layout.
	 */
	private function is_safe_wrapper_div( DOMElement $div ) {
		if ( ! $this->has_safe_attributes( $div ) || ! $div->hasChildNodes() ) {
			return false;
		}

		$has_block = false;
		foreach ( $div->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' !== trim( $child->nodeValue ) ) {
					return false;
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			if ( ! in_array( strtolower( $child->nodeName ), array( 'p', 'div', 'figure', 'table', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol' ), true ) ) {
				return false;
			}
			$has_block = true;
		}
		return $has_block;
	}

	/**
	 * Only empty attributes or redundant left/justify alignment are removable.
	 */
	private function has_safe_attributes( DOMElement $div ) {
		if ( ! $div->hasAttributes() ) {
			return true;
		}
		if ( 1 !== $div->attributes->length || ! $div->hasAttribute( 'style' ) ) {
			return false;
		}

		$style = strtolower( preg_replace( '/\s+/', '', $div->getAttribute( 'style' ) ) );
		return in_array( $style, array( 'text-align:left;', 'text-align:left', 'text-align:justify;', 'text-align:justify' ), true );
	}

	/**
	 * Empty alignment divs have no layout value after their spacer is removed.
	 */
	private function has_spacing_attributes( DOMElement $div ) {
		if ( ! $div->hasAttributes() ) {
			return true;
		}
		if ( 1 !== $div->attributes->length || ! $div->hasAttribute( 'style' ) ) {
			return false;
		}

		$style = strtolower( preg_replace( '/\s+/', '', $div->getAttribute( 'style' ) ) );
		return (bool) preg_match( '/^text-align:(?:left|right|center|justify);?$/', $style );
	}

	/**
	 * Verify text and meaningful linked/embedded resources are unchanged.
	 */
	private function content_signature( $content ) {
		$document = $this->load_fragment( $content );
		if ( ! $document ) {
			return '';
		}

		$root      = $document->getElementById( 'br-normalizer-root' );
		$resources = array();
		foreach ( array( 'a' => 'href', 'img' => 'src', 'iframe' => 'src', 'video' => 'src', 'audio' => 'src' ) as $tag => $attribute ) {
			foreach ( $root->getElementsByTagName( $tag ) as $element ) {
				$resources[] = $tag . ':' . $element->getAttribute( $attribute );
			}
		}

		$text = preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', $root->textContent ) );
		return hash( 'sha256', trim( $text ) . "\n" . implode( "\n", $resources ) );
	}

	private function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
	}

	private function get_requested_post() {
		$id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post = get_post( $id );
		if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			wp_send_json_error( 'Artikel publish tidak ditemukan.' );
		}
		return $post;
	}
}
