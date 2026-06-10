<?php
/**
 * Plugin Name:       Blogger Recovery Tools
 * Plugin URI:        https://chrisnov.com/plugins/blogger-recovery/
 * Description:       Tools lengkap untuk recovery migrasi dari Blogger ke WordPress.
 *                    Menangani gambar Blogger (dua kondisi URL), AdSense tertanam,
 *                    internal link format .html, dan migrasi redirect rules ke Yoast.
 * Version:           2.3.1
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

define( 'BLOGGER_RECOVERY_VERSION', '2.3.1' );
define( 'BLOGGER_RECOVERY_PATH', plugin_dir_path( __FILE__ ) );
define( 'BLOGGER_RECOVERY_URL', plugin_dir_url( __FILE__ ) );

// Load semua class
require_once BLOGGER_RECOVERY_PATH . 'includes/class-detector.php';
require_once BLOGGER_RECOVERY_PATH . 'includes/class-image-recovery.php';
require_once BLOGGER_RECOVERY_PATH . 'includes/class-html-cleanup.php';
require_once BLOGGER_RECOVERY_PATH . 'includes/class-redirect-migrator.php';
require_once BLOGGER_RECOVERY_PATH . 'includes/class-database-backup.php';
require_once BLOGGER_RECOVERY_PATH . 'includes/class-plugin.php';

// Boot
new Blogger_Recovery_Plugin();
