<?php
/**
 * Google Docs/Drive Service for Content Tracker Sync.
 *
 * Creates and updates Google Docs from WordPress post content.
 * Uses Google Drive API v3 for file management and upload.
 * Reuses the same service account credentials as the Sheets service.
 *
 * @package ContentTrackerSync
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class CTS_Google_Docs_Service
 */
class CTS_Google_Docs_Service {

    /**
     * Google OAuth2 token endpoint.
     *
     * @var string
     */
    const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /**
     * Google Drive API v3 base URL.
     *
     * @var string
     */
    const DRIVE_API_BASE = 'https://www.googleapis.com/drive/v3';

    /**
     * Google Drive API upload URL.
     *
     * @var string
     */
    const DRIVE_UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    /**
     * Decoded service account credentials.
     *
     * @var array
     */
    private $credentials;

    /**
     * Target Google Drive folder ID.
     *
     * @var string
     */
    private $folder_id;

    /**
     * Cached access token.
     *
     * @var string|null
     */
    private $access_token = null;

    /**
     * Constructor.
     *
     * @param array  $credentials Decoded service account JSON.
     * @param string $folder_id   Google Drive folder ID.
     */
    public function __construct( array $credentials, string $folder_id ) {
        $this->credentials = $credentials;
        $this->folder_id   = $folder_id;
    }

    /*--------------------------------------------------------------
     * Public API
     *------------------------------------------------------------*/

    /**
     * Create or update a Google Doc from post content.
     *
     * If a doc_id is provided (from post meta), the existing doc is updated.
     * Otherwise, a new doc is created in the configured Drive folder.
     *
     * @param  int    $post_id   WordPress Post ID.
     * @param  string $title     Post title (used for doc name).
     * @param  string $content   Post content (HTML).
     * @param  string $doc_id    Existing Google Doc ID (empty = create new).
     * @return array|WP_Error    Array with 'doc_id' and 'doc_url', or WP_Error.
     */
    public function sync_post_to_doc( int $post_id, string $title, string $content, string $doc_id = '' ) {

        // Build the doc title: {PostID}{Title}
        $doc_title = $post_id . $title;

        // Wrap content in minimal HTML for clean conversion.
        $html_content = $this->prepare_html( $content );

        if ( ! empty( $doc_id ) ) {
            // Update existing doc.
            $result = $this->update_doc( $doc_id, $doc_title, $html_content );
        } else {
            // Create new doc.
            $result = $this->create_doc( $doc_title, $html_content );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $file_id = isset( $result['id'] ) ? $result['id'] : $doc_id;

        return array(
            'doc_id'  => $file_id,
            'doc_url' => 'https://docs.google.com/document/d/' . $file_id . '/edit',
        );
    }

    /**
     * Create a new Google Doc in the configured folder.
     *
     * Uses multipart upload to set metadata + content in one request.
     *
     * @param  string $title        Doc title.
     * @param  string $html_content HTML content.
     * @return array|WP_Error       API response or error.
     */
    private function create_doc( string $title, string $html_content ) {

        $token = $this->get_access_token();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $boundary = 'cts_boundary_' . wp_generate_password( 12, false );

        // File metadata.
        $metadata = wp_json_encode( array(
            'name'     => $title,
            'mimeType' => 'application/vnd.google-apps.document',
            'parents'  => array( $this->folder_id ),
        ) );

        // Build multipart body.
        $body  = '--' . $boundary . "\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $html_content . "\r\n";
        $body .= '--' . $boundary . '--';

        $url = self::DRIVE_UPLOAD_URL . '?uploadType=multipart';

        $response = wp_remote_post( $url, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'multipart/related; boundary=' . $boundary,
            ),
            'body'    => $body,
        ) );

