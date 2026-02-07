/**
 * Plugin Name:       Blogger Recovery Tools
 * Plugin URI:        https://chrisnov.com/plugins/blogger-recovery/
 * Description:       Tools lengkap untuk recovery migrasi dari Blogger ke WordPress.
 * Version:           1.1.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Reynov Christian
 * Author URI:        https://chrisnov.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blogger-recovery
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLOGGER_RECOVERY_VERSION', '1.0.0' );
define( 'BLOGGER_RECOVERY_PATH', plugin_dir_path( __FILE__ ) );
define( 'BLOGGER_RECOVERY_URL', plugin_dir_url( __FILE__ ) );

// ============================================================================
// CLASS 1: IMAGE DETECTOR
// ============================================================================

class Blogger_Image_Detector {
    
    private $issues = [
        'broken_images' => [],
        'blogger_urls'  => [],
        'old_html'      => [],
    ];
    
    public function __construct() {
        add_action( 'wp_ajax_detect_blogger_issues', [ $this, 'ajax_detect_issues' ] );
    }
    
	public function ajax_detect_issues() {
		check_ajax_referer( 'detect_issues' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$offset     = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$batch_size = 10;
        
        $posts = get_posts( [
            'numberposts' => $batch_size,
            'offset'      => $offset,
            'post_type'   => 'post',
            'post_status' => 'publish',
        ] );
        
        $issues = [
            'broken_images' => [],
            'blogger_urls'  => [],
            'old_html'      => [],
        ];
        
        foreach ( $posts as $post ) {
            $content = $post->post_content;
            
            // 1. Detect broken images
            if ( preg_match_all( '/<img[^>]*src=["\']([^"\']*)["\'][^>]*>/i', $content, $matches ) ) {
                foreach ( $matches[1] as $src ) {
                    if ( strpos( $src, 'wp-content/uploads' ) !== false && $this->is_broken_image( $src ) ) {
                        $issues['broken_images'][] = [
                            'post_id' => $post->ID,
                            'url'     => $src,
                        ];
                    }
                }
            }
            
            // 2. Detect Blogger URLs
            if ( preg_match_all( '/https:\/\/\d+\.bp\.blogspot\.com\/[^"\'\s)]+/i', $content, $matches ) ) {
                foreach ( $matches[0] as $url ) {
                    $issues['blogger_urls'][] = [
                        'post_id' => $post->ID,
                        'url'     => $url,
                    ];
                }
            }
            
            // 3. Detect old HTML patterns
            if ( strpos( $content, 'class="tr_bq"' ) !== false ) {
                $issues['old_html'][] = [
                    'post_id'    => $post->ID,
                    'issue_type' => 'Old blockquote format (class="tr_bq")',
                ];
            }
            if ( preg_match( '/align=["\']?center["\']?/i', $content ) ) {
                $issues['old_html'][] = [
                    'post_id'    => $post->ID,
                    'issue_type' => 'Old table/div align attribute',
                ];
            }
            if ( strpos( $content, 'border="0"' ) !== false ) {
                $issues['old_html'][] = [
                    'post_id'    => $post->ID,
                    'issue_type' => 'Old img border attribute',
                ];
            }
            // Additional Blogger-specific patterns
            if ( preg_match( '/<table[^>]*class=["\']tr-caption-container["\'][^>]*>/i', $content ) ) {
                $issues['old_html'][] = [
                    'post_id'    => $post->ID,
                    'issue_type' => 'Blogger caption table structure',
                ];
            }
            if ( preg_match( '/class=["\']tr-caption["\'][^>]*>/i', $content ) ) {
                $issues['old_html'][] = [
                    'post_id'    => $post->ID,
                    'issue_type' => 'Blogger caption styling',
                ];
            }
            if ( preg_match( '/align=["\'][^"\']*["\']?/i', $content ) ) {
                $issues['old_html'][] = [
                    'post_id'    => $post->ID,
                    'issue_type' => 'Deprecated align attributes',
                ];
            }
				if ( preg_match( '/<(div|p|span)[^>]*style=["\'][^"\']*font-family:\s*Trebuchet[^"\']*["\'][^>]*>/i', $content ) ) {
					$issues['old_html'][] = [
						'post_id'    => $post->ID,
						'issue_type' => 'Blogger-specific font styling',
					];
				}
			}
		}

		$total_posts = wp_count_posts()->publish;

		wp_send_json_success(
			[
				'issues'        => $issues,
				'total_scanned' => $offset + count( $posts ),
				'total_posts'   => $total_posts,
				'batch_size'    => $batch_size,
				'has_more'      => ( $offset + $batch_size ) < $total_posts,
			]
		);
	}
    
    private function is_broken_image( $url ) {
        $path = str_replace( get_site_url(), '', $url );
        $file_path = ABSPATH . trim( $path, '/' );
        return ! file_exists( $file_path );
    }
}

// ============================================================================
// CLASS 2: IMAGE RECOVERY
// ============================================================================

class Blogger_Image_Recovery {
    
    private $batch_size = 10;
    private $max_attempts = 3;
    private $upload_dir;
    
    public function __construct() {
        $this->upload_dir = wp_upload_dir();
        add_action( 'wp_ajax_blogger_recover_images', [ $this, 'ajax_recover_images' ] );
    }
    
	public function ajax_recover_images() {
		check_ajax_referer( 'blogger_recover' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$logs   = '';

		$posts = get_posts(
			[
				'numberposts' => $this->batch_size,
				'offset'      => $offset,
				'post_type'   => 'post',
				'post_status' => 'publish',
			]
		);

		$total_posts = wp_count_posts()->publish;

		foreach ( $posts as $post ) {
			$content = $post->post_content;
			$updated = false;

			if ( preg_match_all( '/<img[^>]*src=["\']([^"\']*)["\'][^>]*>/i', $content, $matches ) ) {
				foreach ( $matches[1] as $src ) {
					if ( strpos( $src, 'wp-content/uploads' ) === false ) {
						continue;
					}

					if ( $this->is_broken_image( $src ) ) {
						$logs .= "Post #{$post->ID}: Found broken image - {$src}\n";

						$blogger_url = $this->find_blogger_url_in_post( $post->ID );

						if ( $blogger_url ) {
							$logs .= "  → Found original URL: {$blogger_url}\n";
							$result = $this->download_and_import_image( $blogger_url, $post->ID );

							if ( $result['success'] ) {
								$logs    .= "  ✓ Downloaded and imported: {$result['filename']}\n";
								$content  = str_replace( $src, $result['new_url'], $content );
								$updated  = true;
							} else {
								$logs .= "  ✗ Error: {$result['error']}\n";
							}
						}
					}
				}
			}

			if ( $updated ) {
				wp_update_post(
					[
						'ID'           => $post->ID,
						'post_content' => $content,
					]
				);
				$logs .= "  → Post content updated\n";
			}
		}

		wp_send_json_success(
			[
				'logs'            => nl2br( $logs ),
				'total_processed' => $offset + count( $posts ),
				'total_posts'     => $total_posts,
				'batch_size'      => $this->batch_size,
				'has_more'        => ( $offset + $this->batch_size ) < $total_posts,
			]
		);
	}
    
    private function is_broken_image( $url ) {
        $path = str_replace( get_site_url(), '', $url );
        $file_path = ABSPATH . trim( $path, '/' );
        return ! file_exists( $file_path );
    }
    
    private function find_blogger_url_in_post( $post_id ) {
        $post = get_post( $post_id );
        $content = $post->post_content . ' ' . $post->post_excerpt;
        
        $pattern = '/https:\/\/\d+\.bp\.blogspot\.com\/[^"\'\s)]+/i';
        
        if ( preg_match( $pattern, $content, $matches ) ) {
            return $matches[0];
        }
        
        return null;
    }
    
    private function download_and_import_image( $blogger_url, $post_id ) {
            // Clean URL - remove size parameters from Blogger URLs
            $clean_url = preg_replace( '/\/s\d+(-c)?\//', '/s1600/', $blogger_url );
            
            $response = wp_remote_get( $clean_url, [
                'timeout'     => 30,
                'redirection' => 5,
                'sslverify'   => false,
                'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'headers'     => [
                    'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
                ],
            ] );
            
            if ( is_wp_error( $response ) ) {
                return [
                    'success' => false,
                    'error'   => 'HTTP Error: ' . $response->get_error_message(),
                ];
            }
            
            $response_code = wp_remote_retrieve_response_code( $response );
            if ( $response_code !== 200 ) {
                return [
                    'success' => false,
                    'error'   => 'HTTP ' . $response_code . ' - Failed to download',
                ];
            }
            
            $image_data = wp_remote_retrieve_body( $response );
            if ( empty( $image_data ) || strlen( $image_data ) < 100 ) {
                return [
                    'success' => false,
                    'error'   => 'Empty or corrupted image data (size: ' . strlen( $image_data ) . ' bytes)',
                ];
            }
            
            // Detect proper MIME type from image data
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            $mime_type = finfo_buffer( $finfo, $image_data );
            finfo_close( $finfo );
            
            if ( ! $mime_type || strpos( $mime_type, 'image/' ) !== 0 ) {
                // Fallback: get from header
                $content_type = wp_remote_retrieve_header( $response, 'content-type' );
                $mime_type = $content_type ? $content_type : 'image/jpeg';
            }
            
            // Get proper extension from MIME type
            $extension_map = [
                'image/jpeg' => 'jpg',
                'image/jpg'  => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
            ];
            
            $extension = isset( $extension_map[ $mime_type ] ) ? $extension_map[ $mime_type ] : 'jpg';
            
            // Generate filename
            $url_filename = basename( parse_url( $clean_url, PHP_URL_PATH ) );
            $url_filename = preg_replace( '/[^a-zA-Z0-9\-_\.]/', '-', $url_filename );
            
            if ( empty( $url_filename ) || strlen( $url_filename ) < 3 ) {
                $url_filename = 'blogger-image-' . time();
            }
            
            // Remove old extension and add proper one
            $base_name = preg_replace( '/\.[^.]+$/', '', $url_filename );
            $filename = $base_name . '.' . $extension;
            
            // Check for duplicate files
            $upload_path = $this->upload_dir['path'] . '/' . $filename;
            $upload_url = $this->upload_dir['url'] . '/' . $filename;
            
            $i = 1;
            while ( file_exists( $upload_path ) ) {
                $filename = $base_name . '-' . $i . '.' . $extension;
                $upload_path = $this->upload_dir['path'] . '/' . $filename;
                $upload_url = $this->upload_dir['url'] . '/' . $filename;
                $i++;
            }
            
            // Save file
            $saved = file_put_contents( $upload_path, $image_data );
            if ( ! $saved ) {
                return [
                    'success' => false,
                    'error'   => 'Failed to save file to: ' . $upload_path,
                ];
            }
            
            // Verify file was actually saved
            if ( ! file_exists( $upload_path ) || filesize( $upload_path ) < 100 ) {
                return [
                    'success' => false,
                    'error'   => 'File saved but appears corrupted (size: ' . filesize( $upload_path ) . ' bytes)',
                ];
            }
            
            // Create attachment
            $attachment = [
                'post_mime_type' => $mime_type,
                'post_title'     => sanitize_file_name( $base_name ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ];
            
            $attach_id = wp_insert_attachment( $attachment, $upload_path, $post_id );
            
            if ( is_wp_error( $attach_id ) ) {
                return [
                    'success' => false,
                    'error'   => 'Attachment error: ' . $attach_id->get_error_message(),
                ];
            }
            
            // Generate metadata
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            $attach_data = wp_generate_attachment_metadata( $attach_id, $upload_path );
            wp_update_attachment_metadata( $attach_id, $attach_data );
            
            return [
                'success'   => true,
                'attach_id' => $attach_id,
                'filename'  => $filename,
                'new_url'   => $upload_url,
                'size'      => filesize( $upload_path ),
                'mime_type' => $mime_type,
            ];
        }
    }

// ============================================================================
// CLASS 3: HTML CLEANUP (ENHANCED WITH ADSENSE REMOVAL)
// ============================================================================

class Blogger_HTML_Cleanup {
    
    private $cleanup_stats = [
        'adsense_removed' => 0,
        'tables_cleaned' => 0,
        'quotes_fixed' => 0,
    ];
    
    public function __construct() {
        add_action( 'wp_ajax_cleanup_blogger_html', [ $this, 'ajax_cleanup' ] );
    }
    
	public function ajax_cleanup() {
		check_ajax_referer( 'cleanup_html' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$offset     = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$batch_size = 10;
		$options    = isset( $_POST['options'] ) ? (array) $_POST['options'] : [];
		$logs       = '';

		$posts = get_posts(
			[
				'numberposts' => $batch_size,
				'offset'      => $offset,
				'post_type'   => 'post',
				'post_status' => 'publish',
			]
		);

		foreach ( $posts as $post ) {
			$content      = $post->post_content;
			$post_updated = false;

			// PRIORITY 1: Remove AdSense blocks first (before other cleanup).
			if ( ! empty( $options['cleanup_adsense'] ) ) {
				$before_adsense = $content;

				// Pattern 1: Table-wrapped AdSense.
				$content = preg_replace(
					'/<table[^>]*>\s*<tbody[^>]*>\s*<tr[^>]*>\s*<td[^>]*>.*?adsbygoogle.*?<\/td>\s*<\/tr>\s*<\/tbody>\s*<\/table>/is',
					'',
					$content
				);

				// Pattern 2: Div-wrapped AdSense.
				$content = preg_replace(
					'/<div[^>]*>.*?<ins\s+class=["\']adsbygoogle["\'][^>]*>.*?<\/ins>.*?(adsbygoogle\s*=\s*window\.adsbygoogle.*?\|\|\s*\[\]\)\.push\([^)]*\);?).*?<\/div>/is',
					'',
					$content
				);

				// Pattern 3: Standalone ins + script.
				$content = preg_replace(
					'/<ins\s+class=["\']adsbygoogle["\'][^>]*>.*?<\/ins>\s*(adsbygoogle\s*=\s*window\.adsbygoogle.*?\|\|\s*\[\]\)\.push\([^)]*\);?)/is',
					'',
					$content
				);

				// Pattern 4: Script tags containing AdSense.
				$content = preg_replace(
					'/<script[^>]*>.*?(adsbygoogle\s*=\s*window\.adsbygoogle.*?\|\|\s*\[\]\)\.push\([^)]*\);?).*?<\/script>/is',
					'',
					$content
				);

				// Pattern 5: Loose AdSense JavaScript (tanpa script tag).
				$content = preg_replace(
					'/\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\.push\([^)]*\);?/i',
					'',
					$content
				);

				// Pattern 6: AdSense comment markers.
				$content = preg_replace(
					'/<!--\s*AdSense.*?-->/is',
					'',
					$content
				);

				if ( $content !== $before_adsense ) {
					$logs .= "Post #{$post->ID}: Removed AdSense blocks\n";
					$this->cleanup_stats['adsense_removed']++;
					$post_updated = true;
				}
			}

			// PRIORITY 2: Fix malformed quotes (sebelum cleanup lainnya).
			if ( ! empty( $options['cleanup_malformed_quotes'] ) ) {
				$before_quotes = $content;

				// Fix double-escaped quotes: align=""left"" -> align="left".
				$content = preg_replace( '/(\w+)=["\']""([^""]*)"?"?["\']/', '$1="$2"', $content );

				// Fix missing closing quotes.
				$content = preg_replace( '/(\w+)=["\']([^"\'>\s]+)(?=[>\s])/', '$1="$2"', $content );

				// Fix space in quotes: width=""180?" -> width="180".
				$content = preg_replace( '/(\w+)=["\']([^"\']*)\?["\']/', '$1="$2"', $content );

				if ( $content !== $before_quotes ) {
					$logs .= "Post #{$post->ID}: Fixed malformed HTML quotes\n";
					$this->cleanup_stats['quotes_fixed']++;
					$post_updated = true;
				}
			}

			if ( ! empty( $options['cleanup_tr_bq'] ) ) {
				$before  = $content;
				$content = preg_replace( '/<blockquote\s+class=["\']tr_bq["\']>/i', '<blockquote>', $content );
				if ( $content !== $before ) {
					$logs        .= "Post #{$post->ID}: Cleaned tr_bq blockquote\n";
					$post_updated = true;
				}
			}

			if ( ! empty( $options['cleanup_align'] ) ) {
				$before  = $content;
				$content = preg_replace(
					'/<(div|table|p)\s+align=["\']?center["\']?([^>]*)>/i',
					'<$1 style="text-align: center;" $2>',
					$content
				);

				$content = preg_replace_callback(
					'/<img\s+([^>]*?)\s+align=["\']?center["\']?([^>]*)>/i',
					function( $matches ) {
						return '<figure style="text-align: center;"><img ' . $matches[1] . ' ' . $matches[2] . '></figure>';
					},
					$content
				);

				if ( $content !== $before ) {
					$logs        .= "Post #{$post->ID}: Converted align attributes to CSS\n";
					$post_updated = true;
				}
			}

			if ( ! empty( $options['cleanup_img_border'] ) ) {
				$before  = $content;
				$content = preg_replace( '/\s+border=["\']?0["\']?/i', '', $content );
				if ( $content !== $before ) {
					$logs        .= "Post #{$post->ID}: Removed border=\"0\" from images\n";
					$post_updated = true;
				}
			}

			if ( ! empty( $options['cleanup_tables'] ) ) {
				$before  = $content;
				$content = preg_replace( '/\s+cellpadding=["\']?[^"\'>\s]+["\']?/i', '', $content );
				$content = preg_replace( '/\s+cellspacing=["\']?[^"\'>\s]+["\']?/i', '', $content );

				$content = preg_replace_callback(
					'/<table[^>]*>(.*?)<\/table>/is',
					function( $matches ) {
						$table_content = $matches[1];
						if ( strpos( $table_content, '<thead>' ) === false && strpos( $table_content, '<tbody>' ) === false ) {
							$table_content = '<tbody>' . $table_content . '</tbody>';
						}
						return '<table>' . $table_content . '</table>';
					},
					$content
				);

				if ( $content !== $before ) {
					$logs .= "Post #{$post->ID}: Modernized table markup\n";
					$this->cleanup_stats['tables_cleaned']++;
					$post_updated = true;
				}
			}

			if ( ! empty( $options['cleanup_caption_tables'] ) ) {
				$before  = $content;
				$content = preg_replace_callback(
					'/<table[^>]*class=["\']tr-caption-container["\'][^>]*>.*?<tbody[^>]*>.*?<td[^>]*>.*?<a[^>]*href=["\']([^"\']*)["\'][^>]*>.*?<img([^>]*)><\/a>.*?<\/td>.*?<\/tr>.*?<tr[^>]*>.*?<td[^>]*class=["\']tr-caption["\'][^>]*>(.*?)<\/td>.*?<\/tr>.*?<\/tbody>.*?<\/table>/is',
					function( $matches ) {
						$image_url   = $matches[1];
						$image_attrs = $matches[2];
						$caption     = trim( strip_tags( $matches[3] ) );
						return '<figure><img src="' . esc_url( $image_url ) . '"' . $image_attrs . '><figcaption>' . esc_html( $caption ) . '</figcaption></figure>';
					},
					$content
				);

				if ( $content !== $before ) {
					$logs        .= "Post #{$post->ID}: Converted Blogger caption tables\n";
					$post_updated = true;
				}
			}

			if ( ! empty( $options['cleanup_blogger_fonts'] ) ) {
				$before  = $content;
				$content = preg_replace( '/style=["\'][^"\']*font-family:\s*Trebuchet[^"\']*["\']/', '', $content );
				$content = preg_replace( '/<(span|div|p)[^>]*style=["\'][^"\']*font-family:[^"\']*Trebuchet[^"\']*["\'][^>]*>/i', '<$1>', $content );

				if ( $content !== $before ) {
					$logs        .= "Post #{$post->ID}: Removed Blogger fonts\n";
					$post_updated = true;
				}
			}

			if ( ! empty( $options['cleanup_empty_elements'] ) ) {
				$before  = $content;
				$content = preg_replace( '/<br\s*\/?>(\s*<br\s*\/?>)+/i', '<br>', $content );
				$content = preg_replace( '/<p[^>]*>\s*<\/p>/i', '', $content );
				$content = preg_replace( '/<span[^>]*>\s*<\/span>/i', '', $content );
				$content = preg_replace( '/<div[^>]*>\s*<\/div>/i', '', $content );

				if ( $content !== $before ) {
					$logs        .= "Post #{$post->ID}: Cleaned empty elements\n";
					$post_updated = true;
				}
			}

			// Update post if changed.
			if ( $post_updated && $content !== $post->post_content ) {
				wp_update_post(
					[
						'ID'           => $post->ID,
						'post_content' => $content,
					]
				);
			}
		}

		$total_posts = wp_count_posts()->publish;

		wp_send_json_success(
			[
				'logs'            => nl2br( $logs ),
				'total_processed' => $offset + count( $posts ),
				'total_posts'     => $total_posts,
				'batch_size'      => $batch_size,
				'has_more'        => ( $offset + $batch_size ) < $total_posts,
				'stats'           => $this->cleanup_stats,
			]
		);
	}
}

// ============================================================================
// MAIN PLUGIN CLASS - Admin UI & Router (UNCHANGED - TOO LONG, CONTINUES...)
// ============================================================================

class Blogger_Recovery_Plugin {
    
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_pages' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        
        // Initialize sub-classes
        new Blogger_Image_Detector();
        new Blogger_Image_Recovery();
        new Blogger_HTML_Cleanup();
    }
    
    public function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'blogger-recovery' ) === false ) {
            return;
        }
        wp_enqueue_script( 'jquery' );
    }
    
    public function add_admin_pages() {
        $parent_slug = 'tools.php';
        
        // Main page
        add_submenu_page(
            $parent_slug,
            'Blogger Recovery',
            'Blogger Recovery',
            'manage_options',
            'blogger-recovery-main',
            [ $this, 'render_main_page' ]
        );
        
        // Sub pages
        add_submenu_page(
            $parent_slug,
            'Issues Detector',
            '  ├─ Issues Detector',
            'manage_options',
            'blogger-issues-detector',
            [ $this, 'render_detector_page' ]
        );
        
        add_submenu_page(
            $parent_slug,
            'Image Recovery',
            '  ├─ Image Recovery',
            'manage_options',
            'blogger-image-recovery',
            [ $this, 'render_recovery_page' ]
        );
        
        add_submenu_page(
            $parent_slug,
            'HTML Cleanup',
            '  └─ HTML Cleanup',
            'manage_options',
            'blogger-html-cleanup',
            [ $this, 'render_cleanup_page' ]
        );
    }
    
    public function render_main_page() {
        ?>
        <div class="wrap">
            <h1>🚀 Blogger Recovery Tools</h1>
            <p>Solusi lengkap untuk recovery migrasi dari Blogger ke WordPress</p>
            
            <div style="background: #fff; padding: 20px; border-radius: 5px; margin-top: 20px;">
                <h2>Workflow Perbaikan:</h2>
                <ol>
                    <li><strong>Issues Detector</strong> - Scan semua artikel untuk menemukan masalah</li>
                    <li><strong>Image Recovery</strong> - Download gambar dari Blogger & import ke WordPress</li>
                    <li><strong>HTML Cleanup</strong> - Bersihkan format HTML lama dari Blogger</li>
                </ol>
            </div>
            
            <div style="background: #f9f9f9; padding: 15px; margin-top: 20px; border-left: 4px solid #0073aa;">
                <h3>📋 Sebelum Mulai:</h3>
                <ul>
                    <li>✅ Backup database Anda</li>
                    <li>✅ Pastikan folder /wp-content/uploads/ writable</li>
                    <li>✅ Jangan close tab browser saat proses berjalan</li>
                </ul>
            </div>
            
            <div style="background: #fff3cd; padding: 15px; margin-top: 20px; border-left: 4px solid #ff9800;">
                <h3>⚠️ Catatan:</h3>
                <p>Proses recovery bisa memakan waktu tergantung jumlah artikel. Disarankan jalankan di off-peak hours (malam hari).</p>
            </div>
        </div>
        <?php
    }
    
    public function render_detector_page() {
        wp_nonce_field( 'detect_issues' );
        ?>
        <div class="wrap">
            <h1>Issues Detector</h1>
            <p>Scan semua artikel untuk menemukan masalah migrasi dari Blogger</p>
            
            <button class="button button-primary" id="start-detection">Scan All Posts</button>
            <button class="button" id="export-report">Export Report as CSV</button>
            
            <div id="detection-progress" style="margin-top: 20px;"></div>
            <div id="detection-results" style="margin-top: 20px;"></div>
        </div>
        
        <script>
        jQuery(function($) {
            let report_data = {};
            
            $('#start-detection').click(function() {
                $(this).prop('disabled', true);
                report_data = {};
                $('#detection-results').html('');
                $('#detection-progress').html('Scanning...');
                detectBatch(0);
            });
            
            function detectBatch(offset) {
                $.post(ajaxurl, {
                    action: 'detect_blogger_issues',
                    offset: offset,
                    _wpnonce: '<?php echo wp_create_nonce('detect_issues'); ?>'
                }, function(response) {
                    if (response.success) {
                        const data = response.data;
                        report_data = Object.assign(report_data, data.issues);
                        
                        $('#detection-progress').html(`<strong>Scanned: ${data.total_scanned}/${data.total_posts}</strong>`);
                        
                        if (data.has_more) {
                            setTimeout(() => detectBatch(offset + data.batch_size), 500);
                        } else {
                            displayResults();
                        }
                    } else {
                        $('#detection-progress').html('<span style="color: red;">Error: ' + response.data + '</span>');
                    }
                }, 'json');
            }
            
            function displayResults() {
                let html = '<h2>Results</h2>';
                
                if (report_data.broken_images && report_data.broken_images.length > 0) {
                    html += '<h3 style="color: red;">❌ Broken Images (' + report_data.broken_images.length + ')</h3>';
                    html += '<table class="wp-list-table" style="margin-bottom: 20px;"><thead><tr><th>Post ID</th><th>URL</th></tr></thead><tbody>';
                    report_data.broken_images.forEach(issue => {
                        html += `<tr><td>#${issue.post_id}</td><td><code>${issue.url.substring(0, 80)}</code></td></tr>`;
                    });
                    html += '</tbody></table>';
                }
                
                if (report_data.adsense_blocks && report_data.adsense_blocks.length > 0) {
                    html += '<h3 style="color: #ff6b6b;">🚫 AdSense Blocks Found (' + report_data.adsense_blocks.length + ' posts)</h3>';
                    html += '<div style="background: #fff3cd; padding: 15px; margin-bottom: 20px; border-left: 4px solid #ff9800;">';
                    html += '<strong>⚠️ Recommendation:</strong> Run HTML Cleanup with "Remove AdSense blocks" option enabled.';
                    html += '</div>';
                    html += '<table class="wp-list-table" style="margin-bottom: 20px;"><thead><tr><th>Post ID</th><th>AdSense Count</th></tr></thead><tbody>';
                    report_data.adsense_blocks.forEach(issue => {
                        html += `<tr><td>#${issue.post_id}</td><td>${issue.count} block(s)</td></tr>`;
                    });
                    html += '</tbody></table>';
                }
                
                if (report_data.blogger_urls && report_data.blogger_urls.length > 0) {
                    html += '<h3 style="color: orange;">⚠️ Still pointing to Blogger (' + report_data.blogger_urls.length + ')</h3>';
                    html += '<table class="wp-list-table" style="margin-bottom: 20px;"><thead><tr><th>Post ID</th><th>URL</th></tr></thead><tbody>';
                    report_data.blogger_urls.forEach(issue => {
                        html += `<tr><td>#${issue.post_id}</td><td><a href="${issue.url}" target="_blank">${issue.url.substring(0, 80)}...</a></td></tr>`;
                    });
                    html += '</tbody></table>';
                }
                
                if (report_data.old_html && report_data.old_html.length > 0) {
                    html += '<h3 style="color: blue;">🔧 Old HTML Format (' + report_data.old_html.length + ')</h3>';
                    html += '<ul>';
                    report_data.old_html.forEach(issue => {
                        html += `<li>Post #${issue.post_id}: ${issue.issue_type}</li>`;
                    });
                    html += '</ul>';
                }
                
                $('#detection-results').html(html);
                $('#export-report').prop('disabled', false);
                $('#start-detection').prop('disabled', false);
            }
            
            $('#export-report').click(function() {
                let csv = 'Issue Type,Post ID,Details\n';
                
                if (report_data.broken_images) {
                    report_data.broken_images.forEach(issue => {
                        csv += `Broken Image,${issue.post_id},"${issue.url}"\n`;
                    });
                }
                
                if (report_data.adsense_blocks) {
                    report_data.adsense_blocks.forEach(issue => {
                        csv += `AdSense Block,${issue.post_id},"${issue.count} blocks found"\n`;
                    });
                }
                
                if (report_data.blogger_urls) {
                    report_data.blogger_urls.forEach(issue => {
                        csv += `Blogger URL,${issue.post_id},"${issue.url}"\n`;
                    });
                }
                
                if (report_data.old_html) {
                    report_data.old_html.forEach(issue => {
                        csv += `Old HTML,${issue.post_id},"${issue.issue_type}"\n`;
                    });
                }
                
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'blogger-issues-' + new Date().toISOString().split('T')[0] + '.csv';
                a.click();
            });
        });
        </script>
        <?php
    }
    
    public function render_recovery_page() {
        wp_nonce_field( 'blogger_recover' );
        ?>
        <div class="wrap">
            <h1>Image Recovery</h1>
            <p>Download gambar dari Blogger & import ke WordPress media library</p>
            
            <div style="background: #fff3cd; padding: 15px; margin-bottom: 20px; border-left: 4px solid #ff9800;">
                <strong>⚠️ Penting:</strong> Pastikan folder wp-content/uploads/ writable sebelum mulai!
            </div>
            
            <button class="button button-primary" id="blogger-recover-btn">Start Recovery Process</button>
            <div id="blogger-recovery-progress" style="margin-top: 20px;"></div>
            <div id="blogger-recovery-log" style="margin-top: 20px; padding: 10px; background: #f5f5f5; max-height: 500px; overflow-y: auto; border: 1px solid #ddd;"></div>
        </div>
        
        <script>
        jQuery(function($) {
            $('#blogger-recover-btn').click(function() {
                $(this).prop('disabled', true);
                $('#blogger-recovery-progress').html('Starting recovery process...<br>');
                recoverBatch(0);
            });
            
            function recoverBatch(offset) {
                $.post(ajaxurl, {
                    action: 'blogger_recover_images',
                    offset: offset,
                    _wpnonce: '<?php echo wp_create_nonce('blogger_recover'); ?>'
                }, function(response) {
                    if (response.success) {
                        const data = response.data;
                        $('#blogger-recovery-log').append(data.logs + '<br>');
                        $('#blogger-recovery-progress').html(`<strong>Processed: ${data.total_processed}/${data.total_posts}</strong>`);
                        
                        if (data.has_more) {
                            setTimeout(function() {
                                recoverBatch(offset + data.batch_size);
                            }, 1000);
                        } else {
                            $('#blogger-recovery-log').append('<strong style="color: green;">✓ Recovery process completed!</strong>');
                            $('#blogger-recover-btn').prop('disabled', false);
                        }
                    } else {
                        $('#blogger-recovery-log').append('<span style="color: red;">Error: ' + response.data + '</span>');
                        $('#blogger-recover-btn').prop('disabled', false);
                    }
                }, 'json');
            }
        });
        </script>
        <?php
    }
    
    public function render_cleanup_page() {
        wp_nonce_field( 'cleanup_html' );
        ?>
        <div class="wrap">
            <h1>HTML Cleanup</h1>
            <p>Bersihkan format HTML lama dari Blogger ke format modern WordPress</p>
            
            <div style="background: #e7f3ff; padding: 15px; margin-bottom: 20px; border-left: 4px solid #0073aa;">
                <strong>ℹ️ Tips:</strong> Pilih semua opsi untuk hasil terbaik
            </div>
            
            <fieldset style="background: #f9f9f9; padding: 15px; margin-bottom: 20px; border: 1px solid #ddd;">
                <legend style="padding: 5px 10px; font-weight: bold;">Cleanup Options:</legend>

                <div style="background: #fff3cd; padding: 10px; margin-bottom: 15px; border-left: 3px solid #ff9800;">
                    <strong>⚠️ High Priority:</strong>
                </div>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" id="cleanup-adsense" checked>
                    <strong>🔥 Remove AdSense blocks (Tables, Scripts, Ads)</strong>
                </label>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" id="cleanup-malformed-quotes" checked>
                    <strong>Fix malformed HTML quotes (align=""left"", width=""180?")</strong>
                </label>

                <hr style="margin: 15px 0;">

                <div style="background: #e7f3ff; padding: 10px; margin-bottom: 15px; border-left: 3px solid #0073aa;">
                    <strong>ℹ️ Standard Cleanup:</strong>
                </div>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" id="cleanup-tr-bq" checked>
                    Remove tr_bq blockquote classes
                </label>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" id="cleanup-align" checked>
                    Convert align="center" to CSS classes
                </label>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" id="cleanup-img-border" checked>
                    Remove border="0" from images
                </label>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" id="cleanup-tables" checked>
                    Modernize table markup
                </label>

                <hr style="margin: 15px 0;">

                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" id="cleanup-caption-tables" checked>
                    Convert Blogger caption tables to WordPress figures
                </label>

                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" id="cleanup-blogger-fonts" checked>
                    Remove Blogger-specific font styling
                </label>

                <label style="display: block;">
                    <input type="checkbox" id="cleanup-empty-elements" checked>
                    Clean empty elements and excessive breaks
                </label>
            </fieldset>
            
            <button class="button button-primary" id="start-cleanup">Start Cleanup</button>
            
            <div id="cleanup-progress" style="margin-top: 20px;"></div>
            <div id="cleanup-log" style="margin-top: 20px; padding: 10px; background: #f5f5f5; max-height: 500px; overflow-y: auto; border: 1px solid #ddd;"></div>
        </div>
        
        <script>
        jQuery(function($) {
            $('#start-cleanup').click(function() {
                $(this).prop('disabled', true);
                const options = {
                    cleanup_adsense: $('#cleanup-adsense').is(':checked'),
                    cleanup_malformed_quotes: $('#cleanup-malformed-quotes').is(':checked'),
                    cleanup_tr_bq: $('#cleanup-tr-bq').is(':checked'),
                    cleanup_align: $('#cleanup-align').is(':checked'),
                    cleanup_img_border: $('#cleanup-img-border').is(':checked'),
                    cleanup_tables: $('#cleanup-tables').is(':checked'),
                    cleanup_caption_tables: $('#cleanup-caption-tables').is(':checked'),
                    cleanup_blogger_fonts: $('#cleanup-blogger-fonts').is(':checked'),
                    cleanup_empty_elements: $('#cleanup-empty-elements').is(':checked'),
                };
                $('#cleanup-progress').html('Starting cleanup...<br>');
                $('#cleanup-log').html('');
                cleanupBatch(0, options);
            });
            
            function cleanupBatch(offset, options) {
                $.post(ajaxurl, {
                    action: 'cleanup_blogger_html',
                    offset: offset,
                    options: options,
                    _wpnonce: '<?php echo wp_create_nonce('cleanup_html'); ?>'
                }, function(response) {
                    if (response.success) {
                        const data = response.data;
                        $('#cleanup-log').append(data.logs + '<br>');
                        $('#cleanup-progress').html(`<strong>Processed: ${data.total_processed}/${data.total_posts}</strong>`);
                        
                        if (data.has_more) {
                            setTimeout(() => cleanupBatch(offset + data.batch_size, options), 1000);
                        } else {
                            $('#cleanup-log').append('<br><strong style="color: green;">✓ Cleanup completed!</strong><br>');
                            if (data.stats) {
                                $('#cleanup-log').append('<br><div style="background: #d4edda; padding: 10px; border-left: 3px solid #28a745; margin-top: 10px;">');
                                $('#cleanup-log').append('<strong>📊 Summary:</strong><br>');
                                $('#cleanup-log').append('• AdSense blocks removed: ' + data.stats.adsense_removed + '<br>');
                                $('#cleanup-log').append('• Tables cleaned: ' + data.stats.tables_cleaned + '<br>');
                                $('#cleanup-log').append('• Quotes fixed: ' + data.stats.quotes_fixed + '<br>');
                                $('#cleanup-log').append('</div>');
                            }
                            $('#start-cleanup').prop('disabled', false);
                        }
                    } else {
                        $('#cleanup-log').append('<span style="color: red;">Error: ' + response.data + '</span>');
                        $('#start-cleanup').prop('disabled', false);
                    }
                }, 'json');
            }
        });
        </script>
        <?php
    }
}

// Initialize plugin
new Blogger_Recovery_Plugin();