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
		new Blogger_Database_Backup();
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
			<div class="br-hero">
				<span class="br-eyebrow">Migration recovery toolkit</span>
				<h1>Blogger Recovery Tools <span class="br-version">v<?php echo esc_html( BLOGGER_RECOVERY_VERSION ); ?></span></h1>
				<p>Audit, preview, dan perbaiki sisa migrasi Blogger ke WordPress melalui workflow yang aman dan terukur.</p>
			</div>

			<div class="br-grid-2">
				<div class="br-card">
					<h2>Workflow yang Direkomendasikan</h2>
					<ol class="br-workflow">
						<li><strong>Issues Detector</strong> — Scan & lihat scope masalah dulu</li>
						<li><strong>Database Backup</strong> — Download snapshot SQL sebelum mode Apply</li>
						<li><strong>Image Recovery</strong> — Strip Blogger href wrapper + download gambar yang belum ada</li>
						<li><strong>HTML Cleanup</strong> — Hapus AdSense, fix internal links, bersihkan HTML lama</li>
						<li><strong>Redirect Migrator</strong> — Pindahkan rules dari plugin Redirection → Yoast Premium</li>
					</ol>
				</div>
				<div class="br-card br-card--soft">
					<h2>Compatibility Note</h2>
					<ul class="br-note-list">
						<li>Plugin dibangun untuk pola migrasi Blogger yang spesifik, bukan semua kemungkinan struktur HTML.</li>
						<li>Fitur telah diuji pada website <strong>ranalino.co</strong> dengan dataset migrasi nyata.</li>
						<li>Website lain dapat memiliki markup, permalink, atau plugin redirect yang berbeda.</li>
						<li>Selalu mulai dari <strong>Issues Detector</strong> dan <strong>Dry-run</strong>.</li>
					</ul>
				</div>
			</div>

			<div class="br-notice br-notice--success">
				<strong>Safe by default.</strong>
				Dry-run aktif secara default, internal links dapat memakai fallback Redirection,
				dan database dapat dibackup sebelum operasi Apply.
			</div>
			<div class="br-notice br-notice--warn" style="margin-top:20px;">
				<strong>PERINGATAN OPERASI DESTRUKTIF:</strong>
				Image Recovery, HTML Cleanup, dan Redirect Migrator dapat mengubah konten, membuat attachment,
				atau menulis konfigurasi redirect dalam jumlah besar. Jalankan <strong>Dry-run</strong> terlebih dahulu,
				periksa log, dan pastikan backup database serta folder uploads tersedia sebelum memakai mode Apply.
			</div>
			<div class="br-card br-backup-card">
				<h2>Database Backup</h2>
				<p>
					Download dump penuh database sebagai <code>.sql.gz</code>. Backup mencakup users, password hash,
					options, post content, dan data plugin sehingga file ini <strong>sangat sensitif</strong>.
				</p>
				<p>
					File dibuat di direktori temporary server, langsung dikirim ke browser, lalu dihapus.
					Plugin tidak menyimpan salinan backup di web root atau Media Library.
				</p>
				<?php $this->render_database_backup_button(); ?>
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
			<div class="br-hero">
				<span class="br-eyebrow">Read-only audit</span>
				<h1>Issues Detector</h1>
				<p>Scan artikel terbit untuk memetakan masalah migrasi sebelum perubahan apa pun dilakukan.</p>
			</div>

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
					['🔗 Internal link format .html',                     issues.blogger_html_links.length,   'HTML Cleanup → resolve via slug + Redirection'],
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
			<div class="br-hero">
				<span class="br-eyebrow">Media migration</span>
				<h1>Image Recovery</h1>
				<p>Bersihkan wrapper Blogger dan impor gambar remote ke Media Library secara bertahap.</p>
			</div>

			<div class="br-notice br-notice--info">
				<strong>Dua mode recovery:</strong><br>
				<strong>Mode A</strong> — <code>src</code> sudah lokal tapi <code>href</code> wrapper ke Blogger → hapus wrapper <code>&lt;a&gt;</code>.<br>
				<strong>Mode B</strong> — <code>src</code> masih ke Blogger → download dari Google, import ke Media Library, update src.
			</div>
			<div class="br-notice br-notice--warn">
				<strong>PERINGATAN:</strong> Mode Apply akan mengubah HTML artikel dan dapat membuat file serta attachment baru.
				Dry-run hanya membuat laporan dan tidak mengunduh gambar atau menulis database.
				<?php $this->render_database_backup_button( true ); ?>
			</div>

			<div class="br-action-panel">
				<label class="br-toggle"><input type="checkbox" id="recover-dry-run" checked> Dry-run <span class="br-badge">Recommended</span></label>
				<button class="button button-primary" id="btn-recover">▶ Run Image Recovery</button>
			</div>
			<div id="recover-progress" class="br-progress"></div>
			<div id="recover-log" class="br-log"></div>
		</div>

		<script>
		jQuery(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			$('#btn-recover').on('click', function(){
				var dryRun = $('#recover-dry-run').is(':checked');
				var confirmation = dryRun ? '' : prompt('Mode Apply akan mengubah konten dan Media Library. Ketik APPLY untuk melanjutkan:');
				if (!dryRun && confirmation !== 'APPLY') return;
				$(this).prop('disabled', true);
				$('#recover-log').html('');
				$('#recover-progress').html(dryRun ? 'Starting dry-run…' : 'Starting APPLY mode…');
				runBatch(0, dryRun, confirmation);
			});
			function runBatch(offset, dryRun, confirmation){
				$.post(ajaxurl, { action:'blogger_recover_images', offset:offset, dry_run:dryRun, apply_confirm:confirmation, _wpnonce:nonce }, function(r){
					if (!r.success){ $('#recover-log').append('<span class="br-error">Error: '+r.data+'</span>'); $('#btn-recover').prop('disabled', false); return; }
					var d = r.data;
					if (d.logs) $('#recover-log').append(d.logs+'<br>');
					$('#recover-progress').html('Processed: <strong>'+d.total_processed+' / '+d.total_posts+'</strong>');
					if (d.has_more){ setTimeout(function(){ runBatch(offset + d.batch_size, dryRun, confirmation); }, 800); }
					else {
						var message = dryRun ? '✓ Dry-run selesai. Tidak ada perubahan yang ditulis.' : '✓ Recovery selesai dan perubahan telah diterapkan.';
						$('#recover-log').append('<br><strong class="br-success">'+message+'</strong>');
						$('#btn-recover').prop('disabled', false);
					}
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
			<div class="br-hero">
				<span class="br-eyebrow">Content normalization</span>
				<h1>HTML Cleanup</h1>
				<p>Preview dan rapikan markup Blogger lama, embedded ads, serta internal links yang tidak lagi sesuai.</p>
			</div>

			<div class="br-notice br-notice--warn">
				<strong>PERINGATAN OPERASI DESTRUKTIF:</strong>
				Mode Apply menulis ulang <code>post_content</code> ratusan artikel. Backup database wajib tersedia.
				<strong>Urutan pemrosesan penting:</strong>
				Malformed quotes (<code>width=""180?"</code>) diperbaiki <em>sebelum</em> AdSense removal —
				ini yang menyebabkan AdSense tidak terhapus di versi sebelumnya.
				<?php $this->render_database_backup_button( true ); ?>
			</div>
			<div class="br-notice br-notice--info">
				<strong>Internal links:</strong> Semua anchor termasuk blok manual seperti <code>BACA JUGA</code> diperiksa berdasarkan
				<code>href</code>. Plugin mencoba slug WordPress aktif terlebih dahulu, lalu rule aktif plugin Redirection.
				Link yang tidak dapat dipastikan targetnya akan dibiarkan dan dicatat sebagai unresolved.
			</div>

			<div class="br-card">
				<p class="br-section-label br-label--high">High Priority</p>
				<label><input type="checkbox" id="opt-quotes" checked> <strong>Fix malformed HTML quotes</strong> <em>(width=""180?", align=""left"")</em></label>
				<label><input type="checkbox" id="opt-adsense" checked> <strong>Remove AdSense blocks</strong> <em>(table-wrapped, div-wrapped, script tags)</em></label>
				<label><input type="checkbox" id="opt-links" checked> <strong>Fix internal Blogger links</strong> <em>(/2017/03/slug.html → WordPress permalink)</em></label>
				<label><input type="checkbox" id="opt-duplicate-titles" checked> <strong>Remove duplicate body titles</strong> <em>(hanya jika heading sama persis dengan judul WordPress)</em></label>

				<hr class="br-divider">
				<p class="br-section-label br-label--info">Standard Cleanup</p>
				<label><input type="checkbox" id="opt-related" checked> Format <code>BACA JUGA</code> blocks dengan jarak vertikal</label>
				<label><input type="checkbox" id="opt-captions" checked> Convert Blogger caption tables → <code>&lt;figure&gt;</code></label>
				<label><input type="checkbox" id="opt-trbq" checked> Remove <code>tr_bq</code> blockquote class</label>
				<label><input type="checkbox" id="opt-align" checked> Convert <code>align=center</code> → CSS</label>
				<label><input type="checkbox" id="opt-border" checked> Remove <code>border=0</code> dari images</label>
				<label><input type="checkbox" id="opt-tables" checked> Modernize table markup</label>
				<label><input type="checkbox" id="opt-fonts" checked> Remove Trebuchet font styling</label>
				<label><input type="checkbox" id="opt-empty" checked> Clean empty elements & extra breaks</label>
			</div>

			<div class="br-action-panel">
				<label class="br-toggle"><input type="checkbox" id="cleanup-dry-run" checked> Dry-run <span class="br-badge">Recommended</span></label>
				<button class="button button-primary" id="btn-cleanup">▶ Run HTML Cleanup</button>
			</div>
			<div id="cleanup-progress" class="br-progress"></div>
			<div id="cleanup-log" class="br-log"></div>
		</div>

		<script>
		jQuery(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var totals = {};
			$('#btn-cleanup').on('click', function(){
				var dryRun = $('#cleanup-dry-run').is(':checked');
				var confirmation = dryRun ? '' : prompt('Mode Apply akan menulis ulang konten artikel. Ketik APPLY untuk melanjutkan:');
				if (!dryRun && confirmation !== 'APPLY') return;
				$(this).prop('disabled', true);
				totals = {
					adsense_removed: 0,
					html_links_fixed: 0,
					captions_converted: 0,
					tables_cleaned: 0,
					quotes_fixed: 0,
					links_unresolved: 0,
					related_formatted: 0,
					titles_removed: 0
				};
				var opts = {
					cleanup_malformed_quotes: $('#opt-quotes').is(':checked'),
					cleanup_adsense:          $('#opt-adsense').is(':checked'),
					cleanup_html_links:       $('#opt-links').is(':checked'),
					cleanup_duplicate_titles: $('#opt-duplicate-titles').is(':checked'),
					cleanup_related_links:    $('#opt-related').is(':checked'),
					cleanup_caption_tables:   $('#opt-captions').is(':checked'),
					cleanup_tr_bq:            $('#opt-trbq').is(':checked'),
					cleanup_align:            $('#opt-align').is(':checked'),
					cleanup_img_border:       $('#opt-border').is(':checked'),
					cleanup_tables:           $('#opt-tables').is(':checked'),
					cleanup_blogger_fonts:    $('#opt-fonts').is(':checked'),
					cleanup_empty_elements:   $('#opt-empty').is(':checked'),
				};
				$('#cleanup-log').html('');
				$('#cleanup-progress').html(dryRun ? 'Starting dry-run…' : 'Starting APPLY mode…');
				runBatch(0, opts, dryRun, confirmation);
			});
			function runBatch(offset, opts, dryRun, confirmation){
				$.post(ajaxurl, { action:'cleanup_blogger_html', offset:offset, options:opts, dry_run:dryRun, apply_confirm:confirmation, _wpnonce:nonce }, function(r){
					if (!r.success){ $('#cleanup-log').append('<span class="br-error">Error: '+r.data+'</span>'); $('#btn-cleanup').prop('disabled', false); return; }
					var d = r.data;
					if (d.logs) $('#cleanup-log').append(d.logs);
					$.each(d.stats, function(key, value){ totals[key] += value; });
					$('#cleanup-progress').html('Processed: <strong>'+d.total_processed+' / '+d.total_posts+'</strong>');
					if (d.has_more){ setTimeout(function(){ runBatch(offset + d.batch_size, opts, dryRun, confirmation); }, 800); }
					else {
						var s = totals;
						var summary = '<br><div class="br-notice br-notice--success">'
							+ '<strong>'+(dryRun ? 'Dry-run selesai, tidak ada perubahan ditulis.' : 'Apply selesai, perubahan telah ditulis.')+'</strong><br>'
							+ '• AdSense dihapus: '+s.adsense_removed+' artikel<br>'
							+ '• HTML links fixed: '+s.html_links_fixed+' artikel<br>'
							+ '• HTML links unresolved: '+s.links_unresolved+' link<br>'
							+ '• Duplicate body titles removed: '+s.titles_removed+'<br>'
							+ '• BACA JUGA blocks formatted: '+s.related_formatted+'<br>'
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
			<div class="br-hero">
				<span class="br-eyebrow">Redirect management</span>
				<h1>Redirect Migrator</h1>
				<p>Audit, export, dan migrasikan rules dari plugin Redirection ke Yoast SEO Premium.</p>
			</div>
			<div class="br-notice br-notice--warn">
				<strong>PERINGATAN:</strong> Mode Apply menulis konfigurasi redirect Yoast Premium.
				Jalankan dry-run dan export CSV terlebih dahulu. Jangan menonaktifkan Redirection sebelum hasil redirect diuji.
				<?php $this->render_database_backup_button( true ); ?>
			</div>

			<?php if ( ! $yoast_active ) : ?>
			<div class="br-notice br-notice--warn">
				⚠️ Yoast SEO Premium tidak terdeteksi aktif. Import ke Yoast tidak akan berfungsi, tapi kamu masih bisa export CSV.
			</div>
			<?php endif; ?>

			<div class="br-action-panel">
				<label class="br-toggle"><input type="checkbox" id="redirect-dry-run" checked> Dry-run <span class="br-badge">Recommended</span></label>
				<button class="button button-primary" id="btn-load">📂 Load Rules dari Redirection</button>
				<button class="button" id="btn-csv" disabled>⬇ Export CSV</button>
				<button class="button button-primary" id="btn-yoast" disabled <?php echo $yoast_active ? '' : 'title="Yoast Premium tidak aktif"'; ?>>
					▶ Run Yoast Migration
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
				var dryRun = $('#redirect-dry-run').is(':checked');
				var confirmation = dryRun ? '' : prompt('Mode Apply akan menulis '+allRules.length+' redirect ke Yoast. Ketik APPLY untuk melanjutkan:');
				if (!dryRun && confirmation !== 'APPLY') return;
				$(this).prop('disabled', true).text('Importing…');
				$.post(ajaxurl, { action:'import_to_yoast', rules:JSON.stringify(allRules), dry_run:dryRun, apply_confirm:confirmation, _wpnonce:nonce }, function(r){
					$('#btn-yoast').prop('disabled', false).text('▶ Run Yoast Migration');
					if (!r.success){ alert('Error: '+r.data); return; }
					var d = r.data;
					$('#redirect-status').html('<span class="br-success"><strong>✅ '+d.message+'</strong></span>');
					if (!d.dry_run && d.imported > 0){
						$('#redirect-status').append('<br><small>Uji redirect hasil import sebelum mempertimbangkan menonaktifkan plugin Redirection.</small>');
					}
				}, 'json');
			});
		});
		</script>
		<?php
	}

	/**
	 * Render the authenticated database backup download form.
	 *
	 * @param bool $compact Whether to use compact spacing inside a warning.
	 */
	private function render_database_backup_button( $compact = false ) {
		?>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			class="<?php echo $compact ? 'br-backup-form br-backup-form--compact' : 'br-backup-form'; ?>"
		>
			<input type="hidden" name="action" value="blogger_recovery_database_backup">
			<?php wp_nonce_field( 'blogger_recovery_database_backup' ); ?>
			<button type="submit" class="button button-primary">
				Download Full Database Backup
			</button>
		</form>
		<?php
	}
}
