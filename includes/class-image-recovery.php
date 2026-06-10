<?php
/**
 * Image Recovery
 *
 * Menangani dua kondisi gambar Blogger di ranalino.co:
 *
 * Kondisi A (utama): <a href="blogspot..."><img src="lokal..."></a>
 *   → Strip wrapper <a>, gambar lokal tampil clean tanpa redirect.
 *
 * Kondisi B: <img src="blogspot...">
 *   → Download dari server Google, import ke Media Library, update src.
 *
 * @package Blogger_Recovery
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Blogger_Image_Recovery
 */
class Blogger_Image_Recovery {

	/** @var int Ukuran batch per request AJAX */
	private $batch_size = 10;

	/** @var array Info direktori upload WordPress */
	private $upload_dir;

	public function __construct() {
		$this->upload_dir = wp_upload_dir();
		add_action( 'wp_ajax_blogger_recover_images', array( $this, 'ajax_recover_images' ) );
	}

	/**
	 * AJAX handler: proses batch post, strip Blogger href wrapper dan/atau
	 * download gambar yang masih di Blogger.
	 */
	public function ajax_recover_images() {
		check_ajax_referer( 'blogger_recover' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$logs   = '';

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
			$updated = false;

			// --- Kondisi A: strip Blogger href wrapper ---
			$new_content = preg_replace_callback(
				'/<a\s[^>]*href=["\']https?:\/\/\d+\.bp\.blogspot\.com\/[^"\']+["\'][^>]*>\s*(<img\s[^>]*src=["\'][^"\']+["\'][^>]*\/?>)\s*<\/a>/i',
				function( $matches ) use ( &$logs, $post ) {
					$logs .= "Post #{$post->ID}: [A] Stripped Blogger href wrapper, kept local src.\n";
					return $matches[1];
				},
				$content
			);

			if ( $new_content !== $content ) {
				$content = $new_content;
				$updated = true;
			}

			// --- Kondisi B: download gambar yang src-nya masih ke Blogger ---
			if ( preg_match_all(
				'/<img([^>]*)src=["\'](https?:\/\/\d+\.bp\.blogspot\.com\/[^"\']+)["\']([^>]*)\/?>/',
				$content, $matches, PREG_SET_ORDER
			) ) {
				foreach ( $matches as $match ) {
					$full_tag    = $match[0];
					$blogger_url = $match[2];

					$result = $this->download_and_import( $blogger_url, $post->ID );

					if ( $result['success'] ) {
						$new_tag = str_replace( $blogger_url, esc_url( $result['new_url'] ), $full_tag );
						$content = str_replace( $full_tag, $new_tag, $content );
						$logs   .= "Post #{$post->ID}: [B] Downloaded → {$result['filename']}\n";
						$updated = true;
					} else {
						$logs .= "Post #{$post->ID}: [B] ✗ {$blogger_url}: {$result['error']}\n";
					}
				}
			}

			if ( $updated ) {
				wp_update_post( array(
					'ID'           => $post->ID,
					'post_content' => $content,
				) );
				$logs .= "Post #{$post->ID}: ✓ Content updated.\n";
			}
		}

		$total = wp_count_posts()->publish;

		wp_send_json_success( array(
			'logs'            => nl2br( esc_html( $logs ) ),
			'total_processed' => $offset + count( $posts ),
			'total_posts'     => $total,
			'batch_size'      => $this->batch_size,
			'has_more'        => ( $offset + $this->batch_size ) < $total,
		) );
	}

	/**
	 * Download gambar dari Blogger dan import ke WordPress Media Library.
	 *
	 * @param  string $blogger_url URL gambar di server Blogger.
	 * @param  int    $post_id     ID post terkait.
	 * @return array  { success, filename?, new_url?, error? }
	 */
	private function download_and_import( $blogger_url, $post_id ) {
		// Upgrade ke resolusi penuh
		$url = preg_replace( '/\/s\d+(-c)?\//', '/s1600/', $blogger_url );

		$response = wp_remote_get( $url, array(
			'timeout'     => 30,
			'redirection' => 5,
			'user-agent'  => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo( 'version' ) . ')',
			'headers'     => array(
				'Referer' => 'https://www.blogger.com/',
				'Accept'  => 'image/webp,image/apng,image/*,*/*;q=0.8',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( 'success' => false, 'error' => 'HTTP ' . $code );
		}

		$data = wp_remote_retrieve_body( $response );
		if ( empty( $data ) || strlen( $data ) < 100 ) {
			return array( 'success' => false, 'error' => 'Empty image data' );
		}

		// Deteksi MIME type
		$mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = finfo_buffer( $finfo, $data );
				finfo_close( $finfo );
			}
		}

		if ( ! $mime || strpos( $mime, 'image/' ) !== 0 ) {
			$ct   = wp_remote_retrieve_header( $response, 'content-type' );
			$mime = $ct ? strtolower( trim( strtok( $ct, ';' ) ) ) : '';
		}

		$ext_map = array(
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		if ( ! isset( $ext_map[ $mime ] ) ) {
			return array( 'success' => false, 'error' => 'Unsupported image MIME type: ' . ( $mime ?: 'unknown' ) );
		}

		$ext = $ext_map[ $mime ];

		// Buat nama file
		$raw  = basename( parse_url( $url, PHP_URL_PATH ) );
		$raw  = preg_replace( '/[^a-zA-Z0-9\-_\.]/', '-', urldecode( $raw ) );
		$base = strlen( $raw ) >= 3 ? preg_replace( '/\.[^.]+$/', '', $raw ) : 'blogger-img-' . time();

		$filename = $base . '.' . $ext;
		$path     = $this->upload_dir['path'] . '/' . $filename;
		$url_out  = $this->upload_dir['url'] . '/' . $filename;

		$i = 1;
		while ( file_exists( $path ) ) {
			$filename = $base . '-' . $i . '.' . $ext;
			$path     = $this->upload_dir['path'] . '/' . $filename;
			$url_out  = $this->upload_dir['url'] . '/' . $filename;
			$i++;
		}

		if ( ! file_put_contents( $path, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return array( 'success' => false, 'error' => 'Failed to save file' );
		}

		$attach_id = wp_insert_attachment( array(
			'post_mime_type' => $mime,
			'post_title'     => sanitize_file_name( $base ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		), $path, $post_id );

		if ( is_wp_error( $attach_id ) ) {
			wp_delete_file( $path );
			return array( 'success' => false, 'error' => $attach_id->get_error_message() );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $path ) );

		return array( 'success' => true, 'filename' => $filename, 'new_url' => $url_out );
	}
}
