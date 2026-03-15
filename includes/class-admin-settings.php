<?php
/**
 * Admin Settings for Content Tracker Sync.
 *
 * Registers the settings page, fields, and handles credential storage.
 *
 * @package ContentTrackerSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CTS_Admin_Settings
 *
 * Creates a settings page under Settings → Content Tracker Sync.
 */
class CTS_Admin_Settings {

    /**
     * Option group name.
     *
     * @var string
     */
    const OPTION_GROUP = 'cts_settings_group';

    /**
     * Constructor — register hooks.
     */
    public function __construct() {
        add_action( 'admin_menu',    array( $this, 'add_settings_page' ) );
        add_action( 'admin_init',    array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_cts_test_connection', array( $this, 'handle_test_connection' ) );
    }

    /*--------------------------------------------------------------
     * Menu & Page
     *------------------------------------------------------------*/

    /**
     * Add an options page under the Settings menu.
     *
     * @return void
     */
    public function add_settings_page() {
        add_options_page(
            __( 'Content Tracker Sync', 'content-tracker-sync' ),
            __( 'Content Tracker Sync', 'content-tracker-sync' ),
            'manage_options',
            'content-tracker-sync',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Render the settings page via external template.
     *
     * @return void
     */
    public function render_settings_page() {
        // Capability check.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        include CTS_PLUGIN_DIR . 'admin/admin-page.php';
    }

    /*--------------------------------------------------------------
     * Register Settings & Fields
     *------------------------------------------------------------*/

    /**
     * Register settings, sections, and fields.
     *
     * @return void
     */
    public function register_settings() {

        // ----- Section -----
        add_settings_section(
            'cts_main_section',
            __( 'Google Sheets Configuration', 'content-tracker-sync' ),
            array( $this, 'section_description' ),
            'content-tracker-sync'
        );

        // ----- Credentials JSON -----
        register_setting( self::OPTION_GROUP, 'cts_credentials_json', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_credentials_json' ),
        ) );

        add_settings_field(
            'cts_credentials_json',
            __( 'Service Account Credentials JSON', 'content-tracker-sync' ),
            array( $this, 'render_credentials_field' ),
            'content-tracker-sync',
            'cts_main_section'
        );

        // ----- Spreadsheet ID -----
        register_setting( self::OPTION_GROUP, 'cts_spreadsheet_id', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        add_settings_field(
            'cts_spreadsheet_id',
            __( 'Spreadsheet ID', 'content-tracker-sync' ),
            array( $this, 'render_spreadsheet_id_field' ),
            'content-tracker-sync',
            'cts_main_section'
        );

        // ----- Sheet Name -----
        register_setting( self::OPTION_GROUP, 'cts_sheet_name', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Sheet1',
        ) );

        add_settings_field(
            'cts_sheet_name',
            __( 'Sheet Name (Tab)', 'content-tracker-sync' ),
            array( $this, 'render_sheet_name_field' ),
            'content-tracker-sync',
            'cts_main_section'
        );
    }

    /**
     * Section description text.
     *
     * @return void
     */
    public function section_description() {
        echo '<p>' . esc_html__(
            'Connect your Google Sheet by uploading a Service Account credentials JSON file and entering your spreadsheet details below.',
            'content-tracker-sync'
        ) . '</p>';
    }

    /*--------------------------------------------------------------
     * Field Renderers
     *------------------------------------------------------------*/

    /**
     * Render the credentials JSON textarea.
     *
     * @return void
     */
    public function render_credentials_field() {
        $value = get_option( 'cts_credentials_json', '' );
        $has_credentials = ! empty( $value );
        ?>
        <textarea
            id="cts_credentials_json"
            name="cts_credentials_json"
            rows="6"
            cols="60"
            class="large-text code"
            placeholder="<?php esc_attr_e( 'Paste the full JSON content of your Service Account key file here…', 'content-tracker-sync' ); ?>"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <?php if ( $has_credentials ) : ?>
            <p class="description" style="color: green;">
                ✓ <?php esc_html_e( 'Credentials are saved.', 'content-tracker-sync' ); ?>
            </p>
        <?php else : ?>
            <p class="description">
                <?php esc_html_e( 'Paste the contents of the JSON key file you downloaded from Google Cloud Console.', 'content-tracker-sync' ); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render the Spreadsheet ID text field.
     *
     * @return void
     */
    public function render_spreadsheet_id_field() {
        $value = get_option( 'cts_spreadsheet_id', '' );
        ?>
        <input
            type="text"
            id="cts_spreadsheet_id"
            name="cts_spreadsheet_id"
            value="<?php echo esc_attr( $value ); ?>"
            class="regular-text"
            placeholder="<?php esc_attr_e( 'e.g. 1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms', 'content-tracker-sync' ); ?>"
        />
        <p class="description">
            <?php esc_html_e( 'Find this in the Google Sheet URL between /d/ and /edit.', 'content-tracker-sync' ); ?>
        </p>
        <?php
    }

    /**
     * Render the Sheet Name text field.
     *
     * @return void
     */
    public function render_sheet_name_field() {
        $value = get_option( 'cts_sheet_name', 'Sheet1' );
        ?>
        <input
            type="text"
            id="cts_sheet_name"
            name="cts_sheet_name"
            value="<?php echo esc_attr( $value ); ?>"
            class="regular-text"
            placeholder="Sheet1"
        />
        <p class="description">
            <?php esc_html_e( 'The name of the tab/sheet inside your spreadsheet.', 'content-tracker-sync' ); ?>
        </p>
        <?php
    }

    /*--------------------------------------------------------------
     * Sanitisation
     *------------------------------------------------------------*/

    /**
     * Sanitise the credentials JSON input.
     *
     * @param  string $input Raw input.
     * @return string Sanitised JSON string.
     */
    public function sanitize_credentials_json( $input ) {

        $input = trim( $input );

        if ( empty( $input ) ) {
            return '';
        }

        // Validate that it is valid JSON.
        $decoded = json_decode( $input, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            add_settings_error(
                'cts_credentials_json',
                'invalid_json',
                __( 'The credentials JSON is not valid JSON. Please paste the correct file contents.', 'content-tracker-sync' ),
                'error'
            );
            // Return the previously saved value.
            return get_option( 'cts_credentials_json', '' );
        }

        // Basic check: ensure it looks like a service account key.
        if ( empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
            add_settings_error(
                'cts_credentials_json',
                'missing_fields',
                __( 'The credentials JSON does not contain required fields (client_email, private_key). Please use a Service Account key.', 'content-tracker-sync' ),
                'error'
            );
            return get_option( 'cts_credentials_json', '' );
        }

        return $input;
    }

    /*--------------------------------------------------------------
     * Enqueue Assets
     *------------------------------------------------------------*/

    /**
     * Enqueue admin styles and scripts on relevant screens.
     *
     * @param  string $hook_suffix The current admin page hook suffix.
     * @return void
     */
    public function enqueue_admin_assets( $hook_suffix ) {

        // Settings page assets.
        if ( 'settings_page_content-tracker-sync' === $hook_suffix ) {
            wp_enqueue_style(
                'cts-admin-settings',
                CTS_PLUGIN_URL . 'assets/admin.css',
                array(),
                CTS_VERSION
            );

            wp_enqueue_script( 'jquery' );
            wp_localize_script( 'jquery', 'ctsSettings', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'cts_test_connection' ),
            ) );
        }
    }

    /*--------------------------------------------------------------
     * Test Connection AJAX Handler
     *------------------------------------------------------------*/

    /**
     * Handle the AJAX test connection request.
     *
     * @return void
     */
    public function handle_test_connection() {

        check_ajax_referer( 'cts_test_connection', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $credentials_json = get_option( 'cts_credentials_json', '' );
        $spreadsheet_id   = get_option( 'cts_spreadsheet_id', '' );
        $sheet_name        = get_option( 'cts_sheet_name', 'Sheet1' );

        if ( empty( $credentials_json ) ) {
            wp_send_json_error( array( 'message' => 'Credentials JSON is empty. Paste the JSON key file contents and save first.' ) );
        }

        if ( empty( $spreadsheet_id ) ) {
            wp_send_json_error( array( 'message' => 'Spreadsheet ID is empty. Enter the ID from your Google Sheet URL and save first.' ) );
        }

        if ( empty( $sheet_name ) ) {
            wp_send_json_error( array( 'message' => 'Sheet name is empty. Enter the exact tab name and save first.' ) );
        }

        $credentials = json_decode( $credentials_json, true );

        if ( ! $credentials ) {
            wp_send_json_error( array( 'message' => 'Credentials JSON is invalid. Clear the field, re-paste the JSON, and save.' ) );
        }

        $sheets = new CTS_Google_Sheets_Service( $credentials, $spreadsheet_id, $sheet_name );
        $values = $sheets->get_values( $sheet_name . '!A1:A1' );

        if ( is_wp_error( $values ) ) {
            wp_send_json_error( array( 'message' => $values->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => 'Connection successful! Your Google Sheet is reachable and the sheet tab "' . $sheet_name . '" exists.',
        ) );
    }
}
