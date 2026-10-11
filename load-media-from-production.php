<?php
/**
 * Plugin Name:       Load Media from Production
 * Plugin URI:        https://wordpress.org/plugins/load-media-from-production/
 * Description:       Fix missing images on local and staging sites by loading media from your live site.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Andrew Murray
 * Author URI:        https://ajmmedia.co.uk/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       load-media-from-production
 *
 * @package LoadMediaFromProduction
 */

defined( 'ABSPATH' ) || exit;

define( 'LMFP_VERSION', '1.0.0' );
define( 'LMFP_FILE', __FILE__ );
define( 'LMFP_DIR', plugin_dir_path( __FILE__ ) );
define( 'LMFP_URL', plugin_dir_url( __FILE__ ) );
define( 'LMFP_DONATE_URL', 'https://ko-fi.com/ajmmedia' );

require_once LMFP_DIR . 'includes/class-settings.php';
require_once LMFP_DIR . 'includes/class-environments.php';
require_once LMFP_DIR . 'includes/class-proxy.php';
require_once LMFP_DIR . 'includes/class-admin.php';

add_action( 'plugins_loaded', array( LMFP\Proxy::class, 'init' ) );
add_action( 'plugins_loaded', array( LMFP\Admin::class, 'init' ) );
