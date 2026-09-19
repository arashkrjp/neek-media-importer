<?php
/**
 * Plugin Name: Neek Media Importer
 * Plugin URI: https://arashkrajabpour.ir/neek-media-importer/
 * Description: Transfer remote images, video, and audio directly into the WordPress Media Library, with optional image conversion and resizing.
 * Version: 1.0.1
 * Author: Arashk Rajabpour
 * Author URI: https://arashkrajabpour.ir
 * Text Domain: neek-media-importer
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'NEEK_MEDIA_IMPORTER_VERSION', '1.0.1' );
define( 'NEEK_MEDIA_IMPORTER_FILE', __FILE__ );
define( 'NEEK_MEDIA_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'NEEK_MEDIA_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once NEEK_MEDIA_IMPORTER_PATH . 'includes/class-neek-media-importer.php';

Neek_Media_Importer::instance();
