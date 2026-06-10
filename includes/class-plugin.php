<?php
/**
 * Main Plugin Class
 *
 * Menangani admin menu, enqueue scripts/styles, dan render semua halaman admin.
 * Inisiasi semua sub-class dilakukan di sini.
 *
 * @package Blogger_Recovery
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Blogger_Recovery_Plugin
 */
class Blogger_Recovery_Plugin {

	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		new Blogger_Image_Detector();
		new Blogger_Image_Recovery();
		new Blogger_HTML_Cleanup();
		new Blogger_Redirect_Migrator();
	}

	/**
	 * Daftarkan sub-menu di Tools.
	 */
	public function register_menus() {
		$parent = 'tools.php';
		$cap    = 'manage_options';

		add_submenu_page( $parent, 'Blogger Recovery',   'Blogger Recovery',      $cap, 'blogger-recovery-main',      array( $this, 'page_main' ) );
		add_submenu_page( $parent, 'Issues Detector',    '  ├─ Issues Detector',  $cap, 'blogger-issues-detector',    array( $this, 'page_detector' ) );
		add_submenu_page( $parent, 'Image Recovery',     '  ├─ Image Recovery',   $cap, 'blogger-image-recovery',     array( $this, 'page_image_recovery' ) );
		add_submenu_page( $parent, 'HTML Cleanup',       '  ├─ HTML Cleanup',     $cap, 'blogger-html-cleanup',       array( $this, 'page_cleanup' ) );
		add_submenu_page( $parent, 'Redirect Migrator',  '  └─ Redirect Migrator',$cap, 'blogger-redirect-migrator',  array( $this, 'page_redirect' ) );
	}

	/**
	 * Enqueue CSS di halaman plugin.
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'blogger-recovery' ) === false
			&& strpos( $hook, 'blogger-issues' ) === false
			&& strpos( $hook, 'blogger-image' ) === false
			&& strpos( $hook, 'blogger-html' ) === false
			&& strpos( $hook, 'blogger-redirect' ) === false
		) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		wp_enqueue_style(
			'blogger-recovery',
			BLOGGER_RECOVERY_URL . 'assets/admin.css',
			array(),
			BLOGGER_RECOVERY_VERSION
		);
	}

	// =========================================================================
	// HALAMAN 1 — Dashboard / Main
	// =========================================================================

	public function page_main() {
		?>
		<div class="wrap br-wrap">
			<h1>🚀 Blogger Recovery Tools <span class="br-version">v<?php echo esc_html( BLOGGER_RECOVERY_VERSION ); ?></span></h1>
			<p>Solusi lengkap untuk recovery migrasi dari Blogger ke WordPress.</p>

			<div class="br-grid-2">
				<div class="br-card">
					<h2>📋 Workflow yang Direkomendasikan</h2>
					<ol>
						<li><strong>Issues Detector</strong> — Scan & lihat scope masalah dulu</li>
						<li><strong>Image Recovery</strong> — Strip Blogger href wrapper + download gambar yang belum ada</li>
						<li><strong>HTML Cleanup</strong> — Hapus AdSense, fix internal links, bersihkan HTML lama</li>
						<li><strong>Redirect Migrator</strong> — Pindahkan rules dari plugin Redirection → Yoast Premium</li>
					</ol>
				</div>
				<div class="br-card br-card--warn">
					<h2>⚠️ Kondisi Real ranalino.co</h2>
					<ul>
						<li>🔗 <strong>~85 artikel</strong> — gambar dengan Blogger href wrapper</li>
						<li>📜 <strong>~70 artikel</strong> — script AdSense tertanam</li>
						<li>🔗 <strong>~224 artikel</strong> — internal link format <code>.html</code></li>
						<li>🔀 <strong>54 redirect rules</strong> — siap dimigrasikan ke Yoast</li>
					</ul>
				</div>
			</div>

			<div class="br-notice br-notice--success" style="margin-top:20px;">
				<strong>✅ v2.0 Fixes:</strong>
				Urutan cleanup diperbaiki (malformed quotes → AdSense),
				logic Image Recovery disesuaikan kondisi real (strip href wrapper),
				modul Redirect Migrator baru, dan plugin direfactor ke struktur multi-file.
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// HALAMAN 2 — Issues Detector
	// =========================================================================

	public function page_detector() {
		$nonce = wp_create_nonce( 'detect_issues' );
		?>
		<div class="wrap br-wrap">
			<h1>🔍 Issues Detector</h1>
			<p>Scan semua artikel untuk menemukan masalah migrasi dari Blogger.</p>

			<div class="br-actions">
				<button class="button button-primary" id="btn-start-scan">▶ Scan All Posts</button>
				<button class="button" id="btn-export-csv" disabled>⬇ Export CSV</button>
			</div>

			<div id="scan-progress" class="br-progress"></div>
			<div id="scan-results" class="br-results"></div>
		</div>

		<script>
		jQuery(function($){
			var nonce  = '<?php echo esc_js( $nonce ); ?>';
			var issues = { blogger_href_wrapper:[], full_blogger_images:[], adsense_blocks:[], blogger_html_links:[], old_html:[] };

			$('#btn-start-scan').on('click', function(){
				$(this).prop('disabled', true);
				issues = { blogger_href_wrapper:[], full_blogger_images:[], adsense_blocks:[], blogger_html_links:[], old_html:[] };
				$('#scan-results').html('');
				$('#scan-progress').html('Scanning…');
				runBatch(0);
			});

			function runBatch(offset){
				$.post(ajaxurl, { action:'detect_blogger_issues', offset:offset, _wpnonce:nonce }, function(r){
					if (!r.success){ $('#scan-progress').html('<span class="br-error">Error: '+r.data+'</span>'); return; }
					var d = r.data;
					$.each(d.issues, function(k, v){ issues[k] = issues[k].concat(v); });
					$('#scan-progress').html('Scanned: <strong>'+d.total_scanned+' / '+d.total_posts+'</strong>');
					if (d.has_more){ setTimeout(function(){ runBatch(offset + d.batch_size); }, 300); }
					else { renderResults(); $('#btn-start-scan').prop('disabled', false); }
				}, 'json');
			}

			function renderResults(){
				var rows = [
					['🔗 Blogger href wrapper <em>(src sudah lokal)</em>', issues.blogger_href_wrapper.length, 'Image Recovery → strip href wrapper'],
					['🖼 Gambar src masih ke Blogger',                    issues.full_blogger_images.length,  'Image Recovery → download & import'],
					['🚫 AdSense block tertanam',                         issues.adsense_blocks.length,       'HTML Cleanup → Remove AdSense'],
					['🔗 Internal link format .html',                     issues.blogger_html_links.length,   'HTML Cleanup → Fix HTML Links'],
					['🔧 HTML lama (tr_bq, border, dll)',                  issues.old_html.length,             'HTML Cleanup → Misc'],
				];
				var h = '<h2>📊 Scan Results</h2><table class="widefat striped"><thead><tr><th>Issue</th><th>Count</th><th>Action</th></tr></thead><tbody>';
				$.each(rows, function(i, r){ h += '<tr><td>'+r[0]+'</td><td><strong>'+r[1]+'</strong></td><td>'+r[2]+'</td></tr>'; });
				h += '</tbody></table>';
				$('#scan-results').html(h);
				$('#btn-export-csv').prop('disabled', false);
			}

			$('#btn-export-csv').on('click', function(){
				var csv = 'Issue Type,Post ID,Detail\n';
				$.each(issues.blogger_href_wrapper, function(i,v){ csv += 'Blogger Href Wrapper,'+v.post_id+',"'+v.local_src+'"\n'; });
				$.each(issues.full_blogger_images,  function(i,v){ csv += 'Full Blogger Image,'+v.post_id+',"'+v.url+'"\n'; });
				$.each(issues.adsense_blocks,        function(i,v){ csv += 'AdSense Block,'+v.post_id+','+v.count+' occurrences\n'; });
				$.each(issues.blogger_html_links,    function(i,v){ csv += 'Blogger HTML Links,'+v.post_id+','+v.count+' links\n'; });
				$.each(issues.old_html,              function(i,v){ csv += 'Old HTML,'+v.post_id+',"'+v.issue+'"\n'; });
				var a   = document.createElement('a');
				a.href  = URL.createObjectURL(new Blob([csv], {type:'text/csv'}));
				a.download = 'blogger-issues-<?php echo esc_js( gmdate( 'Y-m-d' ) ); ?>.csv';
				a.click();
			});
		});
		</script>
		<?php
	}

	// =========================================================================
	// HALAMAN 3 — Image Recovery
	// =========================================================================

	public function page_image_recovery() {
		$nonce = wp_create_nonce( 'blogger_recover' );
		?>
		<div class="wrap br-wrap">
			<h1>🖼 Image Recovery</h1>

			<div class="br-notice br-notice--info">
				<strong>Dua mode recovery:</strong><br>
				<strong>Mode A</strong> — <code>src</code> sudah lokal tapi <code>href</code> wrapper ke Blogger → hapus wrapper <code>&lt;a&gt;</code>.<br>
				<strong>Mode B</strong> — <code>src</code> masih ke Blogger → download dari Google, import ke Media Library, update src.
			</div>

			<button class="button button-primary" id="btn-recover">▶ Start Recovery</button>
			<div id="recover-progress" class="br-progress"></div>
			<div id="recover-log" class="br-log"></div>
		</div>

		<script>
		jQuery(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			$('#btn-recover').on('click', function(){
				$(this).prop('disabled', true);
				$('#recover-log').html('');
				$('#recover-progress').html('Starting…');
				runBatch(0);
			});
			function runBatch(offset){
				$.post(ajaxurl, { action:'blogger_recover_images', offset:offset, _wpnonce:nonce }, function(r){
					if (!r.success){ $('#recover-log').append('<span class="br-error">Error: '+r.data+'</span>'); return; }
					var d = r.data;
					if (d.logs) $('#recover-log').append(d.logs+'<br>');
					$('#recover-progress').html('Processed: <strong>'+d.total_processed+' / '+d.total_posts+'</strong>');
					if (d.has_more){ setTimeout(function(){ runBatch(offset + d.batch_size); }, 800); }
					else { $('#recover-log').append('<br><strong class="br-success">✓ Recovery selesai!</strong>'); $('#btn-recover').prop('disabled', false); }
				}, 'json');
			}
		});
		</script>
		<?php
	}

	// =========================================================================
	// HALAMAN 4 — HTML Cleanup
	// =========================================================================

	public function page_cleanup() {
		$nonce = wp_create_nonce( 'cleanup_html' );
		?>
		<div class="wrap br-wrap">
			<h1>🧹 HTML Cleanup</h1>

			<div class="br-notice br-notice--warn">
				<strong>Urutan kritis v2.0:</strong>
				Malformed quotes (<code>width=""180?"</code>) diperbaiki <em>sebelum</em> AdSense removal —
				ini yang menyebabkan AdSense tidak terhapus di versi sebelumnya.
			</div>

			<div class="br-card" style="margin-bottom:20px;">
				<p class="br-section-label br-label--high">🔥 High Priority</p>
				<label><input type="checkbox" id="opt-quotes" checked> <strong>Fix malformed HTML quotes</strong> <em>(width=""180?", align=""left"")</em></label>
				<label><input type="checkbox" id="opt-adsense" checked> <strong>Remove AdSense blocks</strong> <em>(table-wrapped, div-wrapped, script tags)</em></label>
				<label><input type="checkbox" id="opt-links" checked> <strong>Fix internal Blogger links</strong> <em>(/2017/03/slug.html → WordPress permalink)</em></label>

				<hr class="br-divider">
				<p class="br-section-label br-label--info">🔧 Standard Cleanup</p>
				<label><input type="checkbox" id="opt-captions" checked> Convert Blogger caption tables → <code>&lt;figure&gt;</code></label>
				<label><input type="checkbox" id="opt-trbq" checked> Remove <code>tr_bq</code> blockquote class</label>
				<label><input type="checkbox" id="opt-align" checked> Convert <code>align=center</code> → CSS</label>
				<label><input type="checkbox" id="opt-border" checked> Remove <code>border=0</code> dari images</label>
				<label><input type="checkbox" id="opt-tables" checked> Modernize table markup</label>
				<label><input type="checkbox" id="opt-fonts" checked> Remove Trebuchet font styling</label>
				<label><input type="checkbox" id="opt-empty" checked> Clean empty elements & extra breaks</label>
			</div>

			<button class="button button-primary" id="btn-cleanup">▶ Start Cleanup</button>
			<div id="cleanup-progress" class="br-progress"></div>
			<div id="cleanup-log" class="br-log"></div>
		</div>

		<script>
		jQuery(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var totals = {};
			$('#btn-cleanup').on('click', function(){
				$(this).prop('disabled', true);
				totals = {
					adsense_removed: 0,
					html_links_fixed: 0,
					captions_converted: 0,
					tables_cleaned: 0,
					quotes_fixed: 0
				};
				var opts = {
					cleanup_malformed_quotes: $('#opt-quotes').is(':checked'),
					cleanup_adsense:          $('#opt-adsense').is(':checked'),
					cleanup_html_links:       $('#opt-links').is(':checked'),
					cleanup_caption_tables:   $('#opt-captions').is(':checked'),
					cleanup_tr_bq:            $('#opt-trbq').is(':checked'),
					cleanup_align:            $('#opt-align').is(':checked'),
					cleanup_img_border:       $('#opt-border').is(':checked'),
					cleanup_tables:           $('#opt-tables').is(':checked'),
					cleanup_blogger_fonts:    $('#opt-fonts').is(':checked'),
					cleanup_empty_elements:   $('#opt-empty').is(':checked'),
				};
				$('#cleanup-log').html('');
				$('#cleanup-progress').html('Starting…');
				runBatch(0, opts);
			});
			function runBatch(offset, opts){
				$.post(ajaxurl, { action:'cleanup_blogger_html', offset:offset, options:opts, _wpnonce:nonce }, function(r){
					if (!r.success){ $('#cleanup-log').append('<span class="br-error">Error: '+r.data+'</span>'); return; }
					var d = r.data;
					if (d.logs) $('#cleanup-log').append(d.logs);
					$.each(d.stats, function(key, value){ totals[key] += value; });
					$('#cleanup-progress').html('Processed: <strong>'+d.total_processed+' / '+d.total_posts+'</strong>');
					if (d.has_more){ setTimeout(function(){ runBatch(offset + d.batch_size, opts); }, 800); }
					else {
						var s = totals;
						var summary = '<br><div class="br-notice br-notice--success">'
							+ '<strong>✅ Selesai! Summary:</strong><br>'
							+ '• AdSense dihapus: '+s.adsense_removed+' artikel<br>'
							+ '• HTML links fixed: '+s.html_links_fixed+' artikel<br>'
							+ '• Caption tables: '+s.captions_converted+'<br>'
							+ '• Tables cleaned: '+s.tables_cleaned+'<br>'
							+ '• Quotes fixed: '+s.quotes_fixed+'<br>'
							+ '</div>';
						$('#cleanup-log').append(summary);
						$('#btn-cleanup').prop('disabled', false);
					}
				}, 'json');
			}
		});
		</script>
		<?php
	}

	// =========================================================================
	// HALAMAN 5 — Redirect Migrator
	// =========================================================================

	public function page_redirect() {
		$nonce        = wp_create_nonce( 'redirect_migrate' );
		$yoast_active = is_plugin_active( 'wordpress-seo-premium/wp-seo-premium.php' );
		?>
		<div class="wrap br-wrap">
			<h1>🔀 Redirect Migrator</h1>
			<p>Export rules dari plugin <strong>Redirection</strong> dan import ke <strong>Yoast SEO Premium</strong>.</p>

			<?php if ( ! $yoast_active ) : ?>
			<div class="br-notice br-notice--warn">
				⚠️ Yoast SEO Premium tidak terdeteksi aktif. Import ke Yoast tidak akan berfungsi, tapi kamu masih bisa export CSV.
			</div>
			<?php endif; ?>

			<div class="br-actions">
				<button class="button button-primary" id="btn-load">📂 Load Rules dari Redirection</button>
				<button class="button" id="btn-csv" disabled>⬇ Export CSV</button>
				<button class="button button-primary" id="btn-yoast" disabled <?php echo $yoast_active ? '' : 'title="Yoast Premium tidak aktif"'; ?>>
					🚀 Import ke Yoast Premium
				</button>
			</div>

			<div id="redirect-status" class="br-progress"></div>
			<div id="redirect-table"></div>
		</div>

		<script>
		jQuery(function($){
			var nonce    = '<?php echo esc_js( $nonce ); ?>';
			var allRules = [];

			$('#btn-load').on('click', function(){
				$(this).prop('disabled', true);
				$('#redirect-status').html('Loading…');
				$.post(ajaxurl, { action:'export_redirect_rules', _wpnonce:nonce }, function(r){
					$('#btn-load').prop('disabled', false);
					if (!r.success){ $('#redirect-status').html('<span class="br-error">'+r.data+'</span>'); return; }
					var d = r.data;
					allRules = d.rules;
					$('#redirect-status').html(
						'Ditemukan <strong>'+d.total+'</strong> rules aktif — '
						+'<strong>'+d.blogger_rules.length+'</strong> Blogger legacy, '
						+'<strong>'+d.slug_rules.length+'</strong> slug WP, '
						+'<strong>'+d.other_rules.length+'</strong> lainnya.'
					);
					var h = '<table class="widefat striped" style="margin-top:15px;">'
						+'<thead><tr><th>#</th><th>Source URL</th><th>Target URL</th><th>Code</th><th>Hits</th><th>Kategori</th></tr></thead><tbody>';
					$.each(allRules, function(i, rule){
						var cat = '🔀 Lainnya';
						if (/\/\d{4}\/\d{2}\/.*\.html$/.test(rule.source_url)) cat = '📅 Blogger legacy';
						else if (/^\/[a-z0-9\-]+\/$/.test(rule.source_url))   cat = '🔗 Slug WP';
						h += '<tr><td>'+rule.id+'</td>'
							+'<td><code>'+rule.source_url+'</code></td>'
							+'<td style="font-size:11px;">'+rule.target_url+'</td>'
							+'<td>'+rule.http_code+'</td>'
							+'<td>'+rule.last_count+'</td>'
							+'<td>'+cat+'</td></tr>';
					});
					h += '</tbody></table>';
					$('#redirect-table').html(h);
					$('#btn-csv').prop('disabled', false);
					$('#btn-yoast').prop('disabled', false);
				}, 'json');
			});

			$('#btn-csv').on('click', function(){
				var csv = 'ID,Source URL,Target URL,HTTP Code,Hits\n';
				$.each(allRules, function(i, r){
					csv += r.id+',"'+r.source_url+'","'+r.target_url+'",'+r.http_code+','+r.last_count+'\n';
				});
				var a      = document.createElement('a');
				a.href     = URL.createObjectURL(new Blob([csv], {type:'text/csv'}));
				a.download = 'redirection-rules-<?php echo esc_js( gmdate( 'Y-m-d' ) ); ?>.csv';
				a.click();
			});

			$('#btn-yoast').on('click', function(){
				if (!confirm('Import '+allRules.length+' rules ke Yoast Premium?\nRules yang sudah ada akan dilewati (tidak di-overwrite).')) return;
				$(this).prop('disabled', true).text('Importing…');
				$.post(ajaxurl, { action:'import_to_yoast', rules:JSON.stringify(allRules), _wpnonce:nonce }, function(r){
					$('#btn-yoast').prop('disabled', false).text('🚀 Import ke Yoast Premium');
					if (!r.success){ alert('Error: '+r.data); return; }
					var d = r.data;
					$('#redirect-status').html('<span class="br-success"><strong>✅ '+d.message+'</strong></span>');
					if (d.imported > 0){
						$('#redirect-status').append('<br><small>Setelah import selesai, kamu bisa nonaktifkan plugin Redirection.</small>');
					}
				}, 'json');
			});
		});
		</script>
		<?php
	}
}
