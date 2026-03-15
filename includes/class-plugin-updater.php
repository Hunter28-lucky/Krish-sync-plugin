<?php
/**
 * Self-hosted GitHub Plugin Updater
 *
 * Checks GitHub Releases for new versions and silently auto-updates
 * the plugin without any user interaction.
 *
 * @package ContentTrackerSync
 * @since   2.4.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CTS_Plugin_Updater {

    /**
     * GitHub repository owner.
     *
     * @var string
     */
    private $github_username = 'Hunter28-lucky';

    /**
     * GitHub repository name.
     *
     * @var string
     */
    private $github_repo = 'Krish-sync-plugin';

    /**
     * Plugin slug (directory name).
     *
     * @var string
     */
    private $plugin_slug = 'content-tracker-sync';

    /**
     * Full plugin basename (e.g. content-tracker-sync/content-tracker-sync.php).
     *
     * @var string
     */
    private $plugin_basename;

    /**
     * Current plugin version.
     *
     * @var string
     */
    private $current_version;

    /**
     * Transient key for caching the update check.
     *
     * @var string
     */
    private $cache_key = 'cts_github_update_cache';

    /**
     * How long to cache the update check (in seconds). Default: 6 hours.
     *
     * @var int
     */
    private $cache_duration = 21600;

    /**
     * Constructor — hook into WordPress update system.
     */
    public function __construct() {
        $this->plugin_basename = CTS_PLUGIN_BASENAME;
        $this->current_version = CTS_VERSION;

        // Check for updates when WordPress checks.
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

        // Provide plugin info for the "View Details" popup.
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

        // Force silent auto-update for this plugin (no user click needed).
        add_filter( 'auto_update_plugin', array( $this, 'force_auto_update' ), 10, 2 );

        // Ensure the updater correctly renames the extracted folder.
        add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );

        // Clear our cache when WordPress clears its own update cache (e.g. "Check Again").
        add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cache' ) );

        // Add a "Check for updates" link on the plugins page.
        add_filter( 'plugin_action_links_' . CTS_PLUGIN_BASENAME, array( $this, 'add_check_update_link' ) );

        // Handle the force-check request.
        add_action( 'admin_init', array( $this, 'handle_force_check' ) );
    }

    /**
     * Query GitHub Releases API for the latest release info.
     *
     * @return object|false Release data or false on failure.
     */
    private function get_remote_release_info() {

        // Return cached data if available.
        $cached = get_transient( $this->cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_username,
            $this->github_repo
        );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $body ) || ! isset( $body->tag_name ) ) {
            return false;
        }

        // Build a clean release object.
        $release = (object) array(
            'version'     => ltrim( $body->tag_name, 'vV' ), // "v2.4.0" → "2.4.0"
            'zip_url'     => $body->zipball_url,
            'changelog'   => isset( $body->body ) ? $body->body : '',
            'published'   => isset( $body->published_at ) ? $body->published_at : '',
            'html_url'    => isset( $body->html_url ) ? $body->html_url : '',
        );

        // If a .zip asset is attached to the release, prefer that over zipball.
        if ( ! empty( $body->assets ) ) {
            foreach ( $body->assets as $asset ) {
                if ( '.zip' === substr( $asset->name, -4 ) ) {
                    $release->zip_url = $asset->browser_download_url;
                    break;
                }
            }
        }

        // Cache for 12 hours.
        set_transient( $this->cache_key, $release, $this->cache_duration );

        return $release;
    }

    /**
     * Check for updates and inject our plugin into the update transient.
     *
     * @param  object $transient WordPress update_plugins transient.
     * @return object Modified transient.
     */
    public function check_for_update( $transient ) {

        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_remote_release_info();

        if ( false === $release ) {
            return $transient;
        }

        // Compare versions.
        if ( version_compare( $release->version, $this->current_version, '>' ) ) {

            $update = (object) array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $release->version,
                'url'         => $release->html_url,
                'package'     => $release->zip_url,
                'icons'       => array(),
                'banners'     => array(),
                'tested'      => '', // WordPress compatibility.
                'requires'    => '5.8',
                'requires_php'=> '7.4',
            );

            $transient->response[ $this->plugin_basename ] = $update;
        } else {
            // No update available — add to no_update list.
            $transient->no_update[ $this->plugin_basename ] = (object) array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $this->current_version,
                'url'         => '',
                'package'     => '',
            );
        }

        return $transient;
    }

    /**
     * Provide plugin information for the "View Details" popup in WordPress.
     *
     * @param  false|object|array $result The result object or array.
     * @param  string             $action The API action being performed.
     * @param  object             $args   Plugin API arguments.
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {

        if ( 'plugin_information' !== $action || $this->plugin_slug !== $args->slug ) {
            return $result;
        }

        $release = $this->get_remote_release_info();

        if ( false === $release ) {
            return $result;
        }

        $info = (object) array(
            'name'            => 'Content Tracker Sync',
            'slug'            => $this->plugin_slug,
            'version'         => $release->version,
            'author'          => '<a href="mailto:krrishyogi18@gmail.com">Krish Goswami</a>',
            'homepage'        => sprintf( 'https://github.com/%s/%s', $this->github_username, $this->github_repo ),
            'requires'        => '5.8',
            'requires_php'    => '7.4',
            'downloaded'      => 0,
            'last_updated'    => $release->published,
            'download_link'   => $release->zip_url,
            'sections'        => array(
                'description' => 'Sync WordPress post data (title, slug, Yoast SEO fields, tags) to a Google Sheets editorial tracker with a single click.',
                'changelog'   => nl2br( esc_html( $release->changelog ) ),
            ),
        );

        return $info;
    }

    /**
     * Force this plugin to always auto-update silently.
     *
     * @param  bool|null $update Whether to update the plugin.
     * @param  object    $item   The plugin update object.
     * @return bool
     */
    public function force_auto_update( $update, $item ) {
        if ( isset( $item->slug ) && $this->plugin_slug === $item->slug ) {
            return true; // Always auto-update.
        }
        return $update;
    }

    /**
     * Fix the directory name after extraction.
     *
     * GitHub's zipball creates a folder like "Hunter28-lucky-Krish-sync-plugin-abc1234".
     * We need to rename it to "content-tracker-sync" for WordPress to recognise it.
     *
     * @param  string       $source        File source location.
     * @param  string       $remote_source Remote source file location.
     * @param  WP_Upgrader  $upgrader      WP_Upgrader instance.
     * @param  array        $hook_extra    Extra arguments passed to hooked filters.
     * @return string|WP_Error
     */
    public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {

        // Only process our plugin.
        if ( ! isset( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {
            return $source;
        }

        global $wp_filesystem;

        $correct_dir = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

        // If the extracted directory is already correct, do nothing.
        if ( trailingslashit( $source ) === $correct_dir ) {
            return $source;
        }

        // Rename the extracted directory.
        if ( $wp_filesystem->move( $source, $correct_dir, true ) ) {
            return $correct_dir;
        }

        return new WP_Error(
            'rename_failed',
            __( 'Content Tracker Sync: Could not rename the update directory.', 'content-tracker-sync' )
        );
    }

    /**
     * Clear the cached release data.
     * Called automatically when WordPress refreshes its update check,
     * or manually via the "Check for updates" link on the plugins page.
     *
     * @return void
     */
    public static function clear_cache() {
        delete_transient( 'cts_github_update_cache' );
    }

    /**
     * Add a "Check for updates" action link on the Plugins page.
     *
     * @param  array $links Existing action links.
     * @return array Modified action links.
     */
    public function add_check_update_link( $links ) {
        $url = wp_nonce_url(
            admin_url( 'plugins.php?cts_force_check=1' ),
            'cts_force_update_check'
        );
        $links['cts-check-update'] = '<a href="' . esc_url( $url ) . '">Check for updates</a>';
        return $links;
    }

    /**
     * Handle the force update check request from the plugins page link.
     *
     * @return void
     */
    public function handle_force_check() {
        if ( ! isset( $_GET['cts_force_check'] ) || '1' !== $_GET['cts_force_check'] ) {
            return;
        }

        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        // Verify the nonce.
        check_admin_referer( 'cts_force_update_check' );

        // Clear our cache.
        self::clear_cache();

        // Force WordPress to re-check all plugin updates.
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        // Redirect back to plugins page with a notice.
        wp_safe_redirect( admin_url( 'plugins.php?cts_checked=1' ) );
        exit;
    }
}
