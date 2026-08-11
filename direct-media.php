<?php
/**
 * Plugin Name: Direct Media
 * Plugin URI: https://arashkrajabpour.ir
 * Description: Transfer remote images, video, and audio directly into the WordPress Media Library, with optional image conversion and resizing.
 * Version: 1.0.1
 * Author: Arashk Rajabpour
 * Author URI: https://arashkrajabpour.ir
 * Text Domain: direct-media
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'DIRECT_MEDIA_VERSION', '1.0.1' );
define( 'DIRECT_MEDIA_FILE', __FILE__ );
define( 'DIRECT_MEDIA_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIRECT_MEDIA_URL', plugin_dir_url( __FILE__ ) );

require_once DIRECT_MEDIA_PATH . 'includes/class-direct-media.php';

Direct_Media::instance();
