<?php
/**
 * Plugin Name:       Content Tracker Sync
 * Plugin URI:        https://example.com/content-tracker-sync
 * Description:       Sync WordPress post data (title, slug, Yoast SEO fields, tags) to a Google Sheets editorial tracker with a single click.
 * Version:           2.5.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Krish Goswami
 * Author URI:        mailto:krrishyogi18@gmail.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       content-tracker-sync
 * Domain Path:       /languages
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*--------------------------------------------------------------
 * Constants
 *------------------------------------------------------------*/
define( 'CTS_VERSION',    '2.5.1' );
define( 'CTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*--------------------------------------------------------------
 * Autoload plugin classes
 *------------------------------------------------------------*/
require_once CTS_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once CTS_PLUGIN_DIR . 'includes/class-google-sheets-service.php';
require_once CTS_PLUGIN_DIR . 'includes/class-sync-handler.php';
require_once CTS_PLUGIN_DIR . 'includes/class-plugin-updater.php';

/*--------------------------------------------------------------
 * Boot the plugin on `plugins_loaded`
 *------------------------------------------------------------*/
add_action( 'plugins_loaded', 'cts_init' );

/**
 * Initialise plugin components.
 *
 * @return void
 */
function cts_init() {

    // Self-hosted GitHub updater (runs on all requests for auto-update support).
    new CTS_Plugin_Updater();

    // Admin settings page.
    if ( is_admin() ) {
        new CTS_Admin_Settings();
        new CTS_Sync_Handler();
    }
}

/*--------------------------------------------------------------
 * Activation hook — seed default options
 *------------------------------------------------------------*/
register_activation_hook( __FILE__, 'cts_activate' );

/**
 * Plugin activation callback.
 *
 * @return void
 */
function cts_activate() {

    // Create default option entries if they don't already exist.
    add_option( 'cts_credentials_json', '' );
    add_option( 'cts_spreadsheet_id',   '' );
    add_option( 'cts_sheet_name',        'Sheet1' );
}

/*--------------------------------------------------------------
 * Deactivation hook (clean-up if needed in the future)
 *------------------------------------------------------------*/
register_deactivation_hook( __FILE__, 'cts_deactivate' );

/**
 * Plugin deactivation callback.
 *
 * @return void
 */
function cts_deactivate() {
    // Clean up the cached access token transient.
    $spreadsheet_id = get_option( 'cts_spreadsheet_id', '' );
    if ( $spreadsheet_id ) {
        delete_transient( 'cts_token_' . substr( md5( $spreadsheet_id ), 0, 12 ) );
    }
}
