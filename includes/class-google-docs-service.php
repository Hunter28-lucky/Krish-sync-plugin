<?php
/**
 * Google Docs Service for Content Tracker Sync.
 *
 * Creates and updates Google Docs from WordPress post content.
 *
 * Uses a Google Apps Script Web App deployed under the user's Google account
 * to create/update documents. This avoids service-account storage-quota
 * issues (service accounts on free Google Cloud projects have 0 GB quota).
 *
 * The Apps Script runs as the user, so docs are created under the user's
 * Google Drive storage — no quota problems.
 *
 * @package ContentTrackerSync
 * @since   3.3.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CTS_Google_Docs_Service
 */
class CTS_Google_Docs_Service {

    /**
     * Google Apps Script Web App URL.
     *
     * @var string
     */
    private $webapp_url;

    /**
     * Decoded service account credentials (kept for backwards compat).
     *
     * @var array
     */
    private $credentials;

    /**
     * Target Google Drive folder ID (used by Apps Script).
     *
     * @var string
     */
    private $folder_id;

    /**
     * Constructor.
     *
     * @param array  $credentials Decoded service account JSON (not used for doc creation anymore).
     * @param string $folder_id   Google Drive folder ID.
     */
    public function __construct( array $credentials, string $folder_id ) {
        $this->credentials = $credentials;
        $this->folder_id   = $folder_id;
        $this->webapp_url  = get_option( 'cts_apps_script_url', '' );
    }

    /*--------------------------------------------------------------
     * Public API
     *------------------------------------------------------------*/

    /**
     * Create or update a Google Doc from post content.
     *
     * @param  int    $post_id   WordPress Post ID.
     * @param  string $title     Post title.
     * @param  string $content   Post content (HTML).
     * @param  string $doc_id    Existing Google Doc ID (empty = create new).
     * @return array|WP_Error    Array with 'doc_id' and 'doc_url', or WP_Error.
     */
    public function sync_post_to_doc( int $post_id, string $title, string $content, string $doc_id = '' ) {

        if ( empty( $this->webapp_url ) ) {
            return new WP_Error(
                'cts_docs_error',
                'Apps Script Web App URL is not configured. Go to Settings → Content Tracker Sync and add the URL.'
            );
        }

        $doc_title = $post_id . $title;

        // Strip HTML to plain text.
        $plain_text = $this->html_to_plain_text( $content );

        // Determine action.
        $action = ! empty( $doc_id ) ? 'update' : 'create';

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[CTS-DOCS] Action: ' . $action . ' | Title: ' . $doc_title . ' | Doc ID: ' . $doc_id );
            error_log( '[CTS-DOCS] Apps Script URL: ' . $this->webapp_url );
        }

        // Build request payload.
        $payload = array(
            'action'  => $action,
            'title'   => $doc_title,
            'content' => $plain_text,
        );

        if ( ! empty( $doc_id ) ) {
            $payload['doc_id'] = $doc_id;
        }

        // Call the Google Apps Script Web App.
        $response = wp_remote_post( $this->webapp_url, array(
            'timeout'   => 60,
            'headers'   => array(
                'Content-Type' => 'application/json',
            ),
            'body'      => wp_json_encode( $payload ),
            'sslverify' => true,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'cts_docs_error',
                'Failed to reach Apps Script: ' . $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[CTS-DOCS] Apps Script response code: ' . $code );
            error_log( '[CTS-DOCS] Apps Script response body: ' . substr( $body, 0, 500 ) );
        }

        // Apps Script redirects (302) on POST — WordPress follows it and returns 200.
        // But if we get a non-200 code, something went wrong.
        if ( $code < 200 || $code >= 400 ) {
            return new WP_Error(
                'cts_docs_error',
                'Apps Script returned HTTP ' . $code . '. Check your Web App URL and redeployment.'
            );
        }

        $json = json_decode( $body, true );

        if ( empty( $json ) ) {
            // Google Apps Script might return HTML on error (e.g., permission page).
            if ( strpos( $body, 'accounts.google.com' ) !== false || strpos( $body, 'Sign in' ) !== false ) {
                return new WP_Error(
                    'cts_docs_error',
                    'Apps Script requires re-authorization. Open the Apps Script editor, run doPost manually once, and accept permissions.'
                );
            }
            return new WP_Error(
                'cts_docs_error',
                'Apps Script returned an empty or invalid response. Body: ' . substr( $body, 0, 200 )
            );
        }

        if ( isset( $json['success'] ) && $json['success'] === true ) {
            return array(
                'doc_id'  => $json['doc_id'],
                'doc_url' => $json['doc_url'],
            );
        }

        // Error from Apps Script.
        $error_msg = isset( $json['error'] ) ? $json['error'] : 'Unknown Apps Script error';
        return new WP_Error( 'cts_docs_error', $error_msg );
    }

    /*--------------------------------------------------------------
     * Content Helpers
     *------------------------------------------------------------*/

    /**
     * Convert HTML content to plain text for Google Docs.
     *
     * Preserves paragraph breaks and basic structure.
     *
     * @param  string $html Raw HTML content.
     * @return string Plain text.
     */
    private function html_to_plain_text( string $html ): string {

        // Apply WordPress content filters first.
        $html = apply_filters( 'the_content', $html );

        // Remove script and style tags.
        $html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
        $html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );

        // Convert common block elements to newlines.
        $html = preg_replace( '/<\/(p|div|h[1-6]|li|tr|blockquote)>/i', "\n\n", $html );
        $html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
        $html = preg_replace( '/<li[^>]*>/i', "• ", $html );

        // Strip remaining tags.
        $text = wp_strip_all_tags( $html );

        // Decode HTML entities.
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

        // Normalise whitespace: collapse multiple blank lines to two newlines.
        $text = preg_replace( '/\n{3,}/', "\n\n", $text );

        // Trim leading/trailing whitespace from each line.
        $lines = explode( "\n", $text );
        $lines = array_map( 'trim', $lines );
        $text  = implode( "\n", $lines );

        return trim( $text );
    }
}
