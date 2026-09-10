<?php
/**
 * Plugin Name: Ace Revisions
 * Plugin URI: https://github.com/AceMedia/Ace-Revisions
 * Description: Revision history for post meta and taxonomy terms. Tracks who changed what, when and from where, with restore.
 * Version: 0.1.0
 * Author: AceMedia
 * Author URI: https://acemedia.ninja
 * Text Domain: ace-revisions
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Ace_Revisions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Bump on every release: drives asset cache-busting and the options migration check.
define( 'ACE_REVISIONS_VERSION', '0.1.0' );
define( 'ACE_REVISIONS_FILE', __FILE__ );
define( 'ACE_REVISIONS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ACE_REVISIONS_URL', plugin_dir_url( __FILE__ ) );
define( 'ACE_REVISIONS_BASENAME', plugin_basename( __FILE__ ) );

require_once ACE_REVISIONS_PATH . 'includes/class-ace-revisions-settings.php';
require_once ACE_REVISIONS_PATH . 'includes/class-ace-revisions.php';

if ( is_admin() ) {
    require_once ACE_REVISIONS_PATH . 'includes/admin/class-ace-revisions-admin.php';
}

register_activation_hook( __FILE__, [ 'Ace_Revisions', 'activate' ] );

add_action( 'plugins_loaded', [ 'Ace_Revisions', 'instance' ] );
