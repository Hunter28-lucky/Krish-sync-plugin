<?php
/**
 * Google Docs/Drive Service for Content Tracker Sync.
 *
 * Creates and updates Google Docs from WordPress post content.
 * Uses Google Docs API v1 for document creation/editing and
 * Google Drive API v3 for file management (move to folder).
 *
 * Two-step approach to avoid service-account storage-quota issues:
 *   1. Create the doc via Docs API (or copy a blank template).
 *   2. Move it into the target Drive folder via Drive API.
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
     * Google Docs API v1 base URL.
     *
     * @var string
     */
    const DOCS_API_BASE = 'https://docs.googleapis.com/v1';

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
     * @param  int    $post_id   WordPress Post ID.
     * @param  string $title     Post title.
     * @param  string $content   Post content (HTML).
     * @param  string $doc_id    Existing Google Doc ID (empty = create new).
     * @return array|WP_Error    Array with 'doc_id' and 'doc_url', or WP_Error.
     */
    public function sync_post_to_doc( int $post_id, string $title, string $content, string $doc_id = '' ) {

        $doc_title = $post_id . $title;

        // Strip HTML to plain text for Google Docs API insertText.
        $plain_text = $this->html_to_plain_text( $content );

        if ( ! empty( $doc_id ) ) {
            $result = $this->update_doc( $doc_id, $doc_title, $plain_text );
        } else {
            $result = $this->create_doc( $doc_title, $plain_text );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $file_id = $result['doc_id'];

        return array(
            'doc_id'  => $file_id,
            'doc_url' => 'https://docs.google.com/document/d/' . $file_id . '/edit',
        );
    }

    /*--------------------------------------------------------------
     * Create / Update
     *------------------------------------------------------------*/

    /**
     * Create a new Google Doc and move it to the target folder.
     *
     * Step 1: POST to Docs API → creates an empty Google Doc (no quota issue).
     * Step 2: PATCH via Drive API → move the doc into the shared folder.
     * Step 3: batchUpdate via Docs API → insert the plain-text content.
     *
     * @param  string $title      Doc title.
     * @param  string $plain_text Plain-text content.
     * @return array|WP_Error     Array with 'doc_id' key, or error.
     */
    private function create_doc( string $title, string $plain_text ) {

        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        // --- Step 1: Create an empty Google Doc via Docs API ---
        $create_url = self::DOCS_API_BASE . '/documents';

        $create_response = wp_remote_post( $create_url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'title' => $title ) ),
        ) );

        $create_result = $this->parse_response( $create_response );
        if ( is_wp_error( $create_result ) ) {
            return $create_result;
        }

        $new_doc_id = isset( $create_result['documentId'] ) ? $create_result['documentId'] : '';
        if ( empty( $new_doc_id ) ) {
            return new WP_Error( 'cts_docs_error', 'Google Docs API did not return a document ID.' );
        }

        // --- Step 2: Move doc into the shared folder via Drive API ---
        $move_url = self::DRIVE_API_BASE . '/files/' . $new_doc_id
                  . '?addParents=' . urlencode( $this->folder_id )
                  . '&removeParents=root'
                  . '&supportsAllDrives=true';

        $move_response = wp_remote_request( $move_url, array(
            'method'  => 'PATCH',
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => '{}',
        ) );

        $move_result = $this->parse_response( $move_response );
        if ( is_wp_error( $move_result ) ) {
            // Doc was created but couldn't be moved — still usable.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[CTS] Could not move doc to folder: ' . $move_result->get_error_message() );
            }
        }

        // --- Step 3: Insert content via Docs batchUpdate ---
        if ( ! empty( $plain_text ) ) {
            $insert_error = $this->insert_text( $new_doc_id, $plain_text, $token );
            if ( is_wp_error( $insert_error ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[CTS] Could not insert content: ' . $insert_error->get_error_message() );
            }
        }

        return array( 'doc_id' => $new_doc_id );
    }

    /**
     * Update an existing Google Doc: clear content + re-insert + rename.
     *
     * @param  string $doc_id     Existing doc file ID.
     * @param  string $title      Updated title.
     * @param  string $plain_text Updated plain-text content.
     * @return array|WP_Error     Array with 'doc_id' key, or error.
     */
    private function update_doc( string $doc_id, string $title, string $plain_text ) {

        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        // --- Rename via Drive API ---
        $rename_url = self::DRIVE_API_BASE . '/files/' . $doc_id . '?supportsAllDrives=true';

        $rename_response = wp_remote_request( $rename_url, array(
            'method'  => 'PATCH',
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array( 'name' => $title ) ),
        ) );

        $rename_result = $this->parse_response( $rename_response );
        if ( is_wp_error( $rename_result ) ) {
            return $rename_result;
        }

        // --- Get current doc length so we can clear it ---
        $doc_url = self::DOCS_API_BASE . '/documents/' . $doc_id;

        $doc_response = wp_remote_get( $doc_url, array(
            'timeout' => 15,
            'headers' => array( 'Authorization' => 'Bearer ' . $token ),
        ) );

        $doc_data = $this->parse_response( $doc_response );
        if ( is_wp_error( $doc_data ) ) {
            return $doc_data;
        }

        // Determine the end index of the body content (minus 1 for trailing newline).
        $end_index = 1;
        if ( isset( $doc_data['body']['content'] ) && is_array( $doc_data['body']['content'] ) ) {
            $last_element = end( $doc_data['body']['content'] );
            if ( isset( $last_element['endIndex'] ) ) {
                $end_index = (int) $last_element['endIndex'] - 1;
            }
        }

        // --- Clear existing content (if any) ---
        if ( $end_index > 1 ) {
            $clear_url = self::DOCS_API_BASE . '/documents/' . $doc_id . ':batchUpdate';

            wp_remote_post( $clear_url, array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( array(
                    'requests' => array(
                        array(
                            'deleteContentRange' => array(
                                'range' => array(
                                    'startIndex' => 1,
                                    'endIndex'   => $end_index,
                                ),
                            ),
                        ),
                    ),
                ) ),
            ) );
        }

        // --- Insert new content ---
        if ( ! empty( $plain_text ) ) {
            $insert_error = $this->insert_text( $doc_id, $plain_text, $token );
            if ( is_wp_error( $insert_error ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[CTS] Could not re-insert content: ' . $insert_error->get_error_message() );
            }
        }

        return array( 'doc_id' => $doc_id );
    }

    /*--------------------------------------------------------------
     * Content Helpers
     *------------------------------------------------------------*/

    /**
     * Insert plain text into a Google Doc at index 1.
     *
     * @param  string $doc_id     Document ID.
     * @param  string $text       Plain text to insert.
     * @param  string $token      Access token.
     * @return true|WP_Error      True on success or WP_Error.
     */
    private function insert_text( string $doc_id, string $text, string $token ) {

        $url = self::DOCS_API_BASE . '/documents/' . $doc_id . ':batchUpdate';

        $response = wp_remote_post( $url, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'requests' => array(
                    array(
                        'insertText' => array(
                            'location' => array( 'index' => 1 ),
                            'text'     => $text,
                        ),
                    ),
                ),
            ) ),
        ) );

        $result = $this->parse_response( $response );

        return is_wp_error( $result ) ? $result : true;
    }

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

    /*--------------------------------------------------------------
     * HTTP / Auth helpers
     *------------------------------------------------------------*/

    /**
     * Parse an API response.
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
            $api_msg = '';

            // Drive-style error.
            if ( isset( $json['error']['message'] ) ) {
                $api_msg = $json['error']['message'];
            }
            // Docs-style error.
            if ( empty( $api_msg ) && isset( $json['error']['status'] ) ) {
                $api_msg = $json['error']['status'];
            }

            if ( 403 === $code ) {
                $message = 'Permission denied (HTTP 403). ' . $api_msg;
            } elseif ( 404 === $code ) {
                $message = 'Not found (HTTP 404). Check your Folder ID. ' . $api_msg;
            } elseif ( 401 === $code ) {
                $message = 'Auth failed (HTTP 401). Enable the Google Docs API in Cloud Console. ' . $api_msg;
            } else {
                $message = $api_msg ? $api_msg : 'API error (HTTP ' . $code . ').';
            }

            return new WP_Error( 'cts_api_error', $message, array( 'status' => $code ) );
        }

        return $json ? $json : array();
    }

    /**
     * Obtain an OAuth2 access token via JWT grant.
     *
     * Uses both Drive and Docs scopes.
     *
     * @return string|WP_Error Access token or error.
     */
    private function get_access_token() {

        if ( $this->access_token ) {
            return $this->access_token;
        }

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
            $msg = isset( $body['error_description'] ) ? $body['error_description'] : 'Token exchange failed';
            return new WP_Error( 'cts_drive_token_error', $msg );
        }

        $expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] - 60 : 3540;

        set_transient( $transient_key, $body['access_token'], $expires_in );

        $this->access_token = $body['access_token'];
        return $this->access_token;
    }

    /**
     * Create a signed JWT requesting Drive + Docs scopes.
     *
     * @return string|WP_Error Encoded JWT or error.
     */
    private function create_jwt() {

        if ( empty( $this->credentials['client_email'] ) || empty( $this->credentials['private_key'] ) ) {
            return new WP_Error( 'cts_credentials_error', 'Service account credentials are incomplete.' );
        }

        $header = array( 'alg' => 'RS256', 'typ' => 'JWT' );

        $now   = time();
        $claim = array(
            'iss'   => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/documents',
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
            return new WP_Error( 'cts_key_error', 'Unable to parse private key.' );
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