        return $this->parse_response( $response );
    }

    /**
     * Update an existing Google Doc with new content.
     *
     * @param  string $doc_id       Existing Google Doc file ID.
     * @param  string $title        Updated title.
     * @param  string $html_content Updated HTML content.
     * @return array|WP_Error       API response or error.
     */
    private function update_doc( string $doc_id, string $title, string $html_content ) {

        $token = $this->get_access_token();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $boundary = 'cts_boundary_' . wp_generate_password( 12, false );

        // File metadata (title update only, no parents needed for update).
        $metadata = wp_json_encode( array(
            'name' => $title,
        ) );

        // Build multipart body.
        $body  = '--' . $boundary . "\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $html_content . "\r\n";
        $body .= '--' . $boundary . '--';

        $url = self::DRIVE_UPLOAD_URL . '/' . $doc_id . '?uploadType=multipart';

        $response = wp_remote_request( $url, array(
            'method'  => 'PATCH',
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'multipart/related; boundary=' . $boundary,
            ),
            'body'    => $body,
        ) );

        return $this->parse_response( $response );
    }

    /*--------------------------------------------------------------
     * Content Preparation
     *------------------------------------------------------------*/

    /**
     * Prepare post content as clean HTML for Google Docs conversion.
     *
     * Applies WordPress content filters (shortcodes, embeds, etc.)
     * and wraps in a minimal HTML document.
     *
     * @param  string $content Raw post content.
     * @return string Clean HTML document.
     */
    private function prepare_html( string $content ): string {

        // Apply WordPress content filters for proper rendering.
        $content = apply_filters( 'the_content', $content );

        // Remove any script/style tags for safety.
        $content = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $content );
        $content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $content );

        // Wrap in minimal HTML.
        $html  = '<!DOCTYPE html>' . "\n";
        $html .= '<html><head><meta charset="UTF-8"></head>' . "\n";
        $html .= '<body>' . "\n";
        $html .= $content . "\n";
        $html .= '</body></html>';

        return $html;
    }

    /*--------------------------------------------------------------
     * HTTP / Auth helpers
     *------------------------------------------------------------*/

    /**
     * Parse a Drive API response.
     *
     * @param  array|WP_Error $response wp_remote_* response.
     * @return array|WP_Error Decoded JSON or error.
     */
    private function parse_response( $response ) {

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $api_msg = isset( $json['error']['message'] ) ? $json['error']['message'] : '';

            if ( 403 === $code ) {
                $message = 'Drive permission denied. Share the Google Drive folder with the service account email as Editor. (API: ' . $api_msg . ')';
            } elseif ( 404 === $code ) {
                $message = 'Drive folder or document not found. Check your Folder ID in settings. (API: ' . $api_msg . ')';
            } elseif ( 401 === $code ) {
                $message = 'Drive authentication failed. Make sure the Google Drive API is enabled in your Google Cloud project. (API: ' . $api_msg . ')';
            } else {
                $message = $api_msg ? $api_msg : 'Unknown Drive API error (HTTP ' . $code . ').';
            }

            return new WP_Error( 'cts_drive_api_error', $message, array( 'status' => $code ) );
        }

        return $json ? $json : array();
    }

    /**
     * Obtain an OAuth2 access token via JWT grant.
     *
     * Uses Google Drive scope. Token is cached separately from the Sheets token.
     *
     * @return string|WP_Error Access token string or error.
     */
    private function get_access_token() {

        if ( $this->access_token ) {
            return $this->access_token;
        }

        // Separate transient key from Sheets tokens (different scope).
        $transient_key = 'cts_drive_token_' . substr( md5( $this->folder_id ), 0, 12 );

        $cached = get_transient( $transient_key );
        if ( $cached ) {
            $this->access_token = $cached;
            return $cached;
        }

        $jwt = $this->create_jwt();

        if ( is_wp_error( $jwt ) ) {
            return $jwt;
        }

        $response = wp_remote_post( self::TOKEN_URI, array(
            'timeout' => 15,
            'body'    => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            $msg = isset( $body['error_description'] ) ? $body['error_description'] : 'Drive token exchange failed';
            return new WP_Error( 'cts_drive_token_error', $msg );
        }

        $expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] - 60 : 3540;

        set_transient( $transient_key, $body['access_token'], $expires_in );

        $this->access_token = $body['access_token'];

        return $this->access_token;
    }

    /**
     * Create a signed JWT for the Google OAuth2 token endpoint.
     *
     * Requests Google Drive scope for file creation/editing.
     *
     * @return string|WP_Error Encoded JWT string or error.
     */
    private function create_jwt() {

        if ( empty( $this->credentials['client_email'] ) || empty( $this->credentials['private_key'] ) ) {
            return new WP_Error( 'cts_credentials_error', 'Service account credentials are incomplete.' );
        }

        $header = array(
            'alg' => 'RS256',
            'typ' => 'JWT',
        );

        $now = time();

        $claim = array(
            'iss'   => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive',
            'aud'   => self::TOKEN_URI,
            'iat'   => $now,
            'exp'   => $now + 3600,
        );

        $segments = array(
            $this->base64url_encode( wp_json_encode( $header ) ),
            $this->base64url_encode( wp_json_encode( $claim ) ),
        );

        $signing_input = implode( '.', $segments );

        $private_key = openssl_pkey_get_private( $this->credentials['private_key'] );

        if ( ! $private_key ) {
            return new WP_Error( 'cts_key_error', 'Unable to parse private key from credentials.' );
        }

        $signature = '';
        $success   = openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );

        if ( ! $success ) {
            return new WP_Error( 'cts_sign_error', 'JWT signing failed.' );
        }

        $segments[] = $this->base64url_encode( $signature );

        return implode( '.', $segments );
    }

    /**
     * Base64-url-encode a string.
     *
     * @param  string $data Raw data.
     * @return string URL-safe Base64.
     */
    private function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
}
